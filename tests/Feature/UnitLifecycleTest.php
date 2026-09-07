<?php

use App\Exceptions\PrimaryOwnerInvariantException;
use App\Exceptions\UnitAtCapacityException;
use App\Models\AuditLog;
use App\Models\IdCard;
use App\Models\Person;
use App\Models\PersonUnitRelationship;
use App\Models\Unit;
use App\Models\User;
use App\Services\UnitLifecycleManager;
use Illuminate\Database\QueryException;

function units(): UnitLifecycleManager
{
    return app(UnitLifecycleManager::class);
}

test('creating a unit opens its primary-owner relationship in the same transaction', function () {
    $actor = User::factory()->admin()->create();
    $owner = Person::factory()->create(['mobile_number' => '09171234567', 'email' => 'owner@example.com']);

    $result = units()->createUnit($actor, ['building_code' => 'A', 'floor_code' => '01', 'unit_number' => '06'], ['person_id' => $owner->id], '2026-01-01');

    expect($result['unit'])->toBeInstanceOf(Unit::class);
    expect($result['relationship']->is_primary_owner)->toBeTrue();
    expect($result['relationship']->person_id)->toBe($owner->id);
    expect($result['unit']->primaryOwnerPersonId())->toBe($owner->id);

    expect(AuditLog::where('action', 'unit_created')->where('subject_id', $result['unit']->id)->exists())->toBeTrue();
    expect(AuditLog::where('action', 'relationship_opened')->where('subject_id', $result['relationship']->id)->exists())->toBeTrue();
});

test('creating a unit refuses a primary owner below the contactable tier', function () {
    $actor = User::factory()->admin()->create();
    $owner = Person::factory()->minimal()->create();

    expect(fn () => units()->createUnit($actor, ['building_code' => null, 'floor_code' => '01', 'unit_number' => '01'], ['person_id' => $owner->id], '2026-01-01'))
        ->toThrow(PrimaryOwnerInvariantException::class);

    expect(Unit::count())->toBe(0);
});

test('a company can be a unit\'s primary owner', function () {
    $actor = User::factory()->admin()->create();
    $company = Person::factory()->company()->create(['mobile_number' => '09171234567', 'email' => 'rep@acme.example']);

    $result = units()->createUnit($actor, ['building_code' => null, 'floor_code' => '02', 'unit_number' => '01'], ['person_id' => $company->id], '2026-01-01');

    expect($result['unit']->primaryOwnerPersonId())->toBe($company->id);
});

test('creating a unit can create the primary owner inline at the contactable tier', function () {
    $actor = User::factory()->admin()->create();

    $result = units()->createUnit($actor, ['building_code' => null, 'floor_code' => '03', 'unit_number' => '01'], [
        'new' => [
            'entity_type' => 'natural', 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'mobile_number' => '09171234567', 'email' => 'ada@example.com',
        ],
    ], '2026-01-01');

    $owner = Person::findOrFail($result['unit']->primaryOwnerPersonId());
    expect($owner->displayName())->toBe('Ada Lovelace');
});

test('the database refuses two active primary owners on the same unit', function () {
    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);

    expect(fn () => PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]))
        ->toThrow(QueryException::class);
});

test('promotion moves the role between two active co-owners and touches no card', function () {
    $actor = User::factory()->admin()->create();
    $unit = Unit::factory()->create();
    $outgoing = PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);
    $incoming = PersonUnitRelationship::factory()->create(['unit_id' => $unit->id, 'type' => 'owner', 'is_primary_owner' => false]);

    units()->promotePrimaryOwner($actor, $unit, $incoming->fresh());

    expect($outgoing->fresh()->is_primary_owner)->toBeFalse();
    expect($outgoing->fresh()->ended_at)->toBeNull();
    expect($incoming->fresh()->is_primary_owner)->toBeTrue();
    expect(IdCard::count())->toBe(0);

    $log = AuditLog::where('action', 'primary_owner_transferred')->where('subject_id', $unit->id)->first();
    expect($log->previous_value['operation'])->toBe('promotion');
});

test('promotion refuses an incoming relationship that is not an active owner on the same unit', function () {
    $actor = User::factory()->admin()->create();
    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);
    $otherUnitRelationship = PersonUnitRelationship::factory()->create(['type' => 'owner']);

    expect(fn () => units()->promotePrimaryOwner($actor, $unit, $otherUnitRelationship))
        ->toThrow(PrimaryOwnerInvariantException::class);
});

