<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\SecurityEvent;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13 security review — DB-level backstops under the app layer,
 * proven by bypassing the app layer entirely (raw DB::table() calls, not
 * the Eloquent models' own guards). CLAUDE.md rules 8 and 36.
 */
test('a raw UPDATE against audit_logs is rejected by the append-only trigger', function () {
    $log = AuditLog::factory()->create();

    DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'tampered']);
})->throws(QueryException::class, 'append-only');

test('a raw DELETE against audit_logs is rejected by the append-only trigger', function () {
    $log = AuditLog::factory()->create();

    DB::table('audit_logs')->where('id', $log->id)->delete();
})->throws(QueryException::class, 'append-only');

test('a raw UPDATE against security_events is rejected by the append-only trigger', function () {
    $event = SecurityEvent::factory()->create();

    DB::table('security_events')->where('id', $event->id)->update(['event_type' => 'tampered']);
})->throws(QueryException::class, 'append-only');

test('a raw DELETE against security_events is rejected by the append-only trigger', function () {
    $event = SecurityEvent::factory()->create();

    DB::table('security_events')->where('id', $event->id)->delete();
})->throws(QueryException::class, 'append-only');

test('a raw INSERT giving a company an ordinary co-owner relationship is rejected by the trigger', function () {
    $unit = Unit::factory()->create();
    $company = Person::factory()->company()->create();

    DB::table('person_unit_relationships')->insert([
        'person_id' => $company->id, 'unit_id' => $unit->id, 'type' => 'owner',
        'is_primary_owner' => false, 'start_date' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
})->throws(QueryException::class, 'can only ever be');

test('a raw INSERT giving a company a tenant relationship is rejected by the trigger', function () {
    $unit = Unit::factory()->create();
    $company = Person::factory()->company()->create();

    DB::table('person_unit_relationships')->insert([
        'person_id' => $company->id, 'unit_id' => $unit->id, 'type' => 'tenant',
        'is_primary_owner' => false, 'start_date' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
})->throws(QueryException::class, 'can only ever be');

test('a raw UPDATE flipping an existing primary-owner company relationship to is_primary_owner=false is rejected by the trigger', function () {
    $unit = Unit::factory()->create();
    $company = Person::factory()->company()->create();

    DB::table('person_unit_relationships')->insert([
        'person_id' => $company->id, 'unit_id' => $unit->id, 'type' => 'owner',
        'is_primary_owner' => true, 'start_date' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $id = DB::table('person_unit_relationships')->where('person_id', $company->id)->value('id');

    DB::table('person_unit_relationships')->where('id', $id)->update(['is_primary_owner' => false]);
})->throws(QueryException::class, 'can only ever be');

test('a raw INSERT giving a company a primary-owner relationship is still allowed', function () {
    $unit = Unit::factory()->create();
    $company = Person::factory()->company()->create();

    DB::table('person_unit_relationships')->insert([
        'person_id' => $company->id, 'unit_id' => $unit->id, 'type' => 'owner',
        'is_primary_owner' => true, 'start_date' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(DB::table('person_unit_relationships')->where('person_id', $company->id)->exists())->toBeTrue();
});

test('a raw INSERT giving a natural person an ordinary co-owner relationship is unaffected by the company trigger', function () {
    $unit = Unit::factory()->create();
    $natural = Person::factory()->create(['entity_type' => 'natural']);

    DB::table('person_unit_relationships')->insert([
        'person_id' => $natural->id, 'unit_id' => $unit->id, 'type' => 'owner',
        'is_primary_owner' => false, 'start_date' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(DB::table('person_unit_relationships')->where('person_id', $natural->id)->exists())->toBeTrue();
});

test('a second active primary owner on the same unit is still rejected by the partial unique index', function () {
    $unit = Unit::factory()->create();
    $p1 = Person::factory()->create();
    $p2 = Person::factory()->create();

    DB::table('person_unit_relationships')->insert([
        'person_id' => $p1->id, 'unit_id' => $unit->id, 'type' => 'owner',
        'is_primary_owner' => true, 'start_date' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('person_unit_relationships')->insert([
        'person_id' => $p2->id, 'unit_id' => $unit->id, 'type' => 'owner',
        'is_primary_owner' => true, 'start_date' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
})->throws(QueryException::class, 'uq_pur_one_primary_owner_per_unit');
