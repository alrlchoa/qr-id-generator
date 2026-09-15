<?php

namespace App\Services;

use App\Exceptions\DeletionBlockedException;
use App\Exceptions\PrimaryOwnerInvariantException;
use App\Models\IdCard;
use App\Models\Person;
use App\Models\PersonUnitRelationship;
use App\Models\User;

/**
 * Person deletion, guarded and never cascading (CLAUDE.md rule 9). Two
 * distinct refusals, checked in order: being a primary owner is blocked
 * with a specific message naming the units and pointing at the transfer
 * screen (architecture §13) — the generic "end the relationships first"
 * advice is wrong there, since it describes a path that orphans the unit.
 * Only once no unit names them as primary owner does the ordinary
 * active-relationship/active-card guard apply.
 */
class PersonDeletionManager
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly SecurityEventLogger $securityEvents,
    ) {}

    public function delete(User $actor, Person $person): void
    {
        $primaryOwnerUnits = PersonUnitRelationship::where('person_id', $person->id)
            ->where('is_primary_owner', true)
            ->whereNull('ended_at')
            ->whereHas('unit', fn ($q) => $q->whereNull('deleted_at'))
            ->with('unit')
            ->get()
            ->pluck('unit');

        if ($primaryOwnerUnits->isNotEmpty()) {
            $unitCodes = $primaryOwnerUnits->map(fn ($unit) => $unit->unitCode())->all();

            throw new PrimaryOwnerInvariantException(
                'Transfer the primary-owner role before deleting this person. They are the primary owner of: '.implode(', ', $unitCodes).'.'
            );
        }

        $activeRelationships = PersonUnitRelationship::where('person_id', $person->id)->whereNull('ended_at')->get();
        $activeCards = IdCard::where('person_id', $person->id)->where('status', 'active')->get();

        if ($activeRelationships->isNotEmpty() || $activeCards->isNotEmpty()) {
            $detail = [
                'person_id' => $person->id,
                'active_relationships' => $activeRelationships->pluck('id')->all(),
                'active_cards' => $activeCards->pluck('id')->all(),
            ];

            $this->securityEvents->log('deletion_blocked', $actor, $detail);

            throw new DeletionBlockedException(
                "{$person->displayName()} still has active relationships or cards. End them, then delete.",
                $detail,
            );
        }

        $person->delete();

        $this->auditLogger->log(actor: $actor, action: 'person_deleted', subject: $person, newValue: ['display_name' => $person->displayName()]);
    }
}
