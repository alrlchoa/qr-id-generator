<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\PersonUnitRelationship;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

test('refuses to run outside local without --force', function () {
    $this->artisan('demo:seed-test-data')->assertExitCode(1);

    expect(Person::count())->toBe(0);
    expect(Unit::count())->toBe(0);
});

test('seeds people, companies, and units without touching users, and is fully audited', function () {
    $this->artisan('demo:seed-test-data --force')->assertExitCode(0);

    expect(Person::count())->toBeGreaterThanOrEqual(50 + 5);
    expect(Person::where('entity_type', 'company')->count())->toBeGreaterThanOrEqual(5);
    expect(Person::where('entity_type', 'natural')->count())->toBeGreaterThanOrEqual(50);
    expect(Unit::count())->toBeGreaterThanOrEqual(10);
    expect(User::count())->toBe(0);

    $multiUnitOwners = PersonUnitRelationship::where('is_primary_owner', true)
        ->selectRaw('person_id, count(*) as unit_count')
        ->groupBy('person_id')
        ->havingRaw('count(*) > 1')
        ->get();

    expect($multiUnitOwners->count())->toBeGreaterThanOrEqual(3);

    expect(AuditLog::where('action', 'person_created')->count())->toBeGreaterThanOrEqual(55);
    expect(AuditLog::where('action', 'unit_created')->count())->toBeGreaterThanOrEqual(10);
    expect(AuditLog::where('user_role', 'console')->exists())->toBeTrue();

    $person = Person::whereNotNull('photo_path')->first();
    expect($person)->not->toBeNull();
    expect(Storage::disk('local')->exists($person->photo_path))->toBeTrue();
});

test('every seeded unit has exactly one active primary owner', function () {
    $this->artisan('demo:seed-test-data --force')->assertExitCode(0);

    foreach (Unit::all() as $unit) {
        expect($unit->primaryOwnerRelationship())->not->toBeNull();
    }
});
