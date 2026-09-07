<?php

use App\Exceptions\DeletionBlockedException;
use App\Models\AuditLog;
use App\Models\IdCard;
use App\Models\Person;
use App\Models\PersonUnitRelationship;
use App\Models\SecurityEvent;
use App\Models\Unit;
use App\Models\User;
use App\Services\UnitDeletionManager;
use App\Services\UnitLifecycleManager;

test('deleting a unit with only its primary-owner relationship left closes that relationship and soft-deletes the unit', function () {
    $actor = User::factory()->admin()->create();
    $unit = Unit::factory()->create();
    $ownerRelationship = PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);

    app(UnitDeletionManager::class)->delete($actor, $unit);

    expect(Unit::find($unit->id))->toBeNull();
    expect(Unit::withTrashed()->find($unit->id)->deleted_at)->not->toBeNull();
    expect($ownerRelationship->fresh()->ended_at)->not->toBeNull();
    expect($ownerRelationship->fresh()->is_primary_owner)->toBeFalse();

    expect(AuditLog::where('action', 'unit_deleted')->where('subject_id', $unit->id)->exists())->toBeTrue();
});

test('deleting a unit with another active relationship is refused and logs a security event', function () {
    $actor = User::factory()->admin()->create();
    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);
    PersonUnitRelationship::factory()->create(['unit_id' => $unit->id, 'type' => 'tenant']);

    expect(fn () => app(UnitDeletionManager::class)->delete($actor, $unit))
        ->toThrow(DeletionBlockedException::class);

    expect($unit->fresh()->deleted_at)->toBeNull();
    expect(SecurityEvent::where('event_type', 'deletion_blocked')->exists())->toBeTrue();
});

test('deleting a unit with an active card is refused', function () {
    $actor = User::factory()->admin()->create();
    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);
    IdCard::factory()->create(['unit_id' => $unit->id, 'status' => 'active']);

    expect(fn () => app(UnitDeletionManager::class)->delete($actor, $unit))
        ->toThrow(DeletionBlockedException::class);
});

test('restoring a unit requires designating a primary owner in the same transaction', function () {
    $actor = User::factory()->admin()->create();
    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);
    app(UnitDeletionManager::class)->delete($actor, $unit);

    $newOwner = Person::factory()->create(['mobile_number' => '09171234567', 'email' => 'new@example.com']);
    $trashedUnit = Unit::withTrashed()->findOrFail($unit->id);

    app(UnitDeletionManager::class)->restore($actor, $trashedUnit, ['person_id' => $newOwner->id], '2026-03-01', app(UnitLifecycleManager::class));

    $restored = Unit::findOrFail($unit->id);
    expect($restored->deleted_at)->toBeNull();
    expect($restored->primaryOwnerPersonId())->toBe($newOwner->id);

    expect(AuditLog::where('action', 'unit_restored')->where('subject_id', $unit->id)->exists())->toBeTrue();
});
