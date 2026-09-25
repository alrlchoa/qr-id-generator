<?php

use App\Models\AuditLog;
use App\Models\IdCard;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\BulkCardExportService;
use App\Services\SiteSettingsManager;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->actor = User::factory()->superadmin()->create();
    $this->exports = app(BulkCardExportService::class);
});

function cardablePerson(array $attributes = []): Person
{
    $person = Person::factory()->create($attributes);
    Storage::disk('local')->put($person->photo_path, 'fake-photo-bytes');

    return $person;
}

function csvRows(ZipArchive $zip, string $entry): array
{
    $csv = $zip->getFromName($entry);
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, (string) $csv);
    rewind($handle);

    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = $row;
    }

    fclose($handle);

    return $rows;
}

test('a Reader is forbidden from exporting — the export button\'s own gate', function () {
    $reader = User::factory()->reader()->create();

    expect($reader->can('manageLifecycle', IdCard::class))->toBeFalse();
});

test('the zip filename is prefixed with the site name', function () {
    app(SiteSettingsManager::class)->rename($this->actor, 'Sunrise Towers');
    IdCard::factory()->create(['person_id' => cardablePerson()->id, 'status' => 'active']);

    $result = $this->exports->export($this->actor);

    expect($result['filename'])->toStartWith('sunrise-towers-id-cards-');

    unlink($result['path']);
});

test('export refuses when there is nothing unprinted to export', function () {
    IdCard::factory()->create(['status' => 'active', 'printed_at' => now()]);

    expect(fn () => $this->exports->export($this->actor))
        ->toThrow(InvalidArgumentException::class);
});

test('export omits a type\'s file entirely when the batch has no cards of that type', function () {
    $person = cardablePerson(['first_name' => 'Juan', 'middle_name' => 'M', 'last_name' => 'Dela Cruz', 'suffix' => 'Jr.']);
    $unit = Unit::factory()->create();
    $card = IdCard::factory()->create(['type' => 'owner', 'person_id' => $person->id, 'unit_id' => $unit->id]);

    $result = $this->exports->export($this->actor);

    $zip = new ZipArchive;
    $zip->open($result['path']);

    expect($zip->numFiles)->toBe(2);
    expect($zip->locateName('unitOwner.csv'))->not->toBeFalse();
    expect($zip->locateName("{$card->control_number}.jpg"))->not->toBeFalse();
    expect($zip->locateName('tenant.csv'))->toBeFalse();
    expect($zip->locateName('employee.csv'))->toBeFalse();

    $zip->close();
    unlink($result['path']);
});

test('unitOwner.csv keeps suffix, drops middle name, records unit code and a text control number', function () {
    $person = cardablePerson(['first_name' => 'Juan', 'middle_name' => 'Reyes', 'last_name' => 'Dela Cruz', 'suffix' => 'Jr.']);
    $unit = Unit::factory()->create(['building_code' => 'A', 'floor_code' => '05', 'unit_number' => '01']);
    $card = IdCard::factory()->create(['type' => 'owner', 'person_id' => $person->id, 'unit_id' => $unit->id, 'control_number' => '00451234']);

    $result = $this->exports->export($this->actor);

    $zip = new ZipArchive;
    $zip->open($result['path']);
    $rows = csvRows($zip, 'unitOwner.csv');
    $zip->close();
    unlink($result['path']);

    expect($rows[0])->toBe(['Photo', 'Name', 'Unit', 'Code']);
    expect($rows[1])->toBe([
        '00451234.jpg',
        'Juan Dela Cruz Jr.',
        'A0501',
        '00451234',
    ]);
});

test('a space-containing name is not wrapped in quotes in the raw CSV', function () {
    $person = cardablePerson(['first_name' => 'Juan', 'middle_name' => null, 'last_name' => 'Dela Cruz', 'suffix' => null]);
    IdCard::factory()->create(['type' => 'owner', 'person_id' => $person->id]);

    $result = $this->exports->export($this->actor);

    $zip = new ZipArchive;
    $zip->open($result['path']);
    $csv = $zip->getFromName('unitOwner.csv');
    $zip->close();
    unlink($result['path']);

    expect($csv)->toContain(',Juan Dela Cruz,');
    expect($csv)->not->toContain('"Juan Dela Cruz"');
});

test('employee cards have an empty Unit cell', function () {
    $person = cardablePerson();
    IdCard::factory()->employee()->create(['person_id' => $person->id]);

    $result = $this->exports->export($this->actor);

    $zip = new ZipArchive;
    $zip->open($result['path']);
    $rows = csvRows($zip, 'employee.csv');
    $zip->close();
    unlink($result['path']);

    expect($rows[1][2])->toBe('');
});

