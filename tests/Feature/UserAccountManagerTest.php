<?php

use App\Enums\Role;
use App\Exceptions\SuperadminInvariantException;
use App\Models\User;
use App\Services\UserAccountManager;
use Illuminate\Support\Facades\Hash;

test('createAccount generates a one-time password that forces rotation', function () {
    $actor = User::factory()->superadmin()->create();
    $manager = app(UserAccountManager::class);

    $result = $manager->createAccount($actor, 'new.reader', 'New Reader', Role::Reader);

    expect($result['user'])->toBeInstanceOf(User::class)
        ->and($result['user']->must_change_password)->toBeTrue()
        ->and($result['user']->is_active)->toBeTrue()
        ->and(Hash::check($result['password'], $result['user']->password))->toBeTrue();
});

test('disabling a superadmin is blocked when only two are active', function () {
    $superadminA = User::factory()->superadmin()->create();
    $superadminB = User::factory()->superadmin()->create();
    $actor = User::factory()->superadmin()->create(['is_active' => false]);

    $manager = app(UserAccountManager::class);

    expect(fn () => $manager->disable($actor, $superadminA))
        ->toThrow(SuperadminInvariantException::class);

    expect($superadminA->refresh()->is_active)->toBeTrue();
});

test('disabling a superadmin is allowed while a third active superadmin remains', function () {
    $superadminA = User::factory()->superadmin()->create();
    $superadminB = User::factory()->superadmin()->create();
    $actor = User::factory()->superadmin()->create();

    $manager = app(UserAccountManager::class);

    $manager->disable($actor, $superadminA);

    expect($superadminA->refresh()->is_active)->toBeFalse();

    // Now only two remain active (superadminB, actor) -- the next disable
    // must be blocked, proving the check re-reads state rather than using a
    // count captured before the first disable.
    expect(fn () => $manager->disable($actor, $superadminB))
        ->toThrow(SuperadminInvariantException::class);

    expect($superadminB->refresh()->is_active)->toBeTrue();
});

test('demoting a superadmin out of the role is blocked when only two are active', function () {
    $superadminA = User::factory()->superadmin()->create();
    $superadminB = User::factory()->superadmin()->create();
    $actor = User::factory()->superadmin()->create(['is_active' => false]);

    $manager = app(UserAccountManager::class);

    expect(fn () => $manager->changeRole($actor, $superadminA, Role::Admin))
        ->toThrow(SuperadminInvariantException::class);

    expect($superadminA->refresh()->role)->toBe(Role::Superadmin);
});

test('a superadmin cannot disable their own account', function () {
    $superadminA = User::factory()->superadmin()->create();
    User::factory()->superadmin()->create();

    $manager = app(UserAccountManager::class);

    expect(fn () => $manager->disable($superadminA, $superadminA))
        ->toThrow(SuperadminInvariantException::class);

    expect($superadminA->refresh()->is_active)->toBeTrue();
});

test('a superadmin cannot change their own role', function () {
    $superadminA = User::factory()->superadmin()->create();
    User::factory()->superadmin()->create();

    $manager = app(UserAccountManager::class);

    expect(fn () => $manager->changeRole($superadminA, $superadminA, Role::Admin))
        ->toThrow(SuperadminInvariantException::class);

    expect($superadminA->refresh()->role)->toBe(Role::Superadmin);
});

test('disabling a non-superadmin is never blocked by the invariant', function () {
    User::factory()->superadmin()->create();
    User::factory()->superadmin()->create();
    $admin = User::factory()->admin()->create();
    $actor = User::factory()->superadmin()->create();

    $manager = app(UserAccountManager::class);

    $manager->disable($actor, $admin);

    expect($admin->refresh()->is_active)->toBeFalse();
});
