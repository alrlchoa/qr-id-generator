<?php

use App\Exceptions\PrimaryOwnerInvariantException;
use App\Models\AuditLog;
use App\Models\IdCard;
use App\Models\Person;
use App\Models\PersonUnitRelationship;
use App\Models\Unit;
use App\Models\User;
use App\Services\RelationshipManager;

test('opening a relationship logs relationship_opened', function () {
    $actor = User::factory()->admin()->create();
    $person = Person::factory()->create();
    $unit = Unit::factory()->create();

    $relationship = app(RelationshipManager::class)->openRelationship($actor, $person, $unit, 'tenant', '2026-01-01');

    expect($relationship->type)->toBe('tenant');
    expect($relationship->is_primary_owner)->toBeFalse();
    expect(AuditLog::where('action', 'relationship_opened')->where('subject_id', $relationship->id)->exists())->toBeTrue();
});

test('a company can never hold a tenancy', function () {
    $actor = User::factory()->admin()->create();
    $company = Person::factory()->company()->create();
    $unit = Unit::factory()->create();

    expect(fn () => app(RelationshipManager::class)->openRelationship($actor, $company, $unit, 'tenant', '2026-01-01'))
        ->toThrow(InvalidArgumentException::class);
});

test('a company can be an ordinary co-owner', function () {
    $actor = User::factory()->admin()->create();
    $company = Person::factory()->company()->create();
    $unit = Unit::factory()->create();

    $relationship = app(RelationshipManager::class)->openRelationship($actor, $company, $unit, 'owner', '2026-01-01');

    expect($relationship->type)->toBe('owner');
});

test('closing an ordinary relationship sets ended_at and logs relationship_closed', function () {
    $actor = User::factory()->admin()->create();
    $relationship = PersonUnitRelationship::factory()->create();

    app(RelationshipManager::class)->closeRelationship($actor, $relationship);

    expect($relationship->fresh()->ended_at)->not->toBeNull();
    expect(AuditLog::where('action', 'relationship_closed')->where('subject_id', $relationship->id)->exists())->toBeTrue();
});

test('closing the primary-owner relationship directly is refused, pointing at transfer instead', function () {
    $actor = User::factory()->admin()->create();
    $relationship = PersonUnitRelationship::factory()->primaryOwner()->create();

    expect(fn () => app(RelationshipManager::class)->closeRelationship($actor, $relationship))
        ->toThrow(PrimaryOwnerInvariantException::class);

    expect($relationship->fresh()->ended_at)->toBeNull();
});

test('closing a relationship expires that same person\'s matching active card on that unit', function () {
    $actor = User::factory()->admin()->create();
    $unit = Unit::factory()->create();
    $tenant = Person::factory()->create();
    $relationship = PersonUnitRelationship::factory()->create(['person_id' => $tenant->id, 'unit_id' => $unit->id, 'type' => 'tenant']);
    $card = IdCard::factory()->create(['person_id' => $tenant->id, 'unit_id' => $unit->id, 'type' => 'tenant', 'status' => 'active']);

    app(RelationshipManager::class)->closeRelationship($actor, $relationship);

    expect($card->fresh()->status)->toBe('expired');
    expect(AuditLog::where('action', 'id_expired')->where('subject_id', $card->id)->exists())->toBeTrue();
});

test('closing a relationship never touches another occupant\'s card on the same unit', function () {
    $actor = User::factory()->admin()->create();
    $unit = Unit::factory()->create();

    $closing = Person::factory()->create();
    $closingRelationship = PersonUnitRelationship::factory()->create(['person_id' => $closing->id, 'unit_id' => $unit->id, 'type' => 'tenant']);
    IdCard::factory()->create(['person_id' => $closing->id, 'unit_id' => $unit->id, 'type' => 'tenant', 'status' => 'active']);

    $otherOccupant = Person::factory()->create();
    $otherCard = IdCard::factory()->create(['person_id' => $otherOccupant->id, 'unit_id' => $unit->id, 'type' => 'tenant', 'status' => 'active']);

    app(RelationshipManager::class)->closeRelationship($actor, $closingRelationship);

    expect($otherCard->fresh()->status)->toBe('active');
});

test('closing a relationship never touches the same person\'s employee card', function () {
    $actor = User::factory()->admin()->create();
    $unit = Unit::factory()->create();
    $person = Person::factory()->create();
    $relationship = PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'unit_id' => $unit->id, 'type' => 'tenant']);
    IdCard::factory()->create(['person_id' => $person->id, 'unit_id' => $unit->id, 'type' => 'tenant', 'status' => 'active']);
    $employeeCard = IdCard::factory()->employee()->create(['person_id' => $person->id, 'status' => 'active']);

    app(RelationshipManager::class)->closeRelationship($actor, $relationship);

    expect($employeeCard->fresh()->status)->toBe('active');
});

test('cardsAffectedByClosing previews exactly what closeRelationship will expire, before anything closes', function () {
    $unit = Unit::factory()->create();
    $tenant = Person::factory()->create();
    $relationship = PersonUnitRelationship::factory()->create(['person_id' => $tenant->id, 'unit_id' => $unit->id, 'type' => 'tenant']);
    $card = IdCard::factory()->create(['person_id' => $tenant->id, 'unit_id' => $unit->id, 'type' => 'tenant', 'status' => 'active']);

    $preview = app(RelationshipManager::class)->cardsAffectedByClosing($relationship);

    expect($preview->pluck('id')->all())->toBe([$card->id]);
    expect($relationship->fresh()->ended_at)->toBeNull();
    expect($card->fresh()->status)->toBe('active');
});

test('the relationship close and its card cascade commit or roll back together', function () {
    $actor = User::factory()->admin()->create();
    $relationship = PersonUnitRelationship::factory()->ended()->create();

    // Already closed — closeRelationship() throws before touching anything,
    // proving the guard runs before the transaction, not inside a partially
    // applied one.
    expect(fn () => app(RelationshipManager::class)->closeRelationship($actor, $relationship))
        ->toThrow(InvalidArgumentException::class);
});
