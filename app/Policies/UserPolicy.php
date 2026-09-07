<?php

namespace App\Policies;

use App\Models\User;

/**
 * Account management (architecture §11: "Manage Admin/Reader accounts" and
 * "Manage Superadmin accounts" are both Superadmin-only, with no Admin
 * equivalent). This governs managing *other* accounts — a user's own
 * profile page is a separate, always-allowed route, not gated here.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function view(User $user, User $target): bool
    {
        return $user->isSuperadmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function update(User $user, User $target): bool
    {
        return $user->isSuperadmin();
    }

    public function delete(User $user, User $target): bool
    {
        return $user->isSuperadmin();
    }
}
