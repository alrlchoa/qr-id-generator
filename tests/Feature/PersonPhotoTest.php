<?php

use App\Models\AuditLog;
use App\Models\IdCard;
use App\Models\Person;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\CardVerificationService;
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

test('a Reader without any verification is refused a photo and the denial is logged', function () {
    bootstrapSystem();

    $person = Person::factory()->create();
    $reader = User::factory()->reader()->create();

    $this->actingAs($reader)->get(route('people.photo', $person))->assertForbidden();

    $event = SecurityEvent::where('event_type', 'authorization_denied')->where('user_id', $reader->id)->first();
    expect($event)->not->toBeNull();
    expect($event->detail['person_id'])->toBe($person->id);
});

test('a Reader may fetch a photo within 60 seconds of verifying that person\'s card', function () {
    Storage::fake('local');
    bootstrapSystem();

    $person = Person::factory()->create();
    Storage::disk('local')->put($person->photo_path, 'fake-image-bytes');
    $card = IdCard::factory()->create(['person_id' => $person->id, 'status' => 'active']);
    $reader = User::factory()->reader()->create();

    app(CardVerificationService::class)->verify($reader, $card->control_number);

    $this->actingAs($reader)->get(route('people.photo', $person))->assertOk();
});

test('a Reader is refused the same photo 61 seconds after verifying — architecture §9.2\'s window', function () {
    Storage::fake('local');
    bootstrapSystem();

    $person = Person::factory()->create();
    Storage::disk('local')->put($person->photo_path, 'fake-image-bytes');
    $card = IdCard::factory()->create(['person_id' => $person->id, 'status' => 'active']);
    $reader = User::factory()->reader()->create();

    app(CardVerificationService::class)->verify($reader, $card->control_number);

    $this->actingAs($reader)->get(route('people.photo', $person))->assertOk();

    $this->travel(61)->seconds();

    $this->actingAs($reader)->get(route('people.photo', $person))->assertForbidden();

    expect(SecurityEvent::where('event_type', 'authorization_denied')->where('user_id', $reader->id)->exists())->toBeTrue();
});

test('a Reader verifying one person never opens the photo window for a different person', function () {
    Storage::fake('local');
    bootstrapSystem();

    $verified = Person::factory()->create();
    $verifiedCard = IdCard::factory()->create(['person_id' => $verified->id, 'status' => 'active']);
    $other = Person::factory()->create();
    Storage::disk('local')->put($other->photo_path, 'fake-image-bytes');
    $reader = User::factory()->reader()->create();

    app(CardVerificationService::class)->verify($reader, $verifiedCard->control_number);

    $this->actingAs($reader)->get(route('people.photo', $other))->assertForbidden();
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

test('the stored photo keeps shrink-0 so a narrow flex row can\'t squash it off its 1:1 ratio', function () {
    Storage::fake('local');
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->create();

    $html = $this->get(route('people.show', $person))->assertOk()->getContent();

    // Both the fixed w-24 h-24 sizing AND shrink-0 have to be on the same
    // element — width/height alone isn't enough once it sits inside a
    // `flex` row with sibling content (buttons, text): without shrink-0 a
    // flex item's *width* can be compressed below its declared size while
    // its *height* class stays fixed, squashing a square photo into a
    // rectangle. This is exactly the bug that was reported on mobile.
    expect($html)->toContain('w-24 h-24 shrink-0 object-cover');
});

test('the camera preview keeps shrink-0 for the same reason', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $html = $this->get('/people/create')->assertOk()->getContent();

    expect($html)->toContain('w-48 h-48 shrink-0 object-cover');
});
