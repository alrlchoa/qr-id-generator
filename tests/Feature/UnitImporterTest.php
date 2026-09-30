<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\UnitImporter;
use Illuminate\Http\UploadedFile;

function unitsCsv(string $body): UploadedFile
{
    return UploadedFile::fake()->createWithContent('units.csv', "Building Code,Floor,Unit Number,Primary Owner ID Number,Date First Owned\n{$body}");
}

test('a valid file creates each unit with its primary-owner relationship', function () {
    $owner = Person::factory()->create(['mobile_number' => '09171234567', 'email' => 'owner@example.com']);
    $actor = User::factory()->superadmin()->create();

    $result = app(UnitImporter::class)->import($actor, unitsCsv("A,5,1,{$owner->user_id_number},2026-01-01\n"), 'units.csv');

    expect($result['count'])->toBe(1);

    $unit = Unit::first();
    expect($unit->unitCode())->toBe('A0501')
        ->and($unit->primaryOwnerPersonId())->toBe($owner->id);

    expect(AuditLog::where('action', 'unit_created')->count())->toBe(1)
        ->and(AuditLog::where('action', 'relationship_opened')->count())->toBe(1)
        ->and(AuditLog::where('action', 'bulk_onboarding_imported')->count())->toBe(1);
});

test('an omitted building code and single-digit floor/unit number pass validation and create the padded code', function () {
    $owner = Person::factory()->create(['mobile_number' => '09171234567', 'email' => 'owner@example.com']);
    $actor = User::factory()->superadmin()->create();

    app(UnitImporter::class)->import($actor, unitsCsv(",7,3,{$owner->user_id_number},2026-01-01\n"), 'units.csv');

    expect(Unit::first()->unitCode())->toBe('0703');
});

test('a two-letter building code is accepted and never padded', function () {
    $owner = Person::factory()->create(['mobile_number' => '09171234567', 'email' => 'owner@example.com']);
    $actor = User::factory()->superadmin()->create();

    app(UnitImporter::class)->import($actor, unitsCsv("AB,5,1,{$owner->user_id_number},2026-01-01\n"), 'units.csv');

    expect(Unit::first()->unitCode())->toBe('AB0501');
});

test('a three-letter building code is refused', function () {
    $owner = Person::factory()->create(['mobile_number' => '09171234567', 'email' => 'owner@example.com']);

    $preview = app(UnitImporter::class)->preview(unitsCsv("ABC,5,1,{$owner->user_id_number},2026-01-01\n"));

    expect($preview['rows'][2]['errors'])->toContain('Building Code must be 1-2 letters.');
});

test('the owner ID is left-padded to 8 digits before lookup', function () {
    $owner = Person::factory()->create(['user_id_number' => '00451234', 'mobile_number' => '09171234567', 'email' => 'owner@example.com']);

    $preview = app(UnitImporter::class)->preview(unitsCsv("A,5,1,451234,2026-01-01\n"));

    expect($preview['hasErrors'])->toBeFalse();
});

test('an unknown owner ID is refused', function () {
    $preview = app(UnitImporter::class)->preview(unitsCsv("A,5,1,99999999,2026-01-01\n"));

    expect($preview['rows'][2]['errors'][0])->toContain('No person with ID number 99999999');
});

test('a soft-deleted owner is refused with a restore message', function () {
    $owner = Person::factory()->create(['mobile_number' => '09171234567', 'email' => 'owner@example.com']);
    $owner->delete();

    $preview = app(UnitImporter::class)->preview(unitsCsv("A,5,1,{$owner->user_id_number},2026-01-01\n"));

    expect($preview['rows'][2]['errors'][0])->toContain('deleted');
});

test('a non-contactable owner is refused', function () {
    $owner = Person::factory()->minimal()->create();

    $preview = app(UnitImporter::class)->preview(unitsCsv("A,5,1,{$owner->user_id_number},2026-01-01\n"));

    expect($preview['rows'][2]['errors'][0])->toContain('missing a mobile number or email');
});

