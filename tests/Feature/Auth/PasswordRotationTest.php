<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;

test('a user who must change their password is redirected there from any route', function () {
    bootstrapSystem();

    $user = User::factory()->create(['must_change_password' => true]);

    $this->actingAs($user);

    $response = $this->get('/dashboard');

    $response->assertRedirect(route('password.change'));
});

test('the change-password page itself is reachable while the flag is set', function () {
    bootstrapSystem();

    $user = User::factory()->create(['must_change_password' => true]);

    $this->actingAs($user);

    $response = $this->get('/change-password');

    $response->assertOk();
});

test('submitting a new password clears the flag and unblocks the app', function () {
    $user = User::factory()->create([
        'must_change_password' => true,
        'password' => Hash::make('old-password'),
    ]);

    $this->actingAs($user);

    Volt::test('pages.auth.change-password')
        ->set('current_password', 'old-password')
        ->set('password', 'a-brand-new-password')
        ->set('password_confirmation', 'a-brand-new-password')
        ->call('changePassword')
        ->assertRedirect(route('dashboard', absolute: false));

    $user->refresh();

    expect($user->must_change_password)->toBeFalse()
        ->and(Hash::check('a-brand-new-password', $user->password))->toBeTrue();
});

test('a user who does not need to change their password is not redirected', function () {
    bootstrapSystem();

    $user = User::factory()->create(['must_change_password' => false]);

    $this->actingAs($user);

    $response = $this->get('/dashboard');

    $response->assertOk();
});
