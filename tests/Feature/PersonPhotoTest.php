<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

test('a photo is unreachable without a session', function () {
    bootstrapSystem();

    $person = Person::factory()->create();

    $this->get(route('people.photo', $person))->assertRedirect(route('login'));
});

test('a photo is unreachable to a role the PersonPolicy refuses', function () {
    bootstrapSystem();

    $person = Person::factory()->create();
    $reader = User::factory()->reader()->create();

    $this->actingAs($reader)->get(route('people.photo', $person))->assertForbidden();
});

test('uploading a photo crops, compresses, stores it on the private local disk, and logs photo_updated', function () {
    Storage::fake('local');
    bootstrapSystem();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $person = Person::factory()->minimal()->create();

    Volt::test('pages.people.show', ['person' => $person])
        ->set('photo', UploadedFile::fake()->image('photo.jpg', 800, 600))
        ->call('uploadPhoto')
        ->assertHasNoErrors();

    $person->refresh();

    expect($person->photo_path)->not->toBeNull();
    Storage::disk('local')->assertExists($person->photo_path);
    expect($person->photo_path)->toStartWith('people-photos/');

    $log = AuditLog::where('subject_type', $person->getMorphClass())
        ->where('subject_id', $person->id)
        ->where('action', 'photo_updated')
        ->first();

    expect($log)->not->toBeNull();

    $this->get(route('people.photo', $person))->assertOk();
});

test('replacing a photo unlinks the previous file', function () {
    Storage::fake('local');
    bootstrapSystem();

    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->minimal()->create();

    $component = Volt::test('pages.people.show', ['person' => $person])
        ->set('photo', UploadedFile::fake()->image('first.jpg', 800, 600))
        ->call('uploadPhoto');

    $firstPath = $person->refresh()->photo_path;
    Storage::disk('local')->assertExists($firstPath);

    $component->set('photo', UploadedFile::fake()->image('second.jpg', 800, 600))
        ->call('uploadPhoto');

    $secondPath = $person->refresh()->photo_path;

    expect($secondPath)->not->toBe($firstPath);
    Storage::disk('local')->assertMissing($firstPath);
    Storage::disk('local')->assertExists($secondPath);
});

test('a photo over 1MB is rejected', function () {
    Storage::fake('local');
    bootstrapSystem();

    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->minimal()->create();

    Volt::test('pages.people.show', ['person' => $person])
        ->set('photo', UploadedFile::fake()->create('big.jpg', 1025, 'image/jpeg'))
        ->call('uploadPhoto')
        ->assertHasErrors('photo');

    expect($person->refresh()->photo_path)->toBeNull();
});
