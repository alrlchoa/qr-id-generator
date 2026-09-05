<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Architecture §11 / CLAUDE.md rule 8: `audit_logs` is append-only, written
 * only by the system's own AuditLogger, never through a user-facing
 * create/update/delete path. Viewing is a Superadmin/Admin reporting concern.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperadmin() || $user->isAdmin();
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $user->isSuperadmin() || $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditLog $auditLog): bool
    {
        return false;
    }

    public function delete(User $user, AuditLog $auditLog): bool
    {
        return false;
    }
}
