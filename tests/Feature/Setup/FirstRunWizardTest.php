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
        ->assertHasErrors(['second_username' => 'different']);

    expect(User::count())->toBe(0);
});

test('typing does not trigger validation or clear the form', function () {
    // Regression: the duplicate-username check was once a server-side
    // updated() hook fired by wire:model.blur. Every other field here is a
    // deferred wire:model, so that round trip re-rendered them from server
    // state that had not been sent yet — filling in the first username
    // wiped the rest of the form. Setting properties must produce no errors
    // and disturb nothing; the live check now lives in Alpine, and the
    // `different:` rule enforces it on submit.
    Volt::test('pages.setup.wizard')
        ->set('first_username', 'same')
        ->set('second_username', 'same')
        ->set('first_name', 'Ana Reyes')
        ->assertHasNoErrors()
        ->assertSet('first_username', 'same')
        ->assertSet('second_username', 'same')
        ->assertSet('first_name', 'Ana Reyes');
});

test('the browser-side duplicate-username warning is rendered', function () {
    // Alpine cannot run here, so this asserts the mechanism is present
    // rather than its behaviour: the state, the comparison, and the message.
    $this->get('/setup')
        ->assertOk()
        ->assertSee('firstUsername', escape: false)
        ->assertSee('secondUsername !== \'\' && firstUsername === secondUsername', escape: false)
        ->assertSee('The two accounts must have different usernames.');
});

test('a mismatched confirmation reports against the confirmation field', function () {
    // Not against the password field: the message says "Confirm Password is
    // not the same", so it belongs under the input that is wrong.
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
        ->assertHasErrors(['first_password_confirmation' => 'same']);

    expect(User::count())->toBe(0);
});

test('a short password reports against the password field', function () {
    Volt::test('pages.setup.wizard')
        ->set('first_username', 'ana')
        ->set('first_name', 'Ana Reyes')
        ->set('first_password', 'short')
        ->set('first_password_confirmation', 'short')
        ->set('second_username', 'ben')
        ->set('second_name', 'Ben Cruz')
        ->set('second_password', 'second-operator-password')
        ->set('second_password_confirmation', 'second-operator-password')
        ->call('bootstrapSystem')
        ->assertHasErrors(['first_password' => 'min']);

    expect(User::count())->toBe(0);
});

test('a rejected submission keeps everything that was typed', function () {
    $component = Volt::test('pages.setup.wizard')
        ->set('first_username', 'ana')
        ->set('first_name', 'Ana Reyes')
        ->set('first_password', 'first-operator-password')
        ->set('first_password_confirmation', 'mismatched')
        ->set('second_username', 'ben')
        ->set('second_name', 'Ben Cruz')
        ->set('second_password', 'second-operator-password')
        ->set('second_password_confirmation', 'second-operator-password')
        ->call('bootstrapSystem')
        ->assertHasErrors();

    // Retyping eight fields because one confirmation was wrong is the
    // behaviour this asserts against.
    $component
        ->assertSet('first_username', 'ana')
        ->assertSet('first_name', 'Ana Reyes')
        ->assertSet('first_password', 'first-operator-password')
        ->assertSet('second_username', 'ben')
        ->assertSet('second_name', 'Ben Cruz')
        ->assertSet('second_password', 'second-operator-password');
});

test('losing the race to another operator is an error on the form, not a 500', function () {
    $component = Volt::test('pages.setup.wizard')
        ->set('first_username', 'ana')
        ->set('first_name', 'Ana Reyes')
        ->set('first_password', 'first-operator-password')
        ->set('first_password_confirmation', 'first-operator-password')
        ->set('second_username', 'ben')
        ->set('second_name', 'Ben Cruz')
        ->set('second_password', 'second-operator-password')
        ->set('second_password_confirmation', 'second-operator-password');

    // Someone else finishes the wizard between page load and submit.
    bootstrapSystem();

    $component->call('bootstrapSystem')
        ->assertHasErrors('first_username')
        ->assertSet('first_username', 'ana');
});

test('a system whose only Superadmins were disabled falls back to the wizard', function () {
    // The tier being empty is the same state break-glass addresses (§12);
    // refusing to help here would leave no route back into the system.
    User::factory()->superadmin()->inactive()->count(2)->create();

    $this->get('/dashboard')->assertRedirect(route('setup'));
    $this->get('/setup')->assertOk();
});
