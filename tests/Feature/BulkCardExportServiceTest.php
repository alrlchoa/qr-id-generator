<?php

use App\Models\AuditLog;
use App\Models\IdCard;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\BulkCardExportService;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;

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

function xlsxRows(ZipArchive $zip, string $entry): array
{
    $bytes = $zip->getFromName($entry);
    $path = tempnam(sys_get_temp_dir(), 'xlsx-read').'.xlsx';
    file_put_contents($path, $bytes);

    $reader = new Reader;
    $reader->open($path);

    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }

    $reader->close();
    unlink($path);

    return $rows;
}

test('a Reader is forbidden from exporting — the export button\'s own gate', function () {
    $reader = User::factory()->reader()->create();

    expect($reader->can('manageLifecycle', IdCard::class))->toBeFalse();
});

test('export refuses when there is nothing unprinted to export', function () {
    IdCard::factory()->create(['status' => 'active', 'printed_at' => now()]);

    expect(fn () => $this->exports->export($this->actor))
        ->toThrow(InvalidArgumentException::class);
});

test('export produces three always-present folders, header-only for an empty type', function () {
    $person = cardablePerson(['first_name' => 'Juan', 'middle_name' => 'M', 'last_name' => 'Dela Cruz', 'suffix' => 'Jr.']);
    $unit = Unit::factory()->create();
    $card = IdCard::factory()->create(['type' => 'owner', 'person_id' => $person->id, 'unit_id' => $unit->id]);

    $result = $this->exports->export($this->actor);

    $zip = new ZipArchive;
    $zip->open($result['path']);

    expect($zip->locateName('Unit Owner/cards.xlsx'))->not->toBeFalse();
    expect($zip->locateName('Tenant/cards.xlsx'))->not->toBeFalse();
    expect($zip->locateName('Employee/cards.xlsx'))->not->toBeFalse();
    expect($zip->locateName("Unit Owner/{$card->control_number}.jpg"))->not->toBeFalse();

    $tenantRows = xlsxRows($zip, 'Tenant/cards.xlsx');
    expect($tenantRows)->toHaveCount(1); // header row only
    expect($tenantRows[0])->toBe(['Image', 'Name', 'Unit', 'Code']);

    $zip->close();
    unlink($result['path']);
});

test('cards.xlsx keeps suffix, drops middle name, records unit code and a text control number', function () {
    $person = cardablePerson(['first_name' => 'Juan', 'middle_name' => 'Reyes', 'last_name' => 'Dela Cruz', 'suffix' => 'Jr.']);
    $unit = Unit::factory()->create(['building_code' => 'A', 'floor_code' => '05', 'unit_number' => '01']);
    $card = IdCard::factory()->create(['type' => 'owner', 'person_id' => $person->id, 'unit_id' => $unit->id, 'control_number' => '00451234']);

    $result = $this->exports->export($this->actor);

    $zip = new ZipArchive;
    $zip->open($result['path']);
    $rows = xlsxRows($zip, 'Unit Owner/cards.xlsx');
    $zip->close();
    unlink($result['path']);

    expect($rows[0])->toBe(['Image', 'Name', 'Unit', 'Code']);
    expect($rows[1])->toBe([
        '00451234.jpg',
        'Juan Dela Cruz Jr.',
        'A0501',
        '00451234',
    ]);
});

test('employee cards have an empty Unit cell', function () {
    $person = cardablePerson();
    IdCard::factory()->employee()->create(['person_id' => $person->id]);

    $result = $this->exports->export($this->actor);

    $zip = new ZipArchive;
    $zip->open($result['path']);
    $rows = xlsxRows($zip, 'Employee/cards.xlsx');
    $zip->close();
    unlink($result['path']);

    expect($rows[1][2])->toBe('');
});

test('photos are byte-identical to the stored files', function () {
    $person = cardablePerson();
    $card = IdCard::factory()->create(['type' => 'owner', 'person_id' => $person->id]);

    $result = $this->exports->export($this->actor);

    $zip = new ZipArchive;
    $zip->open($result['path']);
    $bytes = $zip->getFromName("Unit Owner/{$card->control_number}.jpg");
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
    $rows = xlsxRows($zip, ($eligible->type === 'owner' ? 'Unit Owner' : 'Tenant').'/cards.xlsx');
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
