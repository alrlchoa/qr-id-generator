<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;

test('a Reader cannot view the users page', function () {
    bootstrapSystem();

    $reader = User::factory()->reader()->create();

    $this->actingAs($reader);

    $this->get('/users')->assertForbidden();
});

test('an Admin cannot view the users page', function () {
    bootstrapSystem();

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    $this->get('/users')->assertForbidden();
});

test('a Superadmin can view the users page and create a third Superadmin', function () {
    $superadminA = User::factory()->superadmin()->create();
    User::factory()->superadmin()->create();

    $this->actingAs($superadminA);

    $this->get('/users')->assertOk()->assertSeeVolt('pages.users.index');

    Volt::test('pages.users.index')
        ->set('username', 'third.super')
        ->set('name', 'Third Super')
        ->set('role', Role::Superadmin->value)
        ->call('createAccount')
        ->assertHasNoErrors();

    $created = User::where('username', 'third.super')->firstOrFail();

    expect($created->role)->toBe(Role::Superadmin)
        ->and($created->must_change_password)->toBeTrue();
});

test('the GUI enforces the two-active-superadmin invariant, not just the service', function () {
    $superadminA = User::factory()->superadmin()->create();
    $superadminB = User::factory()->superadmin()->create();

    $this->actingAs($superadminA);

    Volt::test('pages.users.index')
        ->call('toggleActive', $superadminB->id)
        ->assertHasErrors('invariant');

    expect($superadminB->refresh()->is_active)->toBeTrue();
});

test('a superadmin cannot disable or change the role of their own row from the GUI', function () {
    $superadminA = User::factory()->superadmin()->create();
    User::factory()->superadmin()->create();

    $this->actingAs($superadminA);

    Volt::test('pages.users.index')
        ->call('toggleActive', $superadminA->id)
        ->assertHasErrors('invariant');

    expect($superadminA->refresh()->is_active)->toBeTrue();
});

test('a Superadmin can reset another account\'s password from the Users screen', function () {
    // The only path a password changes other than the mandatory rotation it
    // forces (CLAUDE.md) — this is that path.
    $superadminA = User::factory()->superadmin()->create();
    $admin = User::factory()->admin()->create([
        'password' => Hash::make('old-password'),
        'must_change_password' => false,
    ]);

    $this->actingAs($superadminA);

    Volt::test('pages.users.index')
        ->call('resetPassword', $admin->id)
        ->assertHasNoErrors()
        ->assertSet('generatedForUsername', $admin->username);

    $admin->refresh();

    expect($admin->must_change_password)->toBeTrue()
        ->and(Hash::check('old-password', $admin->password))->toBeFalse();
});

test('a Superadmin can reset their own password from the Users screen', function () {
    // Unlike disable/role-change, this is not the self-lockout path rule 23
    // guards against — architecture §11 already treats one Superadmin
    // handling another's (or their own) password as ordinary.
    $superadminA = User::factory()->superadmin()->create();
    User::factory()->superadmin()->create();

    $this->actingAs($superadminA);

    Volt::test('pages.users.index')
        ->call('resetPassword', $superadminA->id)
        ->assertHasNoErrors();

    expect($superadminA->refresh()->must_change_password)->toBeTrue();
});

test('an Admin cannot reach the reset-password action at all', function () {
    // mount() authorizes viewAny before any action is reachable, so an
    // Admin never gets as far as calling resetPassword() — the page itself
    // is the boundary, matching 'an Admin cannot view the users page' above.
    //
    // Volt::test() does not let the mount()-time AuthorizationException
    // propagate as a raw PHP exception the way a plain method call would —
    // it converts it into a forbidden-status component response, the same
    // as the full HTTP path does for $this->get('/users')->assertForbidden().
    // Confirmed directly: wrapping this in try/catch and asserting on
    // get_class($e) never observed AuthorizationException reach the test.
    $admin = User::factory()->admin()->create();
    User::factory()->reader()->create();

    $this->actingAs($admin);

    Volt::test('pages.users.index')->assertForbidden();
});
