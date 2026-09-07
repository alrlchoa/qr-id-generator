<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
