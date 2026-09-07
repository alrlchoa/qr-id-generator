<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;

test('id:superadmin-create makes an active Superadmin that must change its password', function () {
    $this->artisan('id:superadmin-create', ['username' => 'new.super'])
        ->assertSuccessful();

    $user = User::where('username', 'new.super')->firstOrFail();

    expect($user->role)->toBe(Role::Superadmin)
        ->and($user->is_active)->toBeTrue()
        ->and($user->must_change_password)->toBeTrue();
});

test('id:superadmin-create writes a correctly-shaped console audit row', function () {
    $this->artisan('id:superadmin-create', ['username' => 'new.super'])
        ->assertSuccessful();

    $user = User::where('username', 'new.super')->firstOrFail();
    $log = AuditLog::where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)->firstOrFail();

    expect($log->action)->toBe('superadmin_created_via_console')
        ->and($log->user_id)->toBeNull()
        ->and($log->user_role)->toBe('console')
        ->and($log->ip_address)->toBeNull()
        ->and($log->new_value)->toHaveKeys(['username', 'os_user', 'hostname'])
        ->and($log->new_value['username'])->toBe('new.super')
        // The generated password is never logged, in any form.
        ->and($log->new_value)->not->toHaveKey('password');
});

test('id:superadmin-create refuses a duplicate username', function () {
    User::factory()->create(['username' => 'taken']);

    $this->artisan('id:superadmin-create', ['username' => 'taken'])
        ->assertFailed();
});

test('id:superadmin-reset rotates the password and forces a change', function () {
    $user = User::factory()->superadmin()->create(['must_change_password' => false]);
    $originalHash = $user->password;

    $this->artisan('id:superadmin-reset', ['username' => $user->username])
        ->assertSuccessful();

    $user->refresh();

    expect($user->password)->not->toBe($originalHash)
        ->and($user->must_change_password)->toBeTrue();
});

test('id:superadmin-reset writes a correctly-shaped console audit row', function () {
    $user = User::factory()->superadmin()->create(['must_change_password' => false]);

    $this->artisan('id:superadmin-reset', ['username' => $user->username])
        ->assertSuccessful();

    $log = AuditLog::where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)->firstOrFail();

    expect($log->action)->toBe('superadmin_password_reset_via_console')
        ->and($log->user_id)->toBeNull()
        ->and($log->user_role)->toBe('console')
        ->and($log->new_value)->toHaveKeys(['username', 'os_user', 'hostname'])
        ->and($log->new_value)->not->toHaveKey('password');
});

test('id:superadmin-reset refuses a username that is not a Superadmin', function () {
    $user = User::factory()->admin()->create();

    $this->artisan('id:superadmin-reset', ['username' => $user->username])
        ->assertFailed();
});

test('id:superadmin-list only lists Superadmin accounts', function () {
    $superadmin = User::factory()->superadmin()->create();
    User::factory()->admin()->create();

    $this->artisan('id:superadmin-list')
        ->assertSuccessful()
        ->expectsOutputToContain($superadmin->username);
});
