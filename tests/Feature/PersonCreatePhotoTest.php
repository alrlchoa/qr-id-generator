<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Livewire\Volt\Volt;

test('creating a person with a photo stores it and logs both person_created and photo_updated', function () {
    Storage::fake('local');
    bootstrapSystem();

    $this->actingAs(User::factory()->admin()->create());

    Volt::test('pages.people.create')
        ->set('first_name', 'Ada')
        ->set('last_name', 'Lovelace')
        ->set('photo', UploadedFile::fake()->image('photo.jpg', 600, 600))
        ->call('create')
        ->assertHasNoErrors();

    $person = Person::where('first_name', 'Ada')->firstOrFail();

    expect($person->photo_path)->not->toBeNull();
    Storage::disk('local')->assertExists($person->photo_path);
    expect($person->photo_path)->toStartWith('people-photos/');

    expect(AuditLog::where('subject_id', $person->id)->where('action', 'person_created')->exists())->toBeTrue();
    expect(AuditLog::where('subject_id', $person->id)->where('action', 'photo_updated')->exists())->toBeTrue();
});

test('creating a person without a photo leaves photo_path null', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    Volt::test('pages.people.create')
        ->set('first_name', 'Bob')
        ->set('last_name', 'Builder')
        ->call('create')
        ->assertHasNoErrors();

    $person = Person::where('first_name', 'Bob')->firstOrFail();

    expect($person->photo_path)->toBeNull();
    expect(AuditLog::where('subject_id', $person->id)->where('action', 'photo_updated')->exists())->toBeFalse();
});

test('an oversized photo on the create form is rejected', function () {
    Storage::fake('local');
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    Volt::test('pages.people.create')
        ->set('first_name', 'Carl')
        ->set('last_name', 'Sagan')
        ->set('photo', UploadedFile::fake()->create('big.jpg', 1025, 'image/jpeg'))
        ->call('create')
        ->assertHasErrors('photo');

    expect(Person::where('first_name', 'Carl')->exists())->toBeFalse();
});

test('removing a staged photo before submitting creates the person with no photo', function () {
    Storage::fake('local');
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    Volt::test('pages.people.create')
        ->set('first_name', 'Dana')
        ->set('last_name', 'White')
        ->set('photo', UploadedFile::fake()->image('photo.jpg', 400, 400))
        ->call('removePhoto')
        ->assertSet('photo', null)
        ->call('create')
        ->assertHasNoErrors();

    $person = Person::where('first_name', 'Dana')->firstOrFail();

    expect($person->photo_path)->toBeNull();
});

test('creating a person with a photo produced by the crop tool (a plain upload, from the server\'s view) stores it the same way', function () {
    // The crop tool runs entirely client-side (canvas + pointer drag) and
    // is not something a headless Pest run can exercise. What matters
    // server-side is that its output — a square File uploaded via
    // $wire.upload() — is handled identically to a file-picker upload
    // that was already square, which this proves directly.
    Storage::fake('local');
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    Volt::test('pages.people.create')
        ->set('first_name', 'Eve')
        ->set('last_name', 'Cropped')
        ->set('photo', UploadedFile::fake()->image('cropped.jpg', 480, 480))
        ->call('create')
        ->assertHasNoErrors();

    $person = Person::where('first_name', 'Eve')->firstOrFail();

    expect($person->photo_path)->not->toBeNull();
    Storage::disk('local')->assertExists($person->photo_path);
});

test('the create-person page renders the cropping file input and camera capture controls', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $html = $this->get('/people/create')->assertOk()->getContent();

    expect($html)->toContain('Crop photo')
        ->and($html)->toContain('Take a photo');
});

test('staging a photo on the create form shows its size before the person is saved', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $file = UploadedFile::fake()->image('photo.jpg', 400, 400)->size(120);

    $component = Volt::test('pages.people.create')->set('photo', $file);

    $expectedLabel = Number::fileSize($file->getSize(), precision: 1);

    $component->assertSee($expectedLabel);
});
