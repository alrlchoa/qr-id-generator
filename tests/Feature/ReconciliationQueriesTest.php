<?php

use App\Models\IdCard;
use App\Models\Person;
use App\Models\PersonUnitRelationship;
use App\Models\Unit;
use App\Models\User;
use App\Services\ReconciliationQueries;
use App\Services\UnitLifecycleManager;

function reconciliation(): ReconciliationQueries
{
    return app(ReconciliationQueries::class);
}

/**
 * `$count` non-primary-owner active tenant relationships and cards on
 * `$unit`, same shape IssuanceManagerTest already uses to put a unit at a
 * known occupancy before a test's own assertions.
 */
function fillUnitWithTenants(Unit $unit, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $tenant = Person::factory()->create();
        PersonUnitRelationship::factory()->create([
            'person_id' => $tenant->id, 'unit_id' => $unit->id, 'type' => 'tenant', 'start_date' => '2026-01-01',
        ]);
        IdCard::factory()->create([
            'person_id' => $tenant->id, 'unit_id' => $unit->id, 'type' => 'tenant', 'status' => 'active',
        ]);
    }
}

// -----------------------------------------------------------------------
// Query A — leases past their contract end date, still open.
// -----------------------------------------------------------------------

test('Query A lists an open relationship whose contract end date has passed', function () {
    $relationship = PersonUnitRelationship::factory()->create([
        'contract_end_date' => now()->subDay()->toDateString(),
        'ended_at' => null,
    ]);

    $results = reconciliation()->leasesPastTerm();

    expect($results->pluck('id'))->toContain($relationship->id);
});

test('Query A excludes a relationship whose contract end date is still in the future', function () {
    $relationship = PersonUnitRelationship::factory()->create([
        'contract_end_date' => now()->addMonth()->toDateString(),
        'ended_at' => null,
    ]);

    expect(reconciliation()->leasesPastTerm()->pluck('id'))->not->toContain($relationship->id);
});

test('Query A excludes a relationship with no contract end date at all', function () {
    $relationship = PersonUnitRelationship::factory()->create([
        'contract_end_date' => null,
        'ended_at' => null,
    ]);

    expect(reconciliation()->leasesPastTerm()->pluck('id'))->not->toContain($relationship->id);
});

test('Query A excludes a relationship past its term that was already closed', function () {
    // A closed relationship is the resolved case, not a divergence — this
    // is what keeps Query A from re-flagging something an admin already
    // handled.
    $relationship = PersonUnitRelationship::factory()->create([
        'contract_end_date' => now()->subDay()->toDateString(),
        'ended_at' => now(),
    ]);

    expect(reconciliation()->leasesPastTerm()->pluck('id'))->not->toContain($relationship->id);
});

// -----------------------------------------------------------------------
// Query B — persons who could be carded today and are not.
// -----------------------------------------------------------------------

test('Query B lists a cardable natural person with an active relationship and no card', function () {
    $person = Person::factory()->create();
    PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'type' => 'owner']);

    expect(reconciliation()->cardableAndUncarded()->pluck('id'))->toContain($person->id);
});

test('Query B excludes a multi-unit owner who correctly holds one active card', function () {
    $person = Person::factory()->create();
    $unitA = Unit::factory()->create();
    $unitB = Unit::factory()->create();

    PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'unit_id' => $unitA->id, 'type' => 'owner']);
    PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'unit_id' => $unitB->id, 'type' => 'owner']);
    IdCard::factory()->create(['person_id' => $person->id, 'unit_id' => $unitA->id, 'type' => 'owner', 'status' => 'active']);

    expect(reconciliation()->cardableAndUncarded()->pluck('id'))->not->toContain($person->id);
});

test('Query B lists a multi-unit owner whose only card was expired and not replaced', function () {
    $person = Person::factory()->create();
    PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'type' => 'owner']);
    IdCard::factory()->create(['person_id' => $person->id, 'type' => 'owner', 'status' => 'expired']);

    expect(reconciliation()->cardableAndUncarded()->pluck('id'))->toContain($person->id);
});

