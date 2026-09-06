<?php

use App\Enums\Role;
use App\Exceptions\SuperadminInvariantException;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\SystemBootstrap;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;

/**
 * First-run bootstrap (architecture §12).
 *
 * Note the absence of bootstrapSystem() in most of these: they are the
 * tests that want a virgin system, which is the default state under
 * RefreshDatabase.
 */
test('a fresh system serves the wizard and nothing else', function () {
    $this->get('/dashboard')->assertRedirect(route('setup'));
    $this->get('/profile')->assertRedirect(route('setup'));
    $this->get('/')->assertRedirect(route('setup'));
});

test('even the login page redirects to the wizard before bootstrap', function () {
    // There is no account to log in with yet, so a reachable login form is
    // a dead end that invites a support call.
    $this->get('/login')->assertRedirect(route('setup'));
});

test('the health endpoint stays reachable before bootstrap', function () {
    // Phase 2's deploy check hits /up before anyone opens a browser (§12).
    // Gating it would make a correct deployment look like a failed one.
    $this->get('/up')->assertOk();
});

test('the wizard itself is reachable before bootstrap', function () {
    $this->get('/setup')
        ->assertOk()
        ->assertSeeVolt('pages.setup.wizard');
});

test('the wizard creates two active Superadmins who can log in immediately', function () {
    Volt::test('pages.setup.wizard')
        ->set('first_username', 'ana')
        ->set('first_name', 'Ana Reyes')
        ->set('first_password', 'first-operator-password')
        ->set('first_password_confirmation', 'first-operator-password')
        ->set('second_username', 'ben')
        ->set('second_name', 'Ben Cruz')
        ->set('second_password', 'second-operator-password')
        ->set('second_password_confirmation', 'second-operator-password')
        ->call('bootstrapSystem')
        ->assertHasNoErrors()
        ->assertRedirect(route('login', absolute: false));

    $superadmins = User::where('role', Role::Superadmin)->get();

    expect($superadmins)->toHaveCount(2)
        ->and($superadmins->pluck('username')->all())->toEqualCanonicalizing(['ana', 'ben'])
        ->and($superadmins->every(fn (User $u) => $u->is_active))->toBeTrue();

    // The operator chose these passwords, so there is nothing to rotate
    // away from (§12).
    expect($superadmins->every(fn (User $u) => $u->must_change_password === false))->toBeTrue();

    expect(Hash::check('first-operator-password', $superadmins->firstWhere('username', 'ana')->password))->toBeTrue();
});

test('the wizard route refuses once the system is bootstrapped', function () {
    bootstrapSystem();

    $this->get('/setup')->assertNotFound();
});

test('an attempt to reach the wizard after bootstrap is recorded', function () {
    bootstrapSystem();

    $this->get('/setup')->assertNotFound();

    $event = SecurityEvent::where('event_type', 'setup_wizard_blocked')->first();

    expect($event)->not->toBeNull()
        ->and($event->detail['route'])->toBe('setup');
});

test('an ordinary route is unaffected once bootstrapped', function () {
    bootstrapSystem();

    $this->get('/login')->assertOk();
});

test('the service refuses to bootstrap a system that already has a Superadmin', function () {
    bootstrapSystem();

    $bootstrap = app(SystemBootstrap::class);

    expect(fn () => $bootstrap->bootstrap(
        ['username' => 'ana', 'name' => 'Ana Reyes', 'password' => 'a-password-here'],
        ['username' => 'ben', 'name' => 'Ben Cruz', 'password' => 'b-password-here'],
    ))->toThrow(SuperadminInvariantException::class);
});

test('a failed second account creates neither account', function () {
    // Both accounts land together or not at all (§12): stopping after one
    // is exactly the one-member tier the §11 invariant exists to prevent.
    User::factory()->create(['username' => 'taken', 'role' => Role::Admin]);

    $bootstrap = app(SystemBootstrap::class);

    try {
        $bootstrap->bootstrap(
            ['username' => 'ana', 'name' => 'Ana Reyes', 'password' => 'a-password-here'],
            ['username' => 'taken', 'name' => 'Ben Cruz', 'password' => 'b-password-here'],
        );
    } catch (Throwable) {
        // The duplicate username is what we are provoking.
    }

    expect(User::where('username', 'ana')->exists())->toBeFalse()
        ->and(User::where('role', Role::Superadmin)->count())->toBe(0);
});

test('the wizard rejects two accounts sharing one username', function () {
    Volt::test('pages.setup.wizard')
        ->set('first_username', 'same')
        ->set('first_name', 'Ana Reyes')
        ->set('first_password', 'first-operator-password')
        ->set('first_password_confirmation', 'first-operator-password')
        ->set('second_username', 'same')
        ->set('second_name', 'Ben Cruz')
        ->set('second_password', 'second-operator-password')
        ->set('second_password_confirmation', 'second-operator-password')
        ->call('bootstrapSystem')
        ->assertHasErrors('first_username');

    expect(User::count())->toBe(0);
});

test('the wizard requires each password to be confirmed', function () {
    Volt::test('pages.setup.wizard')
        ->set('first_username', 'ana')
        ->set('first_name', 'Ana Reyes')
        ->set('first_password', 'first-operator-password')
        ->set('first_password_confirmation', 'something-else-entirely')
        ->set('second_username', 'ben')
        ->set('second_name', 'Ben Cruz')
        ->set('second_password', 'second-operator-password')
        ->set('second_password_confirmation', 'second-operator-password')
        ->call('bootstrapSystem')
        ->assertHasErrors('first_password');

    expect(User::count())->toBe(0);
});

test('a system whose only Superadmins were disabled falls back to the wizard', function () {
    // The tier being empty is the same state break-glass addresses (§12);
    // refusing to help here would leave no route back into the system.
    User::factory()->superadmin()->inactive()->count(2)->create();

    $this->get('/dashboard')->assertRedirect(route('setup'));
    $this->get('/setup')->assertOk();
});
