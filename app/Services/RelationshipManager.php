<?php

namespace App\Services;

use App\Exceptions\PrimaryOwnerInvariantException;
use App\Models\IdCard;
use App\Models\Person;
use App\Models\PersonUnitRelationship;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Opening and closing ordinary (non-primary-owner) relationships. Moving
 * the primary-owner role is a different operation entirely — promotion and
 * transfer live in `UnitLifecycleManager` — because a unit can never be
 * left, even momentarily, without exactly one (architecture §5.4).
 */
class RelationshipManager
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly IdCardLifecycleManager $cards,
    ) {}

    /**
     * A company is refused outright, regardless of `$type` — it may only
     * ever be a unit's *primary* owner (set directly by
     * `UnitLifecycleManager`), never an ordinary co-owner or tenant reached
     * through this method.
     *
     * For a natural person, at most one *active* relationship of each kind —
     * owner or tenant — on a given unit, never both kinds at once:
     *
     * - **Same kind already active** (another owner, or another tenant,
     *   relationship for this exact person on this exact unit): refused
     *   outright. Two active tenancies, or two active co-ownerships, for one
     *   person on one unit is never a real state — it's a duplicate.
     * - **Opposite kind already active**, with a new **owner** request: the
     *   tenancy is closed automatically (same `closeRelationship()` path a
     *   manual Close uses, cards and all) and the owner relationship opens
     *   in its place, atomically. A tenant who buys the unit is the
     *   ordinary case this serves.
     * - **Opposite kind already active**, with a new **tenant** request:
     *   refused outright. Owner-to-tenant is a demotion an admin should
     *   decide deliberately, not something that happens as a side effect of
     *   adding a lease.
     */
    public function openRelationship(?User $actor, Person $person, Unit $unit, string $type, string $startDate, ?string $contractEndDate = null, ?string $actingAs = null): PersonUnitRelationship
    {
        if ($person->isCompany()) {
            // openRelationship() only ever creates a non-primary relationship
            // (createRelationship() always sets is_primary_owner => false) —
            // a company may only ever be a unit's *primary* owner, set
            // directly by UnitLifecycleManager::createUnit() or
            // transferPrimaryOwnership(), never an ordinary co-owner or
            // tenant reached through this method.
            throw new InvalidArgumentException('A company can only be a unit\'s primary owner — never an ordinary co-owner or tenant. Record the relationship as the company\'s own primary ownership, or against the occupying individuals directly.');
        }

        $alreadySameType = PersonUnitRelationship::where('person_id', $person->id)
            ->where('unit_id', $unit->id)
            ->where('type', $type)
            ->whereNull('ended_at')
            ->exists();

        if ($alreadySameType) {
            throw new InvalidArgumentException("{$person->displayName()} already holds an active {$type} relationship on this unit.");
        }

        $oppositeType = $type === 'owner' ? 'tenant' : 'owner';

        $opposing = PersonUnitRelationship::where('person_id', $person->id)
            ->where('unit_id', $unit->id)
            ->where('type', $oppositeType)
            ->whereNull('ended_at')
            ->first();

        if ($opposing !== null && $type === 'tenant') {
            throw new InvalidArgumentException("{$person->displayName()} already holds an active owner relationship on this unit — end it before adding a tenant relationship.");
        }

        if ($opposing !== null) {
            // $type === 'owner' and an active tenant relationship exists:
            // close it first, then open the owner relationship, both in one
            // transaction so a failure on either side leaves neither applied.
            return DB::transaction(function () use ($actor, $person, $unit, $type, $startDate, $contractEndDate, $actingAs, $opposing) {
                $this->closeRelationship($actor, $opposing, $actingAs);

                return $this->createRelationship($actor, $person, $unit, $type, $startDate, $contractEndDate, $actingAs);
            });
        }

        return $this->createRelationship($actor, $person, $unit, $type, $startDate, $contractEndDate, $actingAs);
    }

    private function createRelationship(?User $actor, Person $person, Unit $unit, string $type, string $startDate, ?string $contractEndDate, ?string $actingAs): PersonUnitRelationship
    {
        $relationship = PersonUnitRelationship::create([
            'person_id' => $person->id,
            'unit_id' => $unit->id,
            'type' => $type,
            'is_primary_owner' => false,
            'start_date' => $startDate,
            'contract_end_date' => $contractEndDate,
        ]);

        $this->auditLogger->log(actor: $actor, action: 'relationship_opened', subject: $relationship, newValue: [
            'person_id' => $person->id, 'unit_id' => $unit->id, 'type' => $type,
        ], actingAs: $actingAs);

        return $relationship;
    }

    /**
     * Sets `ended_at` and, in the same transaction, expires this person's
     * matching active owner/tenant card(s) on this unit (§5.3) — the seam
     * Phase 8 left named ("the card cascade arrives in Phase 9") is closed
     * here. Employee cards are never touched: they name no unit and don't
     * derive from any relationship.
     */
    public function closeRelationship(?User $actor, PersonUnitRelationship $relationship, ?string $actingAs = null): void
    {
        if ($relationship->is_primary_owner) {
            throw new PrimaryOwnerInvariantException(
                'This is the unit\'s primary-owner relationship — it can\'t be closed directly. Transfer the primary-owner role to someone else first.'
            );
        }

        if ($relationship->ended_at !== null) {
            throw new InvalidArgumentException('This relationship is already closed.');
        }

        DB::transaction(function () use ($actor, $relationship, $actingAs) {
            $relationship->forceFill(['ended_at' => now()])->save();

            $this->auditLogger->log(actor: $actor, action: 'relationship_closed', subject: $relationship, newValue: [
                'ended_at' => $relationship->ended_at->toISOString(),
            ], actingAs: $actingAs);

            $affectedCards = IdCard::where('unit_id', $relationship->unit_id)
                ->where('person_id', $relationship->person_id)
                ->where('status', 'active')
                ->whereIn('type', ['owner', 'tenant'])
                ->get();

            foreach ($affectedCards as $card) {
                $this->cards->expireForClosure($actor, $card, $actingAs);
            }
        });
    }

    /**
     * The set of this person's active owner/tenant cards on this
     * relationship's unit — exactly what `closeRelationship()` is about to
     * expire. Exposed so a confirmation screen can name them *before* the
     * admin commits (§5.3: "the confirmation screen names them first").
     */
    public function cardsAffectedByClosing(PersonUnitRelationship $relationship): Collection
    {
        return IdCard::where('unit_id', $relationship->unit_id)
            ->where('person_id', $relationship->person_id)
            ->where('status', 'active')
            ->whereIn('type', ['owner', 'tenant'])
            ->get();
    }
}
