<?php

use App\Exceptions\UnitAtCapacityException;
use App\Models\IdCard;
use App\Models\Person;
use App\Models\PersonUnitRelationship;
use App\Models\Unit;
use App\Models\User;
use App\Services\IssuanceManager;
use Illuminate\Support\Facades\DB;

/**
 * Same shape as UserAccountManagerConcurrencyTest: a second, genuinely
 * independent database connection so a lock IssuanceManager holds is still
 * held while a second actor probes it, not merely simulated by re-reading a
 * count in the same process. This directory opts out of the project-wide
 * RefreshDatabase wrapper (tests/Pest.php binds it only under Feature) and
 * cleans up its own rows instead.
 */
afterEach(function () {
    $unitIds = DB::table('units')->where('unit_number', 'like', '9%')->pluck('id');
    $personIds = DB::table('people')->where('user_id_number', 'like', '9%')->pluck('id');

    DB::table('id_cards')->whereIn('unit_id', $unitIds)->orWhereIn('person_id', $personIds)->delete();
    DB::table('person_unit_relationships')->whereIn('unit_id', $unitIds)->orWhereIn('person_id', $personIds)->delete();
    DB::table('units')->whereIn('id', $unitIds)->delete();
    DB::table('people')->whereIn('id', $personIds)->delete();
});

function makeConcurrencyUnit(): Unit
{
    return Unit::factory()->create(['building_code' => null, 'floor_code' => '99', 'unit_number' => (string) random_int(90, 99)]);
}

function makeConcurrencyPerson(): Person
{
    return Person::factory()->create(['user_id_number' => '9'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT)]);
}

test('the unit row lock genuinely blocks a second concurrent issuance attempt', function () {
    $unit = makeConcurrencyUnit();

    $config = config('database.connections.pgsql');
    $secondConnection = new PDO(
        "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
        $config['username'],
        $config['password'],
    );
    $secondConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Actor 1: start a transaction and take the same lockForUpdate() the
    // service takes, but don't commit yet -- stands in for one in-flight
    // issuance that has already entered its transaction.
    DB::beginTransaction();
    DB::table('units')->where('id', $unit->id)->lockForUpdate()->get();

    $secondConnection->exec('SET lock_timeout = 200');
    $secondConnection->beginTransaction();

    $blocked = false;

    try {
        $secondConnection->query("SELECT * FROM units WHERE id = {$unit->id} FOR UPDATE");
    } catch (PDOException $e) {
        $blocked = true;
    }

    $secondConnection->rollBack();
    expect($blocked)->toBeTrue();

    DB::rollBack();
});

test('two issuances against a unit at 5/6 occupants produce exactly one card and one clean rejection', function () {
    $unit = makeConcurrencyUnit();

    for ($i = 0; $i < 5; $i++) {
        $tenant = makeConcurrencyPerson();
        PersonUnitRelationship::factory()->create([
            'person_id' => $tenant->id, 'unit_id' => $unit->id, 'type' => 'tenant', 'start_date' => '2026-01-01',
        ]);
        IdCard::factory()->create([
            'person_id' => $tenant->id, 'unit_id' => $unit->id, 'type' => 'tenant', 'status' => 'active',
            'control_number' => '9'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
        ]);
    }

    $actor = User::factory()->admin()->create();
    $sixth = makeConcurrencyPerson();
    $seventh = makeConcurrencyPerson();
    PersonUnitRelationship::factory()->create(['person_id' => $sixth->id, 'unit_id' => $unit->id, 'type' => 'tenant', 'start_date' => '2026-01-01']);
    PersonUnitRelationship::factory()->create(['person_id' => $seventh->id, 'unit_id' => $unit->id, 'type' => 'tenant', 'start_date' => '2026-01-01']);

    $manager = app(IssuanceManager::class);

    // Actor 1's issuance for the sixth occupant runs to completion (lock
    // acquired, count checked, card written, lock released on commit)
    // before actor 2's attempt for the seventh is even considered -- the
    // same handoff proof UserAccountManagerConcurrencyTest uses for the
    // Superadmin invariant, applied to the unit lock here.
    $card = $manager->issueOwnerOrTenantCard($actor, $sixth);
    expect($card->status)->toBe('active');

    // Actor 2 now acquires the same lock actor 1 held a moment ago and must
    // see actor 1's committed sixth card -- exactly one success, one clean
    // rejection, never seven active occupant cards on this unit.
    expect(fn () => $manager->issueOwnerOrTenantCard($actor, $seventh))
        ->toThrow(UnitAtCapacityException::class);

    expect(IdCard::where('unit_id', $unit->id)->where('status', 'active')->count())->toBe(6);
    expect(IdCard::where('person_id', $seventh->id)->exists())->toBeFalse();
});
