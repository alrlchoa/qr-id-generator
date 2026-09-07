<?php

namespace App\Policies;

use App\Models\Person;
use App\Models\User;

/**
 * Architecture §11: person records are CRUD by Superadmin/Admin only;
 * soft-deletion is Superadmin-only and additionally guarded at the model
 * layer against records with active relationships or cards (§13, rule 9).
 */
class PersonPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperadmin() || $user->isAdmin();
    }

    public function view(User $user, Person $person): bool
    {
        return $user->isSuperadmin() || $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperadmin() || $user->isAdmin();
    }

    public function update(User $user, Person $person): bool
    {
        return $user->isSuperadmin() || $user->isAdmin();
    }

    public function delete(User $user, Person $person): bool
    {
        return $user->isSuperadmin();
    }
}
