<?php

namespace App\Services;

use App\Exceptions\CardIssuanceRefusedException;
use App\Exceptions\UnitAtCapacityException;
use App\Models\IdCard;
use App\Models\Person;
use App\Models\PersonUnitRelationship;
use App\Models\Template;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Card issuance (architecture §5.1, §5.2; Phase 8 plan). Two independent
 * paths, on purpose: an owner/tenant card is resolved from the person's
 * relationships and counted against a unit's six-slot cap; an employee card
 * names no unit and never counts toward anything. Conflating them was the
 * flat-seven-count bug this phase's tests exist to catch — see
 * `Unit::nonPrimaryOwnerActiveCardCount()`.
 *
 * `template_id` (Phase 12) resolves to whatever template is currently
 * `Template::activeFor()` the card's type — and is simply `null` when none
 * is active yet. That's a normal state, not an error: `template_id` is
 * provenance only (rule 13), and issuance predates templates existing at
 * all (Phases 8/9 issued cards with none). Never gate issuance on a
 * template existing.
 */
class IssuanceManager
{
    public function __construct(
        private readonly ControlNumberGenerator $controlNumbers,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Resolves type and unit per §5.1, locks the winning unit, enforces the
     * six-slot cap (§5.2), and issues the card. `$unitOverride` lets an admin
     * choose any active relationship of the *already-resolved* winning type —
     * the override never crosses from owner to tenant or back.
     */
    public function issueOwnerOrTenantCard(?User $actor, Person $person, ?Unit $unitOverride = null, ?string $actingAs = null): IdCard
    {
        $this->refuseCompanyByKind($person);
        $this->refuseBelowCardableTier($person);

        $relationship = $this->resolveWinningRelationship($person, $unitOverride);

        if (IdCard::where('person_id', $person->id)->where('status', 'active')->whereIn('type', ['owner', 'tenant'])->exists()) {
            throw new CardIssuanceRefusedException("{$person->displayName()} already holds an active owner/tenant card. Replace it instead of issuing a second one.");
        }

        return DB::transaction(function () use ($actor, $person, $relationship, $actingAs) {
            $lockedUnit = Unit::where('id', $relationship->unit_id)->lockForUpdate()->first();

            // The primary owner is issued into their own reserved slot and is
            // never counted against the six (§5.2) — everyone else competes
            // for those six, checked only once the lock is held.
            if ($person->id !== $lockedUnit->primaryOwnerPersonId() && $lockedUnit->nonPrimaryOwnerActiveCardCount() >= 6) {
                throw new UnitAtCapacityException($lockedUnit);
            }

            $card = $this->controlNumbers->createWithUniqueControlNumber([
                'person_id' => $person->id,
                'unit_id' => $lockedUnit->id,
                'type' => $relationship->type,
                'status' => 'active',
                'template_id' => Template::activeFor($relationship->type)?->id,
                'issued_at' => now(),
            ]);

            $this->auditLogger->log(
                actor: $actor,
                action: 'id_issued',
                subject: $card,
                newValue: ['person_id' => $person->id, 'unit_id' => $lockedUnit->id, 'type' => $relationship->type],
                actingAs: $actingAs,
            );

            return $card;
        });
    }

    /**
     * Type is always `employee`, `unit_id` is always null, and the six-slot
     * cap never applies (§5.1) — this bypasses `issueOwnerOrTenantCard()`
     * entirely rather than sharing its capacity-checked path. Superadmin-only
     * is enforced by `IdCardPolicy::issueEmployee()` at the call site, not
     * re-checked here — the same division of responsibility every other
     * service in this codebase uses.
     */
    public function issueEmployeeCard(?User $actor, Person $person, ?string $position = null, ?string $department = null, ?string $actingAs = null): IdCard
    {
        $this->refuseCompanyByKind($person);
        $this->refuseBelowCardableTier($person);

        $card = $this->controlNumbers->createWithUniqueControlNumber([
            'person_id' => $person->id,
            'unit_id' => null,
            'type' => 'employee',
            'status' => 'active',
            'template_id' => Template::activeFor('employee')?->id,
            'position' => $position,
            'department' => $department,
            'issued_at' => now(),
        ]);

        $this->auditLogger->log(
            actor: $actor,
            action: 'id_issued',
            subject: $card,
            newValue: ['person_id' => $person->id, 'type' => 'employee'],
            actingAs: $actingAs,
        );

        return $card;
    }

    /**
     * Checked before any field check (Phase 8 plan) — the error names the
     * actual reason a company can never hold a card (rule 36), not a
     * misleading "photo is required."
     */
    private function refuseCompanyByKind(Person $person): void
    {
        if ($person->isCompany()) {
            throw new CardIssuanceRefusedException("{$person->displayName()} is a company and can never be issued an ID card.");
        }
    }

    private function refuseBelowCardableTier(Person $person): void
    {
        if ($person->isCardable()) {
            return;
        }

        $missing = array_values(array_filter([
            filled($person->mobile_number) ? null : 'mobile_number',
            filled($person->email) ? null : 'email',
            filled($person->photo_path) ? null : 'photo',
        ]));

        throw new CardIssuanceRefusedException(
            "{$person->displayName()} isn't at the cardable tier yet — missing: ".implode(', ', $missing).'.',
            missingFields: $missing,
        );
    }

    /**
     * §5.1: owner outranks tenant; within the winning type, earliest
     * `start_date`, ties by lowest `unit_id`. `$unitOverride`, when given,
     * must be an active relationship of that same winning type — the
     * override chooses among the winning type's relationships, it never
     * reaches into the losing type.
     */
    private function resolveWinningRelationship(Person $person, ?Unit $unitOverride): PersonUnitRelationship
    {
        $active = PersonUnitRelationship::where('person_id', $person->id)
            ->whereNull('ended_at')
            ->whereIn('type', ['owner', 'tenant'])
            ->orderBy('start_date')
            ->orderBy('unit_id')
            ->get();

        $winningType = $active->firstWhere('type', 'owner') !== null ? 'owner' : 'tenant';
        $candidates = $active->where('type', $winningType);

        if ($candidates->isEmpty()) {
            throw new InvalidArgumentException("{$person->displayName()} holds no active owner or tenant relationship to issue a card against.");
        }

        if ($unitOverride === null) {
            return $candidates->first();
        }

        $chosen = $candidates->firstWhere('unit_id', $unitOverride->id);

        if ($chosen === null) {
            throw new InvalidArgumentException(
                "That unit isn't one of {$person->displayName()}'s active {$winningType} relationships — the override can't cross from {$winningType} into the other type."
            );
        }

        return $chosen;
    }
}
