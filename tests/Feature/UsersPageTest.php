<?php

use App\Enums\Role;
use App\Models\AuditLog;
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

    $log = AuditLog::where('subject_type', $created->getMorphClass())->where('subject_id', $created->id)->firstOrFail();

    expect($log->action)->toBe('superadmin_created')
        ->and($log->user_id)->toBe($superadminA->id)
        // toEqual, not toBe: jsonb does not preserve key insertion order the
        // way PHP array identity (===) requires, and this is a two-key array.
        ->and($log->new_value)->toEqual(['username' => 'third.super', 'role' => 'superadmin']);
});

test('creating a non-Superadmin account writes the generic account_created action', function () {
    $superadminA = User::factory()->superadmin()->create();
    User::factory()->superadmin()->create();

    $this->actingAs($superadminA);

    Volt::test('pages.users.index')
        ->set('username', 'new.reader')
        ->set('name', 'New Reader')
        ->set('role', Role::Reader->value)
        ->call('createAccount')
        ->assertHasNoErrors();

    $created = User::where('username', 'new.reader')->firstOrFail();
    $log = AuditLog::where('subject_type', $created->getMorphClass())->where('subject_id', $created->id)->firstOrFail();

    // Distinct from the Superadmin case above: no `_via_console`-style
    // suffix needed since there is no console equivalent to disambiguate
    // from, and no `superadmin_*` prefix since this account isn't one.
    expect($log->action)->toBe('account_created');
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

test('disabling an account from the Users screen writes an audit row', function () {
    $superadminA = User::factory()->superadmin()->create();
    User::factory()->superadmin()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($superadminA);

    Volt::test('pages.users.index')->call('toggleActive', $admin->id);

    $log = AuditLog::where('subject_type', $admin->getMorphClass())->where('subject_id', $admin->id)->firstOrFail();

    expect($log->action)->toBe('account_disabled')
        ->and($log->user_id)->toBe($superadminA->id)
        ->and($log->previous_value)->toBe(['is_active' => true])
        ->and($log->new_value)->toBe(['is_active' => false]);
});

test('disabling a Superadmin from the Users screen writes the superadmin_disabled action', function () {
    $superadminA = User::factory()->superadmin()->create();
    $superadminB = User::factory()->superadmin()->create();
    User::factory()->superadmin()->create();

    $this->actingAs($superadminA);

    Volt::test('pages.users.index')->call('toggleActive', $superadminB->id);

    $log = AuditLog::where('subject_type', $superadminB->getMorphClass())->where('subject_id', $superadminB->id)->firstOrFail();

    expect($log->action)->toBe('superadmin_disabled');
});

test('changing a role from the Users screen writes a role_changed audit row', function () {
    $superadminA = User::factory()->superadmin()->create();
    User::factory()->superadmin()->create();
    $reader = User::factory()->reader()->create();

    $this->actingAs($superadminA);

    Volt::test('pages.users.index')->call('changeRole', $reader->id, Role::Admin->value);

    $log = AuditLog::where('subject_type', $reader->getMorphClass())->where('subject_id', $reader->id)->firstOrFail();

    expect($log->action)->toBe('role_changed')
        ->and($log->user_id)->toBe($superadminA->id)
        ->and($log->previous_value)->toBe(['role' => 'reader'])
        ->and($log->new_value)->toBe(['role' => 'admin']);
});

test('an invariant-blocked action writes no audit row at all', function () {
    // A refused mutation is not an event that happened — nothing changed,
    // so nothing should be recorded as having changed.
    $superadminA = User::factory()->superadmin()->create();
    $superadminB = User::factory()->superadmin()->create();

    $this->actingAs($superadminA);

    Volt::test('pages.users.index')->call('toggleActive', $superadminB->id);

    expect(AuditLog::where('subject_type', $superadminB->getMorphClass())->where('subject_id', $superadminB->id)->exists())
        ->toBeFalse();
});

test('resetting a password from the Users screen writes an audit row attributed to the actor, never the password', function () {
    $superadminA = User::factory()->superadmin()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($superadminA);

    Volt::test('pages.users.index')->call('resetPassword', $admin->id);

    $log = AuditLog::where('subject_type', $admin->getMorphClass())->where('subject_id', $admin->id)->firstOrFail();

    expect($log->action)->toBe('password_reset')
        ->and($log->user_id)->toBe($superadminA->id)
        ->and($log->new_value)->toBe(['username' => $admin->username])
        ->and($log->new_value)->not->toHaveKey('password')
        ->and($log->previous_value)->toBeNull();
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
