<?php

use App\Exceptions\CardIssuanceRefusedException;
use App\Exceptions\UnitAtCapacityException;
use App\Models\AuditLog;
use App\Models\IdCard;
use App\Models\Person;
use App\Models\PersonUnitRelationship;
use App\Models\Unit;
use App\Models\User;
use App\Services\IssuanceManager;
use Illuminate\Support\Facades\Gate;

function issuance(): IssuanceManager
{
    return app(IssuanceManager::class);
}

/**
 * `$count` non-primary-owner active tenant relationships and cards, so the
 * unit sits at `$count`/6 before whatever the test does next.
 */
function fillUnitWithOccupants(Unit $unit, int $count): void
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

test('owner outranks tenant when both relationships are active', function () {
    $actor = User::factory()->admin()->create();
    $person = Person::factory()->create();
    $ownerUnit = Unit::factory()->create();
    $tenantUnit = Unit::factory()->create();

    PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'unit_id' => $tenantUnit->id, 'type' => 'tenant', 'start_date' => '2026-01-01']);
    PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'unit_id' => $ownerUnit->id, 'type' => 'owner', 'start_date' => '2026-02-01']);

    $card = issuance()->issueOwnerOrTenantCard($actor, $person);

    expect($card->type)->toBe('owner');
    expect($card->unit_id)->toBe($ownerUnit->id);
});

test('within the winning type, earliest start_date wins, ties broken by lowest unit_id', function () {
    $actor = User::factory()->admin()->create();
    $person = Person::factory()->create();
    $laterUnit = Unit::factory()->create();
    $earlierUnit = Unit::factory()->create();

    PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'unit_id' => $laterUnit->id, 'type' => 'owner', 'start_date' => '2026-03-01']);
    PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'unit_id' => $earlierUnit->id, 'type' => 'owner', 'start_date' => '2026-01-01']);

    $card = issuance()->issueOwnerOrTenantCard($actor, $person);

    expect($card->unit_id)->toBe($earlierUnit->id);
});

test('an admin override picks any active relationship of the winning type, never crossing types', function () {
    $actor = User::factory()->admin()->create();
    $person = Person::factory()->create();
    $ownerUnitA = Unit::factory()->create();
    $ownerUnitB = Unit::factory()->create();
    $tenantUnit = Unit::factory()->create();

    PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'unit_id' => $ownerUnitA->id, 'type' => 'owner', 'start_date' => '2026-01-01']);
    PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'unit_id' => $ownerUnitB->id, 'type' => 'owner', 'start_date' => '2026-02-01']);
    PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'unit_id' => $tenantUnit->id, 'type' => 'tenant', 'start_date' => '2025-01-01']);

    $card = issuance()->issueOwnerOrTenantCard($actor, $person, unitOverride: $ownerUnitB);

    expect($card->unit_id)->toBe($ownerUnitB->id);

    $crossTypePerson = Person::factory()->create();
    PersonUnitRelationship::factory()->create(['person_id' => $crossTypePerson->id, 'unit_id' => $ownerUnitA->id, 'type' => 'owner', 'start_date' => '2026-01-01']);
    PersonUnitRelationship::factory()->create(['person_id' => $crossTypePerson->id, 'unit_id' => $tenantUnit->id, 'type' => 'tenant', 'start_date' => '2025-01-01']);

    expect(fn () => issuance()->issueOwnerOrTenantCard($actor, $crossTypePerson, unitOverride: $tenantUnit))
        ->toThrow(InvalidArgumentException::class);
});

test('a seventh occupant card is refused once six non-primary-owner cards are active', function () {
    $actor = User::factory()->admin()->create();
    $unit = Unit::factory()->create();
    fillUnitWithOccupants($unit, 6);

    $seventh = Person::factory()->create();
    PersonUnitRelationship::factory()->create(['person_id' => $seventh->id, 'unit_id' => $unit->id, 'type' => 'tenant', 'start_date' => '2026-01-01']);

    expect(fn () => issuance()->issueOwnerOrTenantCard($actor, $seventh))
        ->toThrow(UnitAtCapacityException::class);

    expect(IdCard::where('unit_id', $unit->id)->where('status', 'active')->count())->toBe(6);
});

test('a company-owned unit refuses a seventh occupant card too — six is the real cap for it', function () {
    $actor = User::factory()->admin()->create();
    $unit = Unit::factory()->create();
    $company = Person::factory()->company()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['person_id' => $company->id, 'unit_id' => $unit->id, 'start_date' => '2025-01-01']);

    fillUnitWithOccupants($unit, 6);

    $seventh = Person::factory()->create();
    PersonUnitRelationship::factory()->create(['person_id' => $seventh->id, 'unit_id' => $unit->id, 'type' => 'tenant', 'start_date' => '2026-01-01']);

    expect(fn () => issuance()->issueOwnerOrTenantCard($actor, $seventh))
        ->toThrow(UnitAtCapacityException::class);
});

