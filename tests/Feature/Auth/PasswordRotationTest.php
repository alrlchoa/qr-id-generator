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

test('submitting a new password clears the flag, logs out, and sends the user to login', function () {
    // No current_password field: arriving here already proves possession
    // of the account (see the component's own docblock), and the point of
    // logging out afterward is to confirm the new password actually works
    // by making the user prove it again at login.
    $user = User::factory()->create([
        'must_change_password' => true,
        'password' => Hash::make('old-password'),
    ]);

    $this->actingAs($user);

    Volt::test('pages.auth.change-password')
        ->set('password', 'a-brand-new-password')
        ->set('password_confirmation', 'a-brand-new-password')
        ->call('changePassword')
        ->assertRedirect(route('login', absolute: false));

    $user->refresh();

    expect($user->must_change_password)->toBeFalse()
        ->and(Hash::check('a-brand-new-password', $user->password))->toBeTrue();

    $this->assertGuest();
});

test('a success message is flashed for the login page to show', function () {
    $user = User::factory()->create(['must_change_password' => true]);

    $this->actingAs($user);

    Volt::test('pages.auth.change-password')
        ->set('password', 'a-brand-new-password')
        ->set('password_confirmation', 'a-brand-new-password')
        ->call('changePassword');

    expect(session('status'))->toBe('Your password has been changed. Sign in with your new password.');
});

test('the change-password form has no current-password field', function () {
    bootstrapSystem();

    $user = User::factory()->create(['must_change_password' => true]);

    $this->actingAs($user);

    $this->get('/change-password')->assertDontSee('current_password');
});

test('a user who does not need to change their password is not redirected', function () {
    bootstrapSystem();

    $user = User::factory()->create(['must_change_password' => false]);

    $this->actingAs($user);

    $response = $this->get('/dashboard');

    $response->assertOk();
});
