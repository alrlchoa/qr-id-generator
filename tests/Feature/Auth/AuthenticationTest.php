<?php

use App\Models\User;
use Livewire\Volt\Volt;

test('login screen can be rendered', function () {
    bootstrapSystem();

    $response = $this->get('/login');

    $response
        ->assertOk()
        ->assertSeeVolt('pages.auth.login');
});

test('the login password field has a show/hide toggle', function () {
    // Same inline pattern as the wizard (see FirstRunWizardTest): no
    // extracted component, no SVG pair, a plain text label — the wizard's
    // password field disappeared once behind a more elaborate version of
    // this, and a static type="password" stays alongside x-bind:type so the
    // field is masked if Alpine never runs.
    bootstrapSystem();

    $html = $this->get('/login')->assertOk()->getContent();

    expect($html)->toContain('x-data="{ showPassword: false }"')
        ->and($html)->toContain("x-bind:type=\"showPassword ? 'text' : 'password'\"")
        ->and(substr_count($html, 'type="password"'))->toBeGreaterThanOrEqual(1)
        ->and(substr_count($html, 'type="button"'))->toBeGreaterThanOrEqual(1);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $component = Volt::test('pages.auth.login')
        ->set('form.username', $user->username)
        ->set('form.password', 'password');

    $component->call('login');

    $component
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $component = Volt::test('pages.auth.login')
        ->set('form.username', $user->username)
        ->set('form.password', 'wrong-password');

    $component->call('login');

    $component
        ->assertHasErrors()
        ->assertNoRedirect();

    expect($component->errors()->first('form.username'))
        ->toBe('Username/password credentials do not match.');

    // A failed attempt keeps what was typed — correcting one field beats
    // retyping both.
    $component->assertSet('form.username', $user->username);

    $this->assertGuest();
});

test('navigation menu can be rendered', function () {
    bootstrapSystem();

    $user = User::factory()->create();

    $this->actingAs($user);

    $response = $this->get('/dashboard');

    $response
        ->assertOk()
        ->assertSeeVolt('layout.navigation');
});

test('users can logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Volt::test('layout.navigation');

    $component->call('logout');

    $component
        ->assertHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
});