test('a natural-person primary owner with no card yet can still be issued one when all six occupant slots are full', function () {
    $actor = User::factory()->admin()->create();
    $unit = Unit::factory()->create();
    $owner = Person::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['person_id' => $owner->id, 'unit_id' => $unit->id, 'start_date' => '2025-01-01']);

    fillUnitWithOccupants($unit, 6);

    $card = issuance()->issueOwnerOrTenantCard($actor, $owner);

    expect($card->type)->toBe('owner');
    expect($card->unit_id)->toBe($unit->id);
    expect(IdCard::where('unit_id', $unit->id)->where('status', 'active')->count())->toBe(7);
});

test('issuance refuses a company by kind, before any field check', function () {
    $actor = User::factory()->admin()->create();
    $company = Person::factory()->company()->create();
    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->create(['person_id' => $company->id, 'unit_id' => $unit->id, 'type' => 'owner', 'start_date' => '2026-01-01']);

    try {
        issuance()->issueOwnerOrTenantCard($actor, $company);
        $this->fail('Expected a CardIssuanceRefusedException.');
    } catch (CardIssuanceRefusedException $e) {
        expect($e->getMessage())->toContain('company')->toContain('never be issued');
        expect($e->getMessage())->not->toContain('photo');
    }
});

test('issuance refuses a person below the cardable tier, naming what is missing', function () {
    $actor = User::factory()->admin()->create();
    $person = Person::factory()->create(['photo_path' => null]);
    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'unit_id' => $unit->id, 'type' => 'owner', 'start_date' => '2026-01-01']);

    try {
        issuance()->issueOwnerOrTenantCard($actor, $person);
        $this->fail('Expected a CardIssuanceRefusedException.');
    } catch (CardIssuanceRefusedException $e) {
        expect($e->missingFields)->toBe(['photo']);
    }
});

test('a person already holding an active owner/tenant card is refused a second one', function () {
    $actor = User::factory()->admin()->create();
    $person = Person::factory()->create();
    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'unit_id' => $unit->id, 'type' => 'owner', 'start_date' => '2026-01-01']);

    issuance()->issueOwnerOrTenantCard($actor, $person);

    expect(fn () => issuance()->issueOwnerOrTenantCard($actor, $person))
        ->toThrow(CardIssuanceRefusedException::class);
});

test('issuing a card writes id_issued to the audit log', function () {
    $actor = User::factory()->admin()->create();
    $person = Person::factory()->create();
    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'unit_id' => $unit->id, 'type' => 'owner', 'start_date' => '2026-01-01']);

    $card = issuance()->issueOwnerOrTenantCard($actor, $person);

    expect(AuditLog::where('action', 'id_issued')->where('subject_id', $card->id)->exists())->toBeTrue();
});

test('an employee card names no unit and never counts toward any unit\'s cap', function () {
    $actor = User::factory()->superadmin()->create();
    $person = Person::factory()->create();

    $card = issuance()->issueEmployeeCard($actor, $person, position: 'Guard', department: 'Security');

    expect($card->type)->toBe('employee');
    expect($card->unit_id)->toBeNull();
    expect($card->position)->toBe('Guard');
    expect($card->department)->toBe('Security');
    expect(AuditLog::where('action', 'id_issued')->where('subject_id', $card->id)->exists())->toBeTrue();
});

test('employee cards bypass the six-slot cap entirely', function () {
    $actor = User::factory()->superadmin()->create();
    $unit = Unit::factory()->create();
    fillUnitWithOccupants($unit, 6);

    $employee = Person::factory()->create();

    $card = issuance()->issueEmployeeCard($actor, $employee);

    expect($card->type)->toBe('employee');
});

test('a UnitAtCapacityException is a usable error, not a 500 — it names the unit', function () {
    $actor = User::factory()->admin()->create();
    $unit = Unit::factory()->create();
    fillUnitWithOccupants($unit, 6);

    $seventh = Person::factory()->create();
    PersonUnitRelationship::factory()->create(['person_id' => $seventh->id, 'unit_id' => $unit->id, 'type' => 'tenant', 'start_date' => '2026-01-01']);

    try {
        issuance()->issueOwnerOrTenantCard($actor, $seventh);
        $this->fail('Expected a UnitAtCapacityException.');
    } catch (UnitAtCapacityException $e) {
        expect($e->unit->id)->toBe($unit->id);
        expect($e->getMessage())->toBeString()->not->toBe('');
    }
});

test('employee issuance is Superadmin-only', function () {
    $admin = User::factory()->admin()->create();
    $superadmin = User::factory()->superadmin()->create();

    expect(Gate::forUser($admin)->allows('issueEmployee', IdCard::class))->toBeFalse();
    expect(Gate::forUser($superadmin)->allows('issueEmployee', IdCard::class))->toBeTrue();
});
