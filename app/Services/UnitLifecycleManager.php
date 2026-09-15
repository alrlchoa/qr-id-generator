<?php

namespace App\Services;

use App\Exceptions\PrimaryOwnerInvariantException;
use App\Exceptions\UnitAtCapacityException;
use App\Models\IdCard;
use App\Models\Person;
use App\Models\PersonUnitRelationship;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Unit creation and every operation that moves the primary-owner role
 * (architecture §5.4, CLAUDE.md rules 30-32). A unit and its primary owner
 * are created together, in one transaction — there is no "add the owner
 * later" path. Promotion and ownership transfer are two distinct
 * operations, never conflated: promotion touches no card, transfer cascades
 * to the outgoing owner's relationship (and, from Phase 9, their card).
 */
class UnitLifecycleManager
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * `$actor` is nullable to allow console-originated seeding (rule 44) —
     * every real UI call site passes an authenticated user and leaves
     * `$actingAs` null, identical to today's behavior.
     *
     * @param  array<string, mixed>  $unitAttributes  building_code, floor_code, unit_number
     * @param  array<string, mixed>  $primaryOwner  either ['person_id' => int] or
     *                                              ['new' => array<string, mixed>] — the new
     *                                              person's attributes, contactable tier required
     * @return array{unit: Unit, relationship: PersonUnitRelationship}
     */
    public function createUnit(?User $actor, array $unitAttributes, array $primaryOwner, string $startDate, ?string $actingAs = null): array
    {
        return DB::transaction(function () use ($actor, $unitAttributes, $primaryOwner, $startDate, $actingAs) {
            $unit = Unit::create($unitAttributes);

            $owner = $this->resolvePrimaryOwnerParty($primaryOwner);

            $this->auditLogger->log(actor: $actor, action: 'unit_created', subject: $unit, newValue: ['unit_code' => $unit->unitCode()], actingAs: $actingAs);

            $relationship = $this->openPrimaryOwnerRelationship($actor, $unit, $owner, $startDate, $actingAs);

            return ['unit' => $unit, 'relationship' => $relationship];
        });
    }

    /**
     * Promotion: the role moves between two parties who both already hold
     * active `owner` relationships on the unit. No relationship opens or
     * closes, and — this is the entire reason promotion and transfer are
     * not one operation — no card is affected.
     */
    public function promotePrimaryOwner(User $actor, Unit $unit, PersonUnitRelationship $incoming): void
    {
        DB::transaction(function () use ($actor, $unit, $incoming) {
            $lockedUnit = Unit::lockById($unit->id);

            $outgoing = $lockedUnit->primaryOwnerRelationship();

            if ($outgoing === null) {
                throw new PrimaryOwnerInvariantException("Unit {$lockedUnit->unitCode()} has no active primary owner to promote from.");
            }

            if ($incoming->unit_id !== $lockedUnit->id || $incoming->ended_at !== null || ! $incoming->isOwner()) {
                throw new PrimaryOwnerInvariantException('The incoming party must hold an active owner relationship on this unit.');
            }

            if ($incoming->is($outgoing)) {
                throw new PrimaryOwnerInvariantException('That party is already the primary owner.');
            }

            // The outgoing owner's card, if any, rejoins the six-slot count
            // the moment they stop being primary owner — a promotion into a
            // unit already at 6/6 would push it to seven.
            $outgoingHasActiveCard = IdCard::where('unit_id', $lockedUnit->id)
                ->where('person_id', $outgoing->person_id)
                ->where('status', 'active')
                ->whereIn('type', ['owner', 'tenant'])
                ->exists();

            if ($outgoingHasActiveCard && $lockedUnit->nonPrimaryOwnerActiveCardCount() >= Unit::OCCUPANT_SLOTS) {
                throw new UnitAtCapacityException($lockedUnit, 'Promoting this owner would push the unit past its six-occupant cap.');
            }

            // Retire-then-set (CLAUDE.md rule 32) — clearing the outgoing
            // flag first is not a style choice: the partial unique index is
            // checked at statement end, unconditionally, and cannot defer.
            $outgoing->forceFill(['is_primary_owner' => false])->save();
            $incoming->forceFill(['is_primary_owner' => true])->save();

            $this->auditLogger->log(
                actor: $actor,
                action: 'primary_owner_transferred',
                subject: $lockedUnit,
                previousValue: ['operation' => 'promotion', 'outgoing_person_id' => $outgoing->person_id],
                newValue: ['incoming_person_id' => $incoming->person_id],
            );
        });
    }

    /**
     * Ownership transfer: the outgoing party ceases to own the unit
     * entirely. Opens the incoming owner's relationship, sets the role, and
     * closes the outgoing owner's relationship — which is what cascades to
     * their cards from Phase 9 onward. Not built here: that cascade itself.
     * This phase closes the relationship and leaves a named seam.
     *
     * @param  array<string, mixed>  $incomingParty  ['person_id' => int] or ['new' => array]
     */
    public function transferPrimaryOwnership(User $actor, Unit $unit, PersonUnitRelationship $outgoing, array $incomingParty, string $startDate): PersonUnitRelationship
    {
        return DB::transaction(function () use ($actor, $unit, $outgoing, $incomingParty, $startDate) {
            $lockedUnit = Unit::lockById($unit->id);

            if ($outgoing->unit_id !== $lockedUnit->id || $outgoing->ended_at !== null || ! $outgoing->is_primary_owner) {
                throw new PrimaryOwnerInvariantException('That relationship is not the unit\'s current active primary owner.');
            }

            $incoming = $this->resolvePrimaryOwnerParty($incomingParty);

            $existingTenancy = $lockedUnit->activeRelationships()
                ->where('person_id', $incoming->id)
                ->where('type', 'tenant')
                ->first();

            // Capacity re-attribution (§5.4): the outgoing owner's card, if
            // any, joins the six the instant they stop being primary owner.
            $outgoingHasActiveCard = IdCard::where('unit_id', $lockedUnit->id)
                ->where('person_id', $outgoing->person_id)
                ->where('status', 'active')
                ->whereIn('type', ['owner', 'tenant'])
                ->exists();

            $projectedNonPrimaryCount = $lockedUnit->nonPrimaryOwnerActiveCardCount()
                + ($outgoingHasActiveCard ? 1 : 0);

            if ($projectedNonPrimaryCount > Unit::OCCUPANT_SLOTS) {
                throw new UnitAtCapacityException($lockedUnit, 'This transfer would push the unit past its six-occupant cap.');
            }

            // Retire-then-set: close and un-primary the outgoing relationship
            // before the incoming one is ever set as primary — never the
            // reverse (CLAUDE.md rule 32).
            $outgoing->forceFill(['is_primary_owner' => false, 'ended_at' => now()])->save();

            $incomingRelationship = PersonUnitRelationship::create([
                'person_id' => $incoming->id,
                'unit_id' => $lockedUnit->id,
                'type' => 'owner',
                'is_primary_owner' => true,
                'start_date' => $startDate,
            ]);

            $this->auditLogger->log(
                actor: $actor,
                action: 'primary_owner_transferred',
                subject: $lockedUnit,
                previousValue: ['operation' => 'transfer', 'outgoing_person_id' => $outgoing->person_id],
                newValue: [
                    'incoming_person_id' => $incoming->id,
                    // Named seam (Phase 9 builds the reissue itself): the
                    // incoming owner's existing tenant card is now the
                    // wrong type per §5.1's owner-outranks-tenant rule.
                    'requires_type_change_reissue' => $existingTenancy !== null,
                ],
            );

            $this->auditLogger->log(actor: $actor, action: 'relationship_closed', subject: $outgoing, newValue: ['ended_at' => $outgoing->ended_at?->toISOString()]);
            $this->auditLogger->log(actor: $actor, action: 'relationship_opened', subject: $incomingRelationship, newValue: [
                'person_id' => $incoming->id, 'unit_id' => $lockedUnit->id, 'type' => 'owner', 'is_primary_owner' => true,
            ]);

            return $incomingRelationship;
        });
    }

    /**
     * Recovery-only: designates a primary owner for a live unit that
     * currently has none. Every sanctioned path keeps a live unit at
     * exactly one active primary owner (rule 30) — this method exists
     * only because a unit can still reach zero through an unsanctioned
     * write (a hand-edited row, the exact corruption architecture §15's
     * Query D canary exists to catch), and until now that canary's
     * "Resolve" link led nowhere: `promotePrimaryOwner()` and
     * `transferPrimaryOwnership()` both require an existing outgoing
     * owner and throw without one.
     *
     * Refuses outright if the unit already has an active primary owner —
     * that case is promotion's or transfer's job, not this one's;
     * conflating them would let a mistaken call here silently orphan an
     * existing primary owner instead of transferring deliberately.
     *
     * @param  array<string, mixed>  $party  ['person_id' => int] or ['new' => array<string, mixed>]
     */
    public function designatePrimaryOwner(User $actor, Unit $unit, array $party, string $startDate): PersonUnitRelationship
    {
        return DB::transaction(function () use ($actor, $unit, $party, $startDate) {
            $lockedUnit = Unit::lockById($unit->id);

            if ($lockedUnit->primaryOwnerRelationship() !== null) {
                throw new PrimaryOwnerInvariantException(
                    "Unit {$lockedUnit->unitCode()} already has an active primary owner — use promotion or transfer instead."
                );
            }

            $owner = $this->resolvePrimaryOwnerParty($party);

            $relationship = PersonUnitRelationship::create([
                'person_id' => $owner->id,
                'unit_id' => $lockedUnit->id,
                'type' => 'owner',
                'is_primary_owner' => true,
                'start_date' => $startDate,
            ]);

            // Rule 30's "at least one" check runs on the way out, not the
            // way in — confirm the row just created actually satisfies it
            // rather than trusting the insert blindly. This is why the
            // create isn't routed through openPrimaryOwnerRelationship():
            // the check has to sit between the insert and the audit rows,
            // so a failed designation logs nothing at all (rule 45).
            if ($lockedUnit->refresh()->primaryOwnerRelationship() === null) {
                throw new PrimaryOwnerInvariantException("Designating a primary owner for {$lockedUnit->unitCode()} failed unexpectedly.");
            }

            $this->auditLogger->log(
                actor: $actor,
                action: 'primary_owner_designated',
                subject: $lockedUnit,
                newValue: ['person_id' => $owner->id],
            );
            $this->auditLogger->log(actor: $actor, action: 'relationship_opened', subject: $relationship, newValue: [
                'person_id' => $owner->id, 'unit_id' => $lockedUnit->id, 'type' => 'owner', 'is_primary_owner' => true,
            ]);

            return $relationship;
        });
    }

    /**
     * Opens a primary-owner relationship on a unit and audits it — shared by
     * `createUnit()` and `UnitDeletionManager::restore()`, which had this
     * block written out identically (restore's own comment already noted it
     * was duplicating createUnit rather than calling it).
     *
     * **Two other methods create the same row and deliberately do not call
     * this**, which is worth knowing before "finishing the job":
     * - `transferPrimaryOwnership()` writes `primary_owner_transferred` and
     *   `relationship_closed` *between* its insert and its
     *   `relationship_opened`. Routing it here would reorder three rows in
     *   an append-only trail.
     * - `designatePrimaryOwner()` re-checks rule 30's "at least one" between
     *   its insert and its audit rows, so a failed designation logs nothing
     *   (rule 45). Fusing insert and log removes the seam that check needs.
     *
     * Both are ordering constraints, not oversights — the duplication that
     * remains is apparent, not real.
     */
    public function openPrimaryOwnerRelationship(?User $actor, Unit $unit, Person $owner, string $startDate, ?string $actingAs = null): PersonUnitRelationship
    {
        $relationship = PersonUnitRelationship::create([
            'person_id' => $owner->id,
            'unit_id' => $unit->id,
            'type' => 'owner',
            'is_primary_owner' => true,
            'start_date' => $startDate,
        ]);

        $this->auditLogger->log(actor: $actor, action: 'relationship_opened', subject: $relationship, newValue: [
            'person_id' => $owner->id, 'unit_id' => $unit->id, 'type' => 'owner', 'is_primary_owner' => true,
        ], actingAs: $actingAs);

        return $relationship;
    }

    /**
     * Resolves and contactable-tier-validates a would-be primary owner —
     * shared by unit creation and by `UnitDeletionManager::restore()`,
     * which asks the same question a fresh unit does (architecture §13).
     *
     * @param  array<string, mixed>  $party  ['person_id' => int] or ['new' => array<string, mixed>]
     */
    public function resolvePrimaryOwnerParty(array $party): Person
    {
        if (isset($party['person_id'])) {
            $person = Person::findOrFail($party['person_id']);
        } elseif (isset($party['new'])) {
            $person = app(PersonIdNumberGenerator::class)->createWithUniqueId($party['new']);
        } else {
            throw new PrimaryOwnerInvariantException('A primary owner must be selected or created.');
        }

        if (! $person->isContactable()) {
            throw new PrimaryOwnerInvariantException(
                "The primary owner must be contactable — a mobile number and an email are required. {$person->displayName()} is missing one or both."
            );
        }

        return $person;
    }
}
