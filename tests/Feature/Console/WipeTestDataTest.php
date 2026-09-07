<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\PersonUnitRelationship;
use App\Models\SecurityEvent;
use App\Models\Unit;
use App\Models\User;

test('wipes every domain table, users included', function () {
    User::factory()->superadmin()->count(2)->create();
    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);
    SecurityEvent::factory()->create();

    expect(Person::count())->toBeGreaterThan(0);
    expect(User::count())->toBeGreaterThanOrEqual(2);

    $this->artisan('db:wipe-test-data')->assertExitCode(0);

    expect(User::count())->toBe(0);
    expect(Person::count())->toBe(0);
    expect(Unit::count())->toBe(0);
    expect(PersonUnitRelationship::count())->toBe(0);
    expect(AuditLog::count())->toBe(0);
    expect(SecurityEvent::count())->toBe(0);
});

test('resets identity sequences so the next row starts fresh', function () {
    Person::factory()->count(3)->create();

    $this->artisan('db:wipe-test-data')->assertExitCode(0);

    $person = Person::factory()->create();

    expect($person->id)->toBe(1);
});
