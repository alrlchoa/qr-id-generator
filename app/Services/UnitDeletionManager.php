<?php

namespace App\Services;

use App\Exceptions\DeletionBlockedException;
use App\Models\IdCard;
use App\Models\PersonUnitRelationship;
use App\Models\SecurityEvent;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Unit deletion and restore, with the one carve-out CLAUDE.md rule 30
 * describes: the primary-owner relationship is the single dependent an
 * admin is structurally forbidden from closing themselves (§5.4 refuses to
 * leave a live unit without one), so the deletion transaction closes it as
 * its own final act — not a cascade, the one dependent the admin never had
 * a path to close beforehand (architecture §13).
 */
class UnitDeletionManager
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function delete(User $actor, Unit $unit): void
    {
        // The blocked branch below throws — inside a DB::transaction() that
        // would roll back everything written before the throw, including
        // the security_events row that's the whole point of logging the
        // refusal. So the guard runs (and locks the unit) inside the
        // transaction, but only returns what to do; the transaction always
        // commits cleanly, and the exception + event are raised afterward,
        // outside it, where they can't be undone by their own rollback.
        $blockedDetail = DB::transaction(function () use ($actor, $unit) {
            $lockedUnit = Unit::where('id', $unit->id)->lockForUpdate()->first();

            $primaryOwnerRelationship = $lockedUnit->primaryOwnerRelationship();

            $otherLiveRelationships = $lockedUnit->activeRelationships()
                ->when($primaryOwnerRelationship, fn ($q) => $q->where('id', '!=', $primaryOwnerRelationship->id))
                ->get();

            $activeCards = IdCard::where('unit_id', $lockedUnit->id)->where('status', 'active')->get();

            if ($otherLiveRelationships->isNotEmpty() || $activeCards->isNotEmpty()) {
                return [
                    'unit_id' => $lockedUnit->id,
                    'unit_code' => $lockedUnit->unitCode(),
                    'active_relationships' => $otherLiveRelationships->pluck('id')->all(),
                    'active_cards' => $activeCards->pluck('id')->all(),
                ];
            }

            if ($primaryOwnerRelationship !== null) {
                $primaryOwnerRelationship->forceFill(['is_primary_owner' => false, 'ended_at' => now()])->save();

                $this->auditLogger->log(actor: $actor, action: 'relationship_closed', subject: $primaryOwnerRelationship, newValue: [
                    'ended_at' => $primaryOwnerRelationship->ended_at->toISOString(), 'reason' => 'unit_deleted',
                ]);
            }

            $lockedUnit->delete();

            $this->auditLogger->log(actor: $actor, action: 'unit_deleted', subject: $lockedUnit, newValue: ['unit_code' => $lockedUnit->unitCode()]);

            return null;
        });

        if ($blockedDetail !== null) {
            SecurityEvent::create([
                'occurred_at' => now(),
                'user_id' => $actor->id,
                'event_type' => 'deletion_blocked',
                'detail' => $blockedDetail,
                'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            ]);

            throw new DeletionBlockedException(
                "Unit {$blockedDetail['unit_code']} still has active relationships or cards. End them, then delete.",
                $blockedDetail,
            );
        }
    }

    /**
     * A deleted unit has no active relationships by construction — its own
     * deletion closed the last one — so restoring the row alone would
     * produce a live unit nobody is accountable for. The restore screen
     * asks the same question unit creation asks (architecture §13).
     *
     * @param  array<string, mixed>  $primaryOwner  ['person_id' => int] or ['new' => array]
     */
    public function restore(User $actor, Unit $unit, array $primaryOwner, string $startDate, UnitLifecycleManager $units): void
    {
        DB::transaction(function () use ($actor, $unit, $primaryOwner, $startDate, $units) {
            $unit->restore();

            $this->auditLogger->log(actor: $actor, action: 'unit_restored', subject: $unit, newValue: ['unit_code' => $unit->unitCode()]);

            // Reuses createUnit()'s party-resolution + contactable-tier
            // guard, but the unit already exists — open the relationship
            // directly rather than calling createUnit() again.
            $owner = $units->resolvePrimaryOwnerParty($primaryOwner);

            $relationship = PersonUnitRelationship::create([
                'person_id' => $owner->id,
                'unit_id' => $unit->id,
                'type' => 'owner',
                'is_primary_owner' => true,
                'start_date' => $startDate,
            ]);

            $this->auditLogger->log(actor: $actor, action: 'relationship_opened', subject: $relationship, newValue: [
                'person_id' => $owner->id, 'unit_id' => $unit->id, 'type' => 'owner', 'is_primary_owner' => true,
            ]);
        });
    }
}
