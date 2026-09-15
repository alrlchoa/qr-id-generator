<?php

namespace App\Policies;

use App\Models\Unit;
use App\Models\User;

/**
 * Architecture §11: units are CRUD by Superadmin/Admin only; deletion is
 * Superadmin-only and guarded at the model layer against active occupants
 * (rule 9).
 */
class UnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperadmin() || $user->isAdmin();
    }

    public function view(User $user, Unit $unit): bool
    {
        return $user->isSuperadmin() || $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperadmin() || $user->isAdmin();
    }

    public function update(User $user, Unit $unit): bool
    {
        return $user->isSuperadmin() || $user->isAdmin();
    }

    public function delete(User $user, Unit $unit): bool
    {
        return $user->isSuperadmin();
    }

    /**
     * Recovery-only (Phase 13, architecture §15) — fixing a unit that has
     * reached zero active primary owners through an unsanctioned write.
     * Superadmin-only, not the Admin-reachable `update` promotion/transfer
     * already use: this repairs data corruption, the same trust tier as
     * `delete`, not routine reassignment.
     */
    public function designatePrimaryOwner(User $user, Unit $unit): bool
    {
        return $user->isSuperadmin();
    }
}
