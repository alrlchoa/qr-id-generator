<?php

use App\Exceptions\PrimaryOwnerInvariantException;
use App\Models\AuditLog;
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
