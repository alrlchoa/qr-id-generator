<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
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

test('saving a staged photo crops, compresses, stores it on the private local disk, and logs photo_updated', function () {
    Storage::fake('local');
    bootstrapSystem();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $person = Person::factory()->minimal()->create();

    Volt::test('pages.people.show', ['person' => $person])
        ->set('photo', UploadedFile::fake()->image('photo.jpg', 800, 600))
        ->call('save')
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
        ->call('save');

    $firstPath = $person->refresh()->photo_path;
    Storage::disk('local')->assertExists($firstPath);

    $component->set('photo', UploadedFile::fake()->image('second.jpg', 800, 600))
        ->call('save');

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
        ->call('save')
        ->assertHasErrors('photo');

    expect($person->refresh()->photo_path)->toBeNull();
});

test('the person show page displays the stored photo\'s actual on-disk size', function () {
    Storage::fake('local');
    bootstrapSystem();

    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->minimal()->create();

    $component = Volt::test('pages.people.show', ['person' => $person])
        ->set('photo', UploadedFile::fake()->image('photo.jpg', 800, 600))
        ->call('save');

    $person->refresh();
    $expectedBytes = Storage::disk('local')->size($person->photo_path);
    $expectedLabel = Number::fileSize($expectedBytes, precision: 1);

    $component->assertSee($expectedLabel);
});

test('the person show page offers taking a new photo, not just uploading a file', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->create();

    $this->get(route('people.show', $person))
        ->assertOk()
        ->assertSee('Take a photo');
});

test('clearing a staged photo on the show page returns to the picker without uploading', function () {
    Storage::fake('local');
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->minimal()->create();

    Volt::test('pages.people.show', ['person' => $person])
        ->set('photo', UploadedFile::fake()->image('photo.jpg', 400, 400))
        ->call('clearStagedPhoto')
        ->assertSet('photo', null);

    expect($person->fresh()->photo_path)->toBeNull();
});

test('there is no separate photo-upload action — a staged photo only takes effect on save, alongside every other field', function () {
    Storage::fake('local');
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->minimal()->create(['first_name' => 'Original']);

    Volt::test('pages.people.show', ['person' => $person])
        ->set('first_name', 'Updated')
        ->set('photo', UploadedFile::fake()->image('photo.jpg', 400, 400))
        ->call('save')
        ->assertHasNoErrors();

    $person->refresh();

    expect($person->first_name)->toBe('Updated');
    expect($person->photo_path)->not->toBeNull();

    expect(AuditLog::where('subject_id', $person->id)->where('action', 'person_data_updated')->exists())->toBeTrue();
    expect(AuditLog::where('subject_id', $person->id)->where('action', 'photo_updated')->exists())->toBeTrue();
});

test('the old separate uploadPhoto action no longer exists on the show page', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->minimal()->create();

    expect(fn () => Volt::test('pages.people.show', ['person' => $person])->call('uploadPhoto'))
        ->toThrow(Exception::class);
});

test('resetForm discards unsaved field edits and any staged photo, reverting to what the server has', function () {
    Storage::fake('local');
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->minimal()->create(['first_name' => 'Saved', 'last_name' => 'Value']);

    Volt::test('pages.people.show', ['person' => $person])
        ->set('first_name', 'Unsaved Edit')
        ->set('photo', UploadedFile::fake()->image('photo.jpg', 400, 400))
        ->call('resetForm')
        ->assertSet('first_name', 'Saved')
        ->assertSet('photo', null);

    expect($person->fresh()->first_name)->toBe('Saved');
    expect($person->fresh()->photo_path)->toBeNull();
});

test('the stored photo\'s URL changes when the photo is replaced, so the browser can\'t serve a stale cached image', function () {
    Storage::fake('local');
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->minimal()->create();

    $html1 = Volt::test('pages.people.show', ['person' => $person])
        ->set('photo', UploadedFile::fake()->image('first.jpg', 400, 400))
        ->call('save')
        ->html();

    preg_match('/people\/\d+\/photo\?v=(\d+)/', $html1, $match1);

    // A real clock tick between saves so the cache-busting value actually differs.
    $person->refresh();
    sleep(1);

    $html2 = Volt::test('pages.people.show', ['person' => $person])
        ->set('photo', UploadedFile::fake()->image('second.jpg', 400, 400))
        ->call('save')
        ->html();

    preg_match('/people\/\d+\/photo\?v=(\d+)/', $html2, $match2);

    expect($match1[1] ?? null)->not->toBeNull();
    expect($match2[1] ?? null)->not->toBeNull();
    expect($match2[1])->not->toBe($match1[1]);
});
