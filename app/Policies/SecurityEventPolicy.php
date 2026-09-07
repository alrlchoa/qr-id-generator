<?php

namespace App\Policies;

use App\Models\SecurityEvent;
use App\Models\User;

/**
 * Architecture §11 / CLAUDE.md rule 8: `security_events` is append-only,
 * written only by the system's own auth/authorization code paths, never
 * through a user-facing create/update/delete path. Viewing is a
 * Superadmin/Admin reporting concern.
 */
class SecurityEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperadmin() || $user->isAdmin();
    }

    public function view(User $user, SecurityEvent $securityEvent): bool
    {
        return $user->isSuperadmin() || $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SecurityEvent $securityEvent): bool
    {
        return false;
    }

    public function delete(User $user, SecurityEvent $securityEvent): bool
    {
        return false;
    }
}
