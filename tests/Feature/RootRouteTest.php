<?php

use App\Models\User;

/**
 * No public landing page. The unbootstrapped case (redirect to /setup) is
 * already covered by FirstRunWizardTest — every route including this one
 * goes through EnsureSystemIsBootstrapped first, so it's the same behavior
 * here as everywhere else and not worth re-asserting.
 */
test('a guest visiting / is sent to login', function () {
    bootstrapSystem();

    $this->get('/')->assertRedirect(route('login'));
});

test('an authenticated user visiting / is sent to the dashboard', function () {
    bootstrapSystem();

    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get('/')->assertRedirect(route('dashboard'));
});
