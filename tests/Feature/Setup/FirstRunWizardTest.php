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

test('livewire endpoints stay reachable before bootstrap', function () {
    // The regression this exists for: the gate matched Livewire by route
    // name, and the names are not what you would guess — the update endpoint
    // is `default.livewire.update` and the asset route has none at all, so
    // `routeIs('livewire.*')` matched neither.
    //
    // Redirecting the script means the browser receives HTML where
    // JavaScript should be, Livewire never initialises, and wire:submit does
    // nothing. The wizard renders perfectly and cannot be submitted, which
    // is the hardest possible version of this bug to see.
    // Livewire itself decides the filename by config('app.debug') — .js
    // unminified in debug mode (true here, under testing), .min.js in
    // production. The middleware's exemption is a path wildcard
    // (is('livewire/*')), so it covers either; hardcoding one filename here
    // would only test the app's own config, not the gate.
    $asset = config('app.debug') ? '/livewire/livewire.js' : '/livewire/livewire.min.js';

    $this->get($asset)
        ->assertOk()
        ->assertHeader('content-type', 'application/javascript; charset=utf-8');
});

test('the wizard reaches its own livewire update endpoint before bootstrap', function () {
    $response = $this->post('/livewire/update', []);

    // Without a valid component payload Livewire rejects this, and the
    // rejection is the point: it proves the request reached Livewire rather
    // than being bounced to /setup by the gate. Asserting a specific status
    // would pin this to a Livewire implementation detail; asserting it was
    // not redirected to /setup is the actual contract.
    expect($response->isRedirect(route('setup')))->toBeFalse();
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

test('the browser-side checks are rendered', function () {
    // Alpine cannot run here, so this asserts the mechanism is present
    // rather than its behaviour: the three comparisons and their messages.
    $this->get('/setup')
        ->assertOk()
        ->assertSee('u2 !== \'\' && u1 === u2', escape: false)
        ->assertSee('p1.length < 8', escape: false)
        ->assertSee('c1 !== p1', escape: false)
        ->assertSee('The two accounts must have different usernames.')
        ->assertSee('Password is less than 8 characters long.')
        ->assertSee('Confirm Password is not the same.');
});

test('a rejected submit summarises every error above the form', function () {
    // A long form scrolls: an error under the second password can sit below
    // the fold while the button just pressed is in view, which reads as
    // "nothing happened" rather than "something is wrong".
    Volt::test('pages.setup.wizard')
        ->set('first_username', 'ana')
        ->set('first_name', 'Ana Reyes')
        ->set('first_password', 'short')
        ->set('first_password_confirmation', 'short')
        ->set('second_username', 'ben')
        ->set('second_name', 'Ben Cruz')
        ->set('second_password', 'also-short-x')
        ->set('second_password_confirmation', 'mismatch')
        ->call('bootstrapSystem')
        ->assertHasErrors()
        ->assertSee('The accounts were not created:')
        ->assertSee('Password is less than 8 characters long.')
        ->assertSee('Confirm Password is not the same.');
});

test('every password field has a reveal toggle that cannot submit the form', function () {
    $html = $this->get('/setup')->assertOk()->getContent();

    // Four fields, four independent toggles.
    foreach (['s1', 'sc1', 's2', 'sc2'] as $toggle) {
        expect($html)->toContain("x-bind:type=\"{$toggle} ? 'text' : 'password'\"");
    }

    // type="button" matters: the default inside a form is submit, so a
    // reveal toggle without it would create the accounts instead of showing
    // the password. And the static type="password" must survive alongside
    // x-bind:type, so the field is still masked if Alpine never runs — a
    // reveal that fails open is worse than none.
    expect(substr_count($html, 'type="button"'))->toBeGreaterThanOrEqual(4)
        ->and(substr_count($html, 'type="password"'))->toBeGreaterThanOrEqual(4);
});

test('wire:model inputs carry no value attribute', function () {
    // Regression: `value="..."` on a wire:model input fights Livewire for
    // ownership of the value. An input reset to empty while the typed value
    // lives only in Livewire's state makes the browser's own `required`
    // check block submission — no request, no error, nothing in the log,
    // which is exactly what a broken app looks like.
    $html = $this->get('/setup')->assertOk()->getContent();

    expect($html)->not->toContain('value="{{')
        ->and(preg_match('/wire:model="first_username"[^>]*value=/', $html))->toBe(0)
        ->and(preg_match('/wire:model="second_username"[^>]*value=/', $html))->toBe(0);
});

test('the submit button is never disabled by client-side state', function () {
    // Regression: disabling it while an Alpine check held produced a form
    // that silently did nothing on click, indistinguishable from a broken
    // app — and unrecoverable if the client-side state was ever wrong.
    // Client-side checks advise; the server decides.
    $this->get('/setup')
        ->assertOk()
        ->assertDontSee('x-bind:disabled', escape: false);
});

test('a rejected submit keeps the typed values in component state', function () {
    // Livewire restores wire:model inputs from this state on re-render.
    // An earlier version also wrote value="..." into the markup as a belt
    // and braces; that fought Livewire and broke submission outright, so
    // the framework's own binding is the single mechanism now.
    Volt::test('pages.setup.wizard')
        ->set('first_username', 'ana')
        ->set('first_name', 'Ana Reyes')
        ->set('first_password', 'first-operator-password')
        ->set('first_password_confirmation', 'mismatched')
        ->set('second_username', 'ben')
        ->set('second_name', 'Ben Cruz')
        ->set('second_password', 'second-operator-password')
        ->set('second_password_confirmation', 'second-operator-password')
        ->call('bootstrapSystem')
        ->assertHasErrors('first_password_confirmation')
        ->assertSet('first_username', 'ana')
        ->assertSet('first_name', 'Ana Reyes')
        ->assertSet('first_password', 'first-operator-password')
        ->assertSet('second_username', 'ben')
        ->assertSet('second_name', 'Ben Cruz')
        ->assertSet('second_password', 'second-operator-password')
        ->assertSee('Confirm Password is not the same.');
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