test('promotion into a unit already at six occupant cards is refused', function () {
    $actor = User::factory()->admin()->create();
    $unit = Unit::factory()->create();
    $outgoing = PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);
    $incoming = PersonUnitRelationship::factory()->create(['unit_id' => $unit->id, 'type' => 'owner', 'is_primary_owner' => false]);

    // The outgoing owner already holds an active card for this unit — it
    // will rejoin the six the moment they stop being primary owner.
    IdCard::factory()->create(['unit_id' => $unit->id, 'person_id' => $outgoing->person_id, 'status' => 'active', 'type' => 'owner']);

    for ($i = 0; $i < 6; $i++) {
        IdCard::factory()->create(['unit_id' => $unit->id, 'status' => 'active', 'type' => 'tenant']);
    }

    expect(fn () => units()->promotePrimaryOwner($actor, $unit, $incoming->fresh()))
        ->toThrow(UnitAtCapacityException::class);

    expect($outgoing->fresh()->is_primary_owner)->toBeTrue();
});

test('ownership transfer closes the outgoing relationship and opens the incoming as primary owner', function () {
    $actor = User::factory()->admin()->create();
    $unit = Unit::factory()->create();
    $outgoing = PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);
    $incoming = Person::factory()->create(['mobile_number' => '09171234567', 'email' => 'incoming@example.com']);

    $newRelationship = units()->transferPrimaryOwnership($actor, $unit, $outgoing->fresh(), ['person_id' => $incoming->id], '2026-02-01');

    expect($outgoing->fresh()->is_primary_owner)->toBeFalse();
    expect($outgoing->fresh()->ended_at)->not->toBeNull();
    expect($newRelationship->is_primary_owner)->toBeTrue();
    expect($newRelationship->person_id)->toBe($incoming->id);
    expect($unit->fresh()->primaryOwnerPersonId())->toBe($incoming->id);
});

test('ownership transfer refuses an incoming party below the contactable tier', function () {
    $actor = User::factory()->admin()->create();
    $unit = Unit::factory()->create();
    $outgoing = PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);
    $incoming = Person::factory()->minimal()->create();

    expect(fn () => units()->transferPrimaryOwnership($actor, $unit, $outgoing->fresh(), ['person_id' => $incoming->id], '2026-02-01'))
        ->toThrow(PrimaryOwnerInvariantException::class);

    expect($outgoing->fresh()->is_primary_owner)->toBeTrue();
});

test('ownership transfer flags a tenant-buying-the-unit case for the type-change reissue seam', function () {
    $actor = User::factory()->admin()->create();
    $unit = Unit::factory()->create();
    $outgoing = PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);
    $incoming = Person::factory()->create(['mobile_number' => '09171234567', 'email' => 'tenant@example.com']);
    PersonUnitRelationship::factory()->create(['unit_id' => $unit->id, 'person_id' => $incoming->id, 'type' => 'tenant']);

    units()->transferPrimaryOwnership($actor, $unit, $outgoing->fresh(), ['person_id' => $incoming->id], '2026-02-01');

    $log = AuditLog::where('action', 'primary_owner_transferred')->where('subject_id', $unit->id)->first();
    expect($log->new_value['requires_type_change_reissue'])->toBeTrue();
});

test('ownership transfer is refused when it would push the unit past its six-card cap', function () {
    $actor = User::factory()->admin()->create();
    $unit = Unit::factory()->create();
    $outgoing = PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);
    $incoming = Person::factory()->create(['mobile_number' => '09171234567', 'email' => 'incoming@example.com']);

    IdCard::factory()->create(['unit_id' => $unit->id, 'person_id' => $outgoing->person_id, 'status' => 'active', 'type' => 'owner']);

    for ($i = 0; $i < 6; $i++) {
        IdCard::factory()->create(['unit_id' => $unit->id, 'status' => 'active', 'type' => 'tenant']);
    }

    expect(fn () => units()->transferPrimaryOwnership($actor, $unit, $outgoing->fresh(), ['person_id' => $incoming->id], '2026-02-01'))
        ->toThrow(UnitAtCapacityException::class);

    expect($outgoing->fresh()->is_primary_owner)->toBeTrue();
});