test('Query B excludes a company, even with an active owner relationship', function () {
    $company = Person::factory()->company()->create();
    PersonUnitRelationship::factory()->create(['person_id' => $company->id, 'type' => 'owner']);

    expect(reconciliation()->cardableAndUncarded()->pluck('id'))->not->toContain($company->id);
});

test('Query B excludes a minimal-tier co-owner recorded with only a name', function () {
    $person = Person::factory()->minimal()->create();
    PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'type' => 'owner']);

    expect(reconciliation()->cardableAndUncarded()->pluck('id'))->not->toContain($person->id);
});

test('Query B excludes a contactable person who still has no photo', function () {
    $person = Person::factory()->create(['photo_path' => null]);
    PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'type' => 'owner']);

    expect(reconciliation()->cardableAndUncarded()->pluck('id'))->not->toContain($person->id);
});

test('Query B excludes a cardable person with no active relationship at all', function () {
    $person = Person::factory()->create();

    expect(reconciliation()->cardableAndUncarded()->pluck('id'))->not->toContain($person->id);
});

// -----------------------------------------------------------------------
// Query C — units with all six occupant slots taken.
// -----------------------------------------------------------------------

test('Query C lists a unit whose six non-primary-owner slots are all filled', function () {
    $owner = Person::factory()->create();
    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['person_id' => $owner->id, 'unit_id' => $unit->id]);
    IdCard::factory()->create(['person_id' => $owner->id, 'unit_id' => $unit->id, 'type' => 'owner', 'status' => 'active']);
    fillUnitWithTenants($unit, 6);

    expect(reconciliation()->unitsAtCapacity()->pluck('id'))->toContain($unit->id);
});

test('Query C excludes a unit with five of six non-primary-owner slots filled', function () {
    $owner = Person::factory()->create();
    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['person_id' => $owner->id, 'unit_id' => $unit->id]);
    IdCard::factory()->create(['person_id' => $owner->id, 'unit_id' => $unit->id, 'type' => 'owner', 'status' => 'active']);
    fillUnitWithTenants($unit, 5);

    expect(reconciliation()->unitsAtCapacity()->pluck('id'))->not->toContain($unit->id);
});

// -----------------------------------------------------------------------
// Query D — units whose active primary-owner count is not exactly one.
// -----------------------------------------------------------------------

test('Query D is empty for a fixture built entirely through the application\'s own flows', function () {
    $actor = User::factory()->admin()->create();
    $owner = Person::factory()->create(['mobile_number' => '09171234567', 'email' => 'owner@example.com']);

    app(UnitLifecycleManager::class)->createUnit(
        $actor,
        ['building_code' => 'A', 'floor_code' => '01', 'unit_number' => '01'],
        ['person_id' => $owner->id],
        '2026-01-01',
    );

    expect(reconciliation()->primaryOwnerIntegrityIssues())->toHaveCount(0);
});

test('Query D catches a unit left with zero active primary owners by a hand-edited row', function () {
    // Reachable only by writing around RelationshipManager — the partial
    // unique index and the app-layer guard both refuse this through any
    // sanctioned path (architecture §5.4). That's the whole point of an
    // integrity canary: it exists for exactly this kind of bypass.
    $unit = Unit::factory()->create();
    $owner = Person::factory()->create();
    $relationship = PersonUnitRelationship::factory()->primaryOwner()->create([
        'person_id' => $owner->id, 'unit_id' => $unit->id,
    ]);

    $relationship->forceFill(['ended_at' => now()])->save();

    $results = reconciliation()->primaryOwnerIntegrityIssues();

    expect($results->pluck('id'))->toContain($unit->id);
    expect(reconciliation()->primaryOwnerCandidates($unit))->toHaveCount(0);
});
