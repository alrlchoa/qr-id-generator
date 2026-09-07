<?php

use App\Models\User;
use Livewire\Volt\Volt;

test('profile page is displayed', function () {
    bootstrapSystem();

    $user = User::factory()->create();

    $this->actingAs($user);

    $response = $this->get('/profile');

    $response
        ->assertOk()
        ->assertSeeVolt('profile.update-profile-information-form');
});

test('the profile page has no self-service password change', function () {
    // Removed deliberately: a password now changes in exactly two ways
    // (CLAUDE.md) — the mandatory rotation, and a Superadmin reset from the
    // Users screen. A voluntary current-password-known change was a third
    // path this system no longer has.
    bootstrapSystem();

    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get('/profile')->assertDontSeeVolt('profile.update-password-form');
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Volt::test('profile.update-profile-information-form')
        ->set('name', 'Test User')
        ->call('updateProfileInformation');

    $component
        ->assertHasNoErrors()
        ->assertNoRedirect();

    $user->refresh();

    $this->assertSame('Test User', $user->name);
});
