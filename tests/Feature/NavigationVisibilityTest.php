<?php

use App\Models\User;

/**
 * Regression coverage for the Phase 5 nav-item refactor
 * (resources/views/components/nav-item.blade.php): the role-gating logic
 * moved out of four near-duplicate blocks into one shared component, and
 * this proves the visibility rules survived the move. Hiding a link is UX
 * only — the Policy on each route is the real boundary, already covered by
 * UsersPageTest/AuditViewerTest — this file is specifically about what
 * renders in the nav, not what a role can reach.
 *
 * Plain-text matches, not '>Label<': <x-nav-link>'s <a> tag puts its slot
 * on its own indented line (>\n    Label\n</a>), not flush against the
 * angle brackets, so a bracket-anchored match fails on real markup even
 * when the label is genuinely there.
 */
test('a Reader sees neither Users nor Audit Log in the nav', function () {
    bootstrapSystem();

    $reader = User::factory()->reader()->create();

    $this->actingAs($reader);

    $html = $this->get('/dashboard')->assertOk()->getContent();

    expect($html)->not->toContain('Users')
        ->and($html)->not->toContain('Audit Log');
});

test('an Admin sees Audit Log but not Users in the nav', function () {
    bootstrapSystem();

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    $html = $this->get('/dashboard')->assertOk()->getContent();

    expect($html)->toContain('Audit Log')
        ->and($html)->not->toContain('Users');
});

test('a Superadmin sees both Users and Audit Log in the nav', function () {
    $superadmin = User::factory()->superadmin()->create();
    User::factory()->superadmin()->create();

    $this->actingAs($superadmin);

    $html = $this->get('/dashboard')->assertOk()->getContent();

    expect($html)->toContain('Users')
        ->and($html)->toContain('Audit Log');
});

test('Dashboard is visible to every role', function () {
    bootstrapSystem();

    foreach (['reader', 'admin', 'superadmin'] as $role) {
        $user = User::factory()->{$role}()->create();

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        expect($html)->toContain('Dashboard');
    }
});
