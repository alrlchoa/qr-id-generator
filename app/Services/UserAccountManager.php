<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\SuperadminInvariantException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Account management for the Superadmin tier (architecture §11). Every
 * mutation that could shrink the tier below two active Superadmins runs
 * inside a transaction with the Superadmin rows locked, so two admins
 * acting at the same instant can't each pass a stale count check.
 *
 * Every mutation also writes through AuditLogger (Phase 4) — the actor is
 * always the authenticated Superadmin performing it; there is no console or
 * wizard path through this class (those write their own rows directly,
 * since neither has an authenticated actor to attribute to).
 */
class UserAccountManager
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Create a new account. Returns the generated password — the caller is
     * responsible for showing it exactly once (never persisted in plain
     * text, never logged).
     *
     * @return array{user: User, password: string}
     */
    public function createAccount(User $actor, string $username, string $name, Role $role, ?int $personId = null): array
    {
        $password = Str::password(20);

        $user = User::create([
            'username' => $username,
            'name' => $name,
            'password' => Hash::make($password),
            'role' => $role,
            'person_id' => $personId,
            'must_change_password' => true,
            'is_active' => true,
        ]);

        $this->auditLogger->log(
            actor: $actor,
            action: $role === Role::Superadmin ? 'superadmin_created' : 'account_created',
            subject: $user,
            newValue: ['username' => $user->username, 'role' => $role->value],
        );

        return ['user' => $user, 'password' => $password];
    }

    /**
     * Reset an account's password. The only path a password changes other
     * than the mandatory rotation it forces (CLAUDE.md rule 40) —
     * self-service, current-password-known changes were deliberately
     * removed rather than kept alongside this one.
     *
     * Not guarded against acting on one's own account — unlike disable() and
     * changeRole(), a Superadmin resetting their own forgotten password is
     * ordinary, not the accidental-lockout path rule 24 exists to prevent.
     * "Superadmins can impersonate each other" (architecture §11) already
     * establishes that A resetting B's password is expected, auditable
     * behaviour, not a privilege escalation to guard against.
     *
     * @return array{user: User, password: string}
     */
    public function resetPassword(User $actor, User $target): array
    {
        $password = Str::password(20);

        $target->forceFill([
            'password' => Hash::make($password),
            'must_change_password' => true,
        ])->save();

        $this->auditLogger->log(
            actor: $actor,
            action: $target->role === Role::Superadmin ? 'superadmin_password_reset' : 'password_reset',
            subject: $target,
            newValue: ['username' => $target->username],
        );

        return ['user' => $target, 'password' => $password];
    }

    public function disable(User $actor, User $target): void
    {
        $this->guardSelfAction($actor, $target);

        DB::transaction(function () use ($actor, $target) {
            $this->assertInvariantHolds($target, wouldRemainSuperadmin: false);

            $target->forceFill(['is_active' => false])->save();

            $this->auditLogger->log(
                actor: $actor,
                action: $target->role === Role::Superadmin ? 'superadmin_disabled' : 'account_disabled',
                subject: $target,
                previousValue: ['is_active' => true],
                newValue: ['is_active' => false],
            );
        });
    }

    public function enable(User $actor, User $target): void
    {
        $target->forceFill(['is_active' => true])->save();

        $this->auditLogger->log(
            actor: $actor,
            action: 'account_enabled',
            subject: $target,
            previousValue: ['is_active' => false],
            newValue: ['is_active' => true],
        );
    }

    public function changeRole(User $actor, User $target, Role $role): void
    {
        $this->guardSelfAction($actor, $target);

        DB::transaction(function () use ($actor, $target, $role) {
            $this->assertInvariantHolds($target, wouldRemainSuperadmin: $role === Role::Superadmin);

            $previousRole = $target->role;

            $target->forceFill(['role' => $role])->save();

            $this->auditLogger->log(
                actor: $actor,
                action: 'role_changed',
                subject: $target,
                previousValue: ['role' => $previousRole->value],
                newValue: ['role' => $role->value],
            );
        });
    }

    private function guardSelfAction(User $actor, User $target): void
    {
        if ($actor->id === $target->id) {
            throw new SuperadminInvariantException(
                'A Superadmin cannot change their own role or disable their own account.'
            );
        }
    }

    /**
     * Lock every active Superadmin row and refuse the change if $target is
     * one of them and the change would not leave them a Superadmin.
     */
    private function assertInvariantHolds(User $target, bool $wouldRemainSuperadmin): void
    {
        $activeSuperadmins = User::where('role', Role::Superadmin)
            ->where('is_active', true)
            ->lockForUpdate()
            ->get();

        $targetIsCountedActiveSuperadmin = $activeSuperadmins->contains('id', $target->id);

        if ($targetIsCountedActiveSuperadmin && ! $wouldRemainSuperadmin && $activeSuperadmins->count() <= 2) {
            throw new SuperadminInvariantException(
                'At least two active Superadmins are required at all times.'
            );
        }
    }
}
