<?php

use App\Exceptions\DeletionBlockedException;
use App\Exceptions\PrimaryOwnerInvariantException;
use App\Models\AuditLog;
use App\Models\Person;
use App\Models\PersonUnitRelationship;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\PersonDeletionManager;
use App\Services\UnitLifecycleManager;

test('deleting a person with no dependents succeeds', function () {
    $actor = User::factory()->admin()->create();
    $person = Person::factory()->create();

    app(PersonDeletionManager::class)->delete($actor, $person);

    expect($person->fresh()->deleted_at)->not->toBeNull();
    expect(AuditLog::where('action', 'person_deleted')->where('subject_id', $person->id)->exists())->toBeTrue();
});

test('deleting a person who is a live unit\'s primary owner is refused with the transfer instruction, not the generic message', function () {
    $actor = User::factory()->admin()->create();
    $person = Person::factory()->create();
    $relationship = PersonUnitRelationship::factory()->primaryOwner()->create(['person_id' => $person->id]);

    try {
        app(PersonDeletionManager::class)->delete($actor, $person);
        $this->fail('Expected PrimaryOwnerInvariantException.');
    } catch (PrimaryOwnerInvariantException $e) {
        expect($e->getMessage())->toContain($relationship->unit->unitCode())
            ->toContain('Transfer the primary-owner role');
    }
});

test('deleting a person with an ordinary active relationship is refused and logs a security event', function () {
    $actor = User::factory()->admin()->create();
    $person = Person::factory()->create();
    PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'type' => 'tenant']);

    expect(fn () => app(PersonDeletionManager::class)->delete($actor, $person))
        ->toThrow(DeletionBlockedException::class);

    expect($person->fresh()->deleted_at)->toBeNull();
    expect(SecurityEvent::where('event_type', 'deletion_blocked')->exists())->toBeTrue();
});

test('a primary owner blocked from deletion is no longer blocked once the role is transferred', function () {
    $actor = User::factory()->admin()->create();
    $person = Person::factory()->create();
    $relationship = PersonUnitRelationship::factory()->primaryOwner()->create(['person_id' => $person->id]);

    $newOwner = Person::factory()->create(['mobile_number' => '09171234567', 'email' => 'new@example.com']);
    app(UnitLifecycleManager::class)->transferPrimaryOwnership($actor, $relationship->unit, $relationship->fresh(), ['person_id' => $newOwner->id], '2026-02-01');

    app(PersonDeletionManager::class)->delete($actor, $person->fresh());

    expect($person->fresh()->deleted_at)->not->toBeNull();
});
