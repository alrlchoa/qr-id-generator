<?php

use App\Models\SecurityEvent;
use App\Models\User;
use Livewire\Volt\Volt;

test('an inactive user cannot authenticate even with the right password', function () {
    $user = User::factory()->create(['is_active' => false]);

    $component = Volt::test('pages.auth.login')
        ->set('form.username', $user->username)
        ->set('form.password', 'password');

    $component->call('login');

    $component->assertHasErrors();

    $this->assertGuest();
});

test('a failed login attempt writes a security_events row', function () {
    $user = User::factory()->create();

    Volt::test('pages.auth.login')
        ->set('form.username', $user->username)
        ->set('form.password', 'wrong-password')
        ->call('login');

    expect(SecurityEvent::where('user_id', $user->id)->where('event_type', 'login_failed')->count())
        ->toBe(1);
});

test('a successful login is never written to security_events', function () {
    $user = User::factory()->create();

    Volt::test('pages.auth.login')
        ->set('form.username', $user->username)
        ->set('form.password', 'password')
        ->call('login');

    expect(SecurityEvent::where('user_id', $user->id)->count())->toBe(0);
});

test('three consecutive failed logins prompt the user to contact a Superadmin, with no lockout', function () {
    $user = User::factory()->create();

    $attempt = fn () => Volt::test('pages.auth.login')
        ->set('form.username', $user->username)
        ->set('form.password', 'wrong-password')
        ->call('login');

    $attempt()->assertHasErrors();
    $attempt()->assertHasErrors();
    $third = $attempt();

    $third->assertHasErrors();
    expect($third->errors()->first('form.username'))->toContain('Superadmin');

    // No lockout: a correct password on the very next attempt still works.
    Volt::test('pages.auth.login')
        ->set('form.username', $user->username)
        ->set('form.password', 'password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});
