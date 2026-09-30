<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\User;
use App\Services\PersonImporter;
use Illuminate\Http\UploadedFile;

function personsCsv(string $body): UploadedFile
{
    $header = 'First Name,Last Name,Email,Phone Number,Middle Name,Suffix,Gender,Date of Birth,Place of Birth,Home Address,Landline Number,Emergency Contact Name,Emergency Contact Number,Emergency Contact Relation,Notes';

    return UploadedFile::fake()->createWithContent('persons.csv', "{$header}\n{$body}");
}

test('a valid file imports every row, minting an ID number for each', function () {
    $actor = User::factory()->superadmin()->create();

    $result = app(PersonImporter::class)->import(
        $actor,
        personsCsv("Juan,Dela Cruz,juan@example.com,09171234567,,,,,,,,,,,\nMaria,Santos,maria@example.com,09179876543,,,,,,,,,,,\n"),
        'persons.csv',
    );

    expect($result['results'])->toHaveCount(2);

    $juan = Person::where('first_name', 'Juan')->first();
    expect($juan->user_id_number)->toMatch('/^\d{8}$/')
        ->and($result['results'][2]['id_number'])->toBe($juan->user_id_number)
        ->and($result['csv'])->toContain($juan->user_id_number);

    expect(AuditLog::where('action', 'person_created')->count())->toBe(2)
        ->and(AuditLog::where('action', 'bulk_onboarding_imported')->count())->toBe(1);
});

test('a missing name refuses that row without blocking the others in preview', function () {
    $preview = app(PersonImporter::class)->preview(personsCsv(",Dela Cruz,,,,,,,,,,,,,\nMaria,Santos,,,,,,,,,,,,,\n"));

    expect($preview['hasErrors'])->toBeTrue()
        ->and($preview['rows'][2]['errors'])->toContain('First Name is required.')
        ->and($preview['rows'][3]['errors'])->toBe([]);
});

test('a bad email is refused', function () {
    $preview = app(PersonImporter::class)->preview(personsCsv("Juan,Dela Cruz,not-an-email,,,,,,,,,,,,\n"));

    expect($preview['rows'][2]['errors'])->toContain('Email is not a valid address.');
});

test('a file with any error imports nothing', function () {
    $actor = User::factory()->superadmin()->create();

    expect(fn () => app(PersonImporter::class)->import(
        $actor,
        personsCsv(",Dela Cruz,,,,,,,,,,,,,\n"),
        'persons.csv',
    ))->toThrow(InvalidArgumentException::class);

    expect(Person::count())->toBe(0);
});

test('two rows with the same name and email are both refused as duplicates', function () {
    $preview = app(PersonImporter::class)->preview(personsCsv(
        "Juan,Dela Cruz,juan@example.com,,,,,,,,,,,,\n".
        "JUAN,  dela cruz ,JUAN@EXAMPLE.COM,,,,,,,,,,,,\n"
    ));

    expect($preview['rows'][2]['errors'])->toContain('Duplicate of row 3 — same name and contact detail.')
        ->and($preview['rows'][3]['errors'])->toContain('Duplicate of row 2 — same name and contact detail.');
});

test('two rows with the same name and phone (normalized) are refused', function () {
    $preview = app(PersonImporter::class)->preview(personsCsv(
        "Juan,Dela Cruz,,0917 123 4567,,,,,,,,,,,\n".
        "Juan,Dela Cruz,,09171234567,,,,,,,,,,,\n"
    ));

    expect($preview['hasErrors'])->toBeTrue()
        ->and($preview['rows'][2]['errors'])->toContain('Duplicate of row 3 — same name and contact detail.')
        ->and($preview['rows'][3]['errors'])->toContain('Duplicate of row 2 — same name and contact detail.');
});

test('the same name with no shared contact detail is only a warning', function () {
    $preview = app(PersonImporter::class)->preview(personsCsv(
        "Maria,Santos,a@example.com,,,,,,,,,,,,\n".
        "Maria,Santos,b@example.com,,,,,,,,,,,,\n"
    ));

    expect($preview['hasErrors'])->toBeFalse()
        ->and($preview['rows'][2]['warnings'])->not->toBeEmpty()
        ->and($preview['rows'][3]['warnings'])->not->toBeEmpty();
});

test('a duplicate against an existing person is refused and names their ID number', function () {
    $existing = Person::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'email' => 'juan@example.com', 'mobile_number' => '09171234567']);

    $preview = app(PersonImporter::class)->preview(personsCsv("Juan,Dela Cruz,juan@example.com,,,,,,,,,,,,\n"));

    expect($preview['rows'][2]['errors'][0])->toContain($existing->user_id_number);
});

test('a match against a soft-deleted person is refused with a restore message', function () {
    $existing = Person::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'email' => 'juan@example.com']);
    $existing->delete();

    $preview = app(PersonImporter::class)->preview(personsCsv("Juan,Dela Cruz,juan@example.com,,,,,,,,,,,,\n"));

    expect($preview['rows'][2]['errors'][0])->toContain('deleted')->toContain($existing->user_id_number);
});

test('an invalid gender value is refused', function () {
    $preview = app(PersonImporter::class)->preview(personsCsv("Juan,Dela Cruz,,,,,nonbinary,,,,,,,,\n"));

    expect($preview['rows'][2]['errors'])->toContain('Gender must be Male, Female, or Prefer not to say.');
});

test('a non-ISO date of birth is refused, not guessed', function () {
    $preview = app(PersonImporter::class)->preview(personsCsv("Juan,Dela Cruz,,,,,,9/10/2026,,,,,,,\n"));

    expect($preview['rows'][2]['errors'])->toContain('Date of Birth must be YYYY-MM-DD.');
});

test('a duplicate created after the preview is still caught by import, and nothing is written', function () {
    $actor = User::factory()->superadmin()->create();
    $file = personsCsv("Juan,Dela Cruz,juan@example.com,,,,,,,,,,,,\n");

    $preview = app(PersonImporter::class)->preview($file);
    expect($preview['hasErrors'])->toBeFalse();

    // Another admin creates the exact same person between preview and import.
    Person::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'email' => 'juan@example.com']);

    expect(fn () => app(PersonImporter::class)->import($actor, $file, 'persons.csv'))
        ->toThrow(InvalidArgumentException::class);

    expect(Person::count())->toBe(1);
});

test('the template has the exact headers and no data rows', function () {
    $csv = app(PersonImporter::class)->templateCsv();
    $lines = explode("\r\n", trim($csv));

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toBe(implode(',', PersonImporter::HEADERS));
});
