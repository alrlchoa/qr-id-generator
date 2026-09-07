<?php

namespace App\Services;

use App\Exceptions\PrimaryOwnerInvariantException;
use App\Models\Person;
use App\Models\PersonUnitRelationship;
use App\Models\Unit;
use App\Models\User;
use InvalidArgumentException;

/**
 * Opening and closing ordinary (non-primary-owner) relationships. Moving
 * the primary-owner role is a different operation entirely — promotion and
 * transfer live in `UnitLifecycleManager` — because a unit can never be
 * left, even momentarily, without exactly one (architecture §5.4).
 */
class RelationshipManager
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function openRelationship(User $actor, Person $person, Unit $unit, string $type, string $startDate, ?string $contractEndDate = null): PersonUnitRelationship
    {
        if ($type === 'tenant' && $person->isCompany()) {
            throw new InvalidArgumentException('A company can never hold a tenancy — a corporate lease is recorded against the company as owner, or against the occupying individuals as tenants.');
        }

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
        ]);

        return $relationship;
    }

    /**
     * Sets `ended_at`. **The card cascade arrives in Phase 9** — this is a
     * clearly-named seam, not a silent gap: closing a relationship today
     * does not yet expire the matching card.
     */
    public function closeRelationship(User $actor, PersonUnitRelationship $relationship): void
    {
        if ($relationship->is_primary_owner) {
            throw new PrimaryOwnerInvariantException(
                'This is the unit\'s primary-owner relationship — it can\'t be closed directly. Transfer the primary-owner role to someone else first.'
            );
        }

        if ($relationship->ended_at !== null) {
            throw new InvalidArgumentException('This relationship is already closed.');
        }

        $relationship->forceFill(['ended_at' => now()])->save();

        $this->auditLogger->log(actor: $actor, action: 'relationship_closed', subject: $relationship, newValue: [
            'ended_at' => $relationship->ended_at->toISOString(),
        ]);
    }
}