test('a batch spanning all three types produces exactly those three files', function () {
    IdCard::factory()->create(['type' => 'owner', 'person_id' => cardablePerson()->id]);
    IdCard::factory()->create(['type' => 'tenant', 'person_id' => cardablePerson()->id]);
    IdCard::factory()->employee()->create(['person_id' => cardablePerson()->id]);

    $result = $this->exports->export($this->actor);

    $zip = new ZipArchive;
    $zip->open($result['path']);

    expect($zip->numFiles)->toBe(6); // 3 spreadsheets + 3 photos
    expect($zip->locateName('unitOwner.csv'))->not->toBeFalse();
    expect($zip->locateName('tenant.csv'))->not->toBeFalse();
    expect($zip->locateName('employee.csv'))->not->toBeFalse();

    $zip->close();
    unlink($result['path']);
});

test('photos are byte-identical to the stored files', function () {
    $person = cardablePerson();
    $card = IdCard::factory()->create(['type' => 'owner', 'person_id' => $person->id]);

    $result = $this->exports->export($this->actor);

    $zip = new ZipArchive;
    $zip->open($result['path']);
    $bytes = $zip->getFromName("{$card->control_number}.jpg");
    $zip->close();
    unlink($result['path']);

    expect($bytes)->toBe(Storage::disk('local')->get($person->photo_path));
});

test('lost, revoked, expired, replaced, and already-printed cards are excluded', function () {
    $excluded = collect(['lost', 'revoked', 'expired', 'replaced'])
        ->map(fn (string $status) => IdCard::factory()->create(['person_id' => cardablePerson()->id, 'status' => $status]));

    $alreadyPrinted = IdCard::factory()->create(['person_id' => cardablePerson()->id, 'status' => 'active', 'printed_at' => now()]);
    $eligible = IdCard::factory()->create(['person_id' => cardablePerson()->id, 'status' => 'active']);

    $result = $this->exports->export($this->actor);

    $zip = new ZipArchive;
    $zip->open($result['path']);
    $rows = csvRows($zip, $eligible->type === 'owner' ? 'unitOwner.csv' : 'tenant.csv');
    $zip->close();
    unlink($result['path']);

    expect($rows)->toHaveCount(2); // header + the one eligible card
    expect($result['count'])->toBe(1);

    foreach ($excluded as $card) {
        expect($card->fresh()->printed_at)->toBeNull();
    }
    expect($alreadyPrinted->fresh()->printed_at)->not->toBeNull(); // unchanged, was already set
});

test('a successful export sets printed_at on every exported card and writes one id_printed audit row each', function () {
    $cardOne = IdCard::factory()->create(['person_id' => cardablePerson()->id, 'status' => 'active']);
    $cardTwo = IdCard::factory()->create(['person_id' => cardablePerson()->id, 'status' => 'active']);

    $this->exports->export($this->actor);

    expect($cardOne->fresh()->printed_at)->not->toBeNull();
    expect($cardTwo->fresh()->printed_at)->not->toBeNull();

    foreach ([$cardOne, $cardTwo] as $card) {
        $log = AuditLog::where('action', 'id_printed')
            ->where('subject_type', $card->getMorphClass())
            ->where('subject_id', $card->id)
            ->first();

        expect($log)->not->toBeNull();
        expect($log->new_value['via'])->toBe('bulk_export');
    }
});

test('a second export is refused as empty once the first has claimed everything', function () {
    IdCard::factory()->create(['person_id' => cardablePerson()->id, 'status' => 'active']);

    $this->exports->export($this->actor);

    expect(fn () => $this->exports->export($this->actor))
        ->toThrow(InvalidArgumentException::class);
});

test('a missing photo refuses the export and marks nothing', function () {
    $withPhoto = cardablePerson();
    $withoutPhoto = Person::factory()->create(); // photo_path set on the row, but no file on disk

    $cardOne = IdCard::factory()->create(['person_id' => $withPhoto->id, 'status' => 'active']);
    $cardTwo = IdCard::factory()->create(['person_id' => $withoutPhoto->id, 'status' => 'active']);

    expect(fn () => $this->exports->export($this->actor))
        ->toThrow(InvalidArgumentException::class);

    expect($cardOne->fresh()->printed_at)->toBeNull();
    expect($cardTwo->fresh()->printed_at)->toBeNull();
    expect(AuditLog::where('action', 'id_printed')->exists())->toBeFalse();
});
