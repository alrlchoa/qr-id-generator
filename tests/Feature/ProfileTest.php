<?php

use App\Models\AuditLog;
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

test('changing your own display name writes one display_name_changed audit row', function () {
    // Until 2026-09-15 this form saved the model directly and wrote nothing
    // — the one account mutation Phase 4's audit retrofit missed.
    $user = User::factory()->create(['name' => 'Old Name']);

    $this->actingAs($user);

    Volt::test('profile.update-profile-information-form')
        ->set('name', 'New Name')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $log = AuditLog::where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)->sole();

    expect($log->action)->toBe('display_name_changed')
        ->and($log->user_id)->toBe($user->id)
        ->and($log->previous_value)->toBe(['name' => 'Old Name'])
        ->and($log->new_value)->toBe(['name' => 'New Name']);
});

test('saving an unchanged display name writes no audit row', function () {
    // Nothing changed, so nothing happened to record — the same reasoning
    // that keeps a refused mutation out of audit_logs.
    $user = User::factory()->create(['name' => 'Same Name']);

    $this->actingAs($user);

    Volt::test('profile.update-profile-information-form')
        ->set('name', 'Same Name')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect(AuditLog::where('action', 'display_name_changed')->count())->toBe(0);
});
