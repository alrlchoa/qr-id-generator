<?php

namespace App\Policies;

use App\Models\PersonUnitRelationship;
use App\Models\User;

/**
 * Architecture §11: relationships (person-unit assignments) are managed by
 * Superadmin/Admin only. Ending a relationship is an update (`ended_at` set),
 * never a delete — rows are never removed once a relationship existed.
 */
class PersonUnitRelationshipPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperadmin() || $user->isAdmin();
    }

    public function view(User $user, PersonUnitRelationship $relationship): bool
    {
        return $user->isSuperadmin() || $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperadmin() || $user->isAdmin();
    }

    public function update(User $user, PersonUnitRelationship $relationship): bool
    {
        return $user->isSuperadmin() || $user->isAdmin();
    }

    public function delete(User $user, PersonUnitRelationship $relationship): bool
    {
        return false;
    }
}
