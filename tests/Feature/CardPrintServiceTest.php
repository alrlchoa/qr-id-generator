<?php

use App\Models\IdCard;
use App\Models\Person;
use App\Models\User;
use App\Services\CardPrintService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->actor = User::factory()->superadmin()->create();
    $this->prints = app(CardPrintService::class);
});

function putFakePhoto(Person $person): void
{
    Storage::disk('local')->put($person->photo_path, 'fake-photo-bytes');
}

test('print returns a Smart IDesigner zip for the one card and marks it printed', function () {
    $person = Person::factory()->create(['first_name' => 'Juan', 'middle_name' => 'Reyes', 'last_name' => 'Dela Cruz', 'suffix' => 'Jr.']);
    putFakePhoto($person);
    $card = IdCard::factory()->create(['type' => 'owner', 'person_id' => $person->id, 'template_id' => null]);

    $zipBytes = $this->prints->print($this->actor, $card);

    $path = tempnam(sys_get_temp_dir(), 'ziptest').'.zip';
    file_put_contents($path, $zipBytes);
    $zip = new ZipArchive;
    $zip->open($path);

    expect($zip->locateName('Unit Owner/cards.xlsx'))->not->toBeFalse();
    expect($zip->locateName("Unit Owner/{$card->control_number}.jpg"))->not->toBeFalse();
    expect($zip->locateName('Tenant/cards.xlsx'))->not->toBeFalse();
    expect($zip->locateName('Employee/cards.xlsx'))->not->toBeFalse();
    expect($zip->locateName('Tenant/'.$card->control_number.'.jpg'))->toBeFalse();

    $zip->close();
    unlink($path);

    expect($card->fresh()->isPrinted())->toBeTrue();
    expect($card->fresh()->printed_at)->not->toBeNull();
});

test('print refuses a lost, revoked, or expired card', function (string $status) {
    $person = Person::factory()->create();
    putFakePhoto($person);
    $card = IdCard::factory()->create(['type' => 'owner', 'person_id' => $person->id, 'status' => $status]);

    expect(fn () => $this->prints->print($this->actor, $card))
        ->toThrow(InvalidArgumentException::class);

    expect($card->fresh()->isPrinted())->toBeFalse();
})->with(['lost', 'revoked', 'expired']);

test('print refuses a card that has already been printed', function () {
    $person = Person::factory()->create();
    putFakePhoto($person);
    $card = IdCard::factory()->create(['type' => 'owner', 'person_id' => $person->id, 'printed_at' => now()]);

    expect(fn () => $this->prints->print($this->actor, $card))
        ->toThrow(InvalidArgumentException::class);
});

test('print no longer needs a template — a card with no template_id prints like any other', function () {
    $person = Person::factory()->create();
    putFakePhoto($person);
    $card = IdCard::factory()->create(['template_id' => null, 'person_id' => $person->id]);

    $zipBytes = $this->prints->print($this->actor, $card);

    expect($zipBytes)->not->toBe('');
    expect($card->fresh()->isPrinted())->toBeTrue();
});

test('print refuses a card whose photo file is missing, and marks nothing', function () {
    $person = Person::factory()->create();
    // Deliberately not writing the photo file to the fake disk.
    $card = IdCard::factory()->create(['type' => 'owner', 'person_id' => $person->id]);

    expect(fn () => $this->prints->print($this->actor, $card))
        ->toThrow(InvalidArgumentException::class);

    expect($card->fresh()->isPrinted())->toBeFalse();
});

test('printing is orthogonal to status — a printed card keeps its own status', function () {
    $person = Person::factory()->create();
    putFakePhoto($person);
    $card = IdCard::factory()->create(['type' => 'owner', 'person_id' => $person->id, 'status' => 'active']);

    $this->prints->print($this->actor, $card);

    expect($card->fresh()->status)->toBe('active');
    expect($card->fresh()->isPrinted())->toBeTrue();
});
