<?php

use App\Models\IdCard;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

test('the verify page is reachable by every role', function () {
    bootstrapSystem();

    foreach (['reader', 'admin', 'superadmin'] as $role) {
        $user = User::factory()->{$role}()->create();

        $this->actingAs($user)->get('/verify')->assertOk()->assertSeeVolt('pages.verify.index');
    }
});

test('Verify appears in the nav for every role', function () {
    bootstrapSystem();

    foreach (['reader', 'admin', 'superadmin'] as $role) {
        $user = User::factory()->{$role}()->create();

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        expect($html)->toContain('Verify');
    }
});

test('scanning a valid control number shows the card\'s current status and identifying info', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->reader()->create());

    $person = Person::factory()->create(['first_name' => 'Ada', 'middle_name' => null, 'last_name' => 'Lovelace']);
    $card = IdCard::factory()->create(['person_id' => $person->id, 'type' => 'owner', 'status' => 'active']);

    $component = Volt::test('pages.verify.index')->call('scan', $card->control_number);

    expect($component->get('result')['found'])->toBeTrue();
    expect($component->get('result')['status'])->toBe('active');
    expect($component->get('result')['person_name'])->toBe('Lovelace, Ada');
    $component->assertSee('Lovelace, Ada')->assertSee('Active');
});

test('a non-active card displays its true status unmistakably', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->reader()->create());

    $card = IdCard::factory()->create(['status' => 'revoked']);

    $component = Volt::test('pages.verify.index')->call('scan', $card->control_number);

    expect($component->get('result')['status'])->toBe('revoked');
    $component->assertSee('not active');
});

test('an unresolvable control number shows a clean miss, not an error', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->reader()->create());

    $component = Volt::test('pages.verify.index')->call('scan', '00000000');

    expect($component->get('result')['found'])->toBeFalse();
    $component->assertSee('No card found');
});

test('manual entry reaches the same verify path as a scan', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->reader()->create());

    $card = IdCard::factory()->create(['status' => 'active']);

    Volt::test('pages.verify.index')
        ->set('manualControlNumber', $card->control_number)
        ->call('verifyManual')
        ->assertHasNoErrors();
});

test('the full guardhouse flow: a Reader scans, sees the photo, and is refused it 61 seconds later — Phase 10\'s own "done when"', function () {
    Storage::fake('local');
    bootstrapSystem();
    $reader = User::factory()->reader()->create();
    $this->actingAs($reader);

    $person = Person::factory()->create();
    Storage::disk('local')->put($person->photo_path, 'fake-image-bytes');
    $card = IdCard::factory()->create(['person_id' => $person->id, 'status' => 'active']);

    Volt::test('pages.verify.index')->call('scan', $card->control_number);

    $this->get(route('people.photo', $person))->assertOk();

    $this->travel(61)->seconds();

    $this->get(route('people.photo', $person))->assertForbidden();
});
