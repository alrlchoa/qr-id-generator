<?php

namespace App\Services;

use App\Models\Person;
use App\Models\PersonUnitRelationship;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Collection;

/**
 * Architecture §14. Four read-only queries, each producing a list for a
 * human — this class writes nothing, not even to `audit_logs` (reading a
 * list is not a business event, the same point the audit viewer already
 * makes about itself). Query A is rule 3's single exception: the only place
 * anywhere in this system that compares a date to today.
 */
class ReconciliationQueries
{
    /**
     * Query A — leases past their contract end date, still open. The lease
     * term has elapsed with no admin action: either it was renewed and the
     * record needs updating, or the tenancy ended and nobody closed it.
     */
    public function leasesPastTerm(): Collection
    {
        return PersonUnitRelationship::query()
            ->whereNotNull('contract_end_date')
            ->where('contract_end_date', '<', now()->toDateString())
            ->whereNull('ended_at')
            ->with(['person', 'unit'])
            ->orderBy('contract_end_date')
            ->get();
    }

    /**
     * Query B — persons who could be carded today and are not, per person
     * rather than per relationship. Deliberately narrower than "active
     * relationship, no card": scoped to natural persons at the cardable
     * tier (photo present, on top of the contactable fields it already
     * requires) with an active owner/tenant relationship and no active
     * owner/tenant card. Companies and below-cardable people are excluded
     * on purpose — both are permanent, unresolvable states that would
     * swamp this list and break the "empty is normal" contract the whole
     * dashboard depends on (§14).
     */
    public function cardableAndUncarded(): Collection
    {
        return Person::query()
            ->where('entity_type', 'natural')
            ->whereNotNull('photo_path')
            ->whereNotNull('mobile_number')
            ->whereNotNull('email')
            ->whereHas('relationships', function ($q) {
                $q->whereNull('ended_at')->whereIn('type', ['owner', 'tenant']);
            })
            ->whereDoesntHave('idCards', function ($q) {
                $q->where('status', 'active')->whereIn('type', ['owner', 'tenant']);
            })
            ->orderBy('last_name')
            ->get();
    }

    /**
     * Query C — units with all six occupant slots taken. Informational
     * only: a full unit isn't a problem, just worth surfacing before an
     * admin hits `UnitAtCapacityException` mid-transaction. Reuses
     * `Unit::nonPrimaryOwnerActiveCardCount()` rather than re-deriving the
     * six-slot definition here — a second implementation of §5.2 sitting
     * next to the first is exactly the kind of drift this codebase's own
     * traps warn about (Phase 8).
     */
    public function unitsAtCapacity(): Collection
    {
        return Unit::query()->get()
            ->filter(fn (Unit $unit) => $unit->nonPrimaryOwnerActiveCardCount() >= 6)
            ->values();
    }

    /**
     * Query D — units whose active primary-owner count is not exactly one.
     * An integrity canary, unlike A–C: §5.4's transaction plus the partial
     * unique index make both failure states unreachable through the
     * application, so this should always come back empty. The left join
     * (rather than a plain group-by on the relationship table) is what
     * catches a unit with *zero* primary owners, not only one with several
     * — a unit with none has no relationship row to group in the first
     * place. Same join shape the Units index already uses for its
     * `primary_owner` sort column.
     */
    public function primaryOwnerIntegrityIssues(): Collection
    {
        return Unit::query()
            ->select('units.*')
            ->leftJoin('person_unit_relationships as pur', function ($join) {
                $join->on('pur.unit_id', '=', 'units.id')
                    ->where('pur.is_primary_owner', true)
                    ->whereNull('pur.ended_at');
            })
            ->groupBy('units.id')
            ->havingRaw('count(pur.id) != 1')
            ->get();
    }

    /**
     * Every active primary-owner relationship on a unit, named with its
     * `start_date` — what Query D's screen shows for a unit with several,
     * so a Superadmin can choose which is correct. Query D should always
     * be empty in practice, so this is a small per-row lookup rather than
     * something worth folding into the query above.
     */
    public function primaryOwnerCandidates(Unit $unit): Collection
    {
        return PersonUnitRelationship::where('unit_id', $unit->id)
            ->where('is_primary_owner', true)
            ->whereNull('ended_at')
            ->with('person')
            ->orderBy('start_date')
            ->get();
    }
}