test('a non-contactable owner is refused at import too, not just preview, and nothing is written', function () {
    $owner = Person::factory()->minimal()->create();
    $actor = User::factory()->superadmin()->create();

    expect(fn () => app(UnitImporter::class)->import($actor, unitsCsv("A,5,1,{$owner->user_id_number},2026-01-01\n"), 'units.csv'))
        ->toThrow(InvalidArgumentException::class);

    expect(Unit::count())->toBe(0)
        ->and(AuditLog::where('action', 'bulk_onboarding_imported')->count())->toBe(0);
});

test('a non-ISO date is refused, not guessed', function () {
    $owner = Person::factory()->create(['mobile_number' => '09171234567', 'email' => 'owner@example.com']);

    $preview = app(UnitImporter::class)->preview(unitsCsv("A,5,1,{$owner->user_id_number},9/10/2026\n"));

    expect($preview['rows'][2]['errors'])->toContain('Date First Owned must be YYYY-MM-DD.');
});

test('the same code twice in the file, including differing case and padding, refuses every such row', function () {
    $owner = Person::factory()->create(['mobile_number' => '09171234567', 'email' => 'owner@example.com']);

    $preview = app(UnitImporter::class)->preview(unitsCsv(
        "A,5,1,{$owner->user_id_number},2026-01-01\n".
        "a,05,01,{$owner->user_id_number},2026-01-01\n"
    ));

    expect($preview['hasErrors'])->toBeTrue()
        ->and($preview['rows'][2]['errors'])->toContain('Duplicate of row 3 — same unit code.')
        ->and($preview['rows'][3]['errors'])->toContain('Duplicate of row 2 — same unit code.');
});

test('an already-existing unit code is refused', function () {
    $unit = Unit::factory()->create(['building_code' => 'A', 'floor_code' => '05', 'unit_number' => '01']);
    $owner = Person::factory()->create(['mobile_number' => '09171234567', 'email' => 'owner@example.com']);

    $preview = app(UnitImporter::class)->preview(unitsCsv("A,5,1,{$owner->user_id_number},2026-01-01\n"));

    expect($preview['rows'][2]['errors'][0])->toContain('already exists');
});

test('a soft-deleted unit code is refused with a restore message, not a database error', function () {
    $unit = Unit::factory()->create(['building_code' => 'A', 'floor_code' => '05', 'unit_number' => '01']);
    $unit->delete();
    $owner = Person::factory()->create(['mobile_number' => '09171234567', 'email' => 'owner@example.com']);

    $preview = app(UnitImporter::class)->preview(unitsCsv("A,5,1,{$owner->user_id_number},2026-01-01\n"));

    expect($preview['rows'][2]['errors'][0])->toContain('deleted unit');
});

test('a file with any error imports nothing', function () {
    $actor = User::factory()->superadmin()->create();

    expect(fn () => app(UnitImporter::class)->import($actor, unitsCsv("A,5,1,99999999,2026-01-01\n"), 'units.csv'))
        ->toThrow(InvalidArgumentException::class);

    expect(Unit::count())->toBe(0);
});

test('a unit created after the preview is still caught by import, and nothing is written', function () {
    $owner = Person::factory()->create(['mobile_number' => '09171234567', 'email' => 'owner@example.com']);
    $actor = User::factory()->superadmin()->create();
    $file = unitsCsv("A,5,1,{$owner->user_id_number},2026-01-01\n");

    $preview = app(UnitImporter::class)->preview($file);
    expect($preview['hasErrors'])->toBeFalse();

    // Another admin creates the exact same unit code between preview and import.
    Unit::factory()->create(['building_code' => 'A', 'floor_code' => '05', 'unit_number' => '01']);

    expect(fn () => app(UnitImporter::class)->import($actor, $file, 'units.csv'))
        ->toThrow(InvalidArgumentException::class);

    expect(Unit::count())->toBe(1);
});

test('the template has the exact headers and no data rows', function () {
    $csv = app(UnitImporter::class)->templateCsv();
    $lines = explode("\r\n", trim($csv));

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toBe(implode(',', UnitImporter::HEADERS));
});
