<?php

namespace App\Policies;

use App\Models\Font;
use App\Models\User;

/**
 * Architecture §11: card fonts are a Superadmin-only concern, the same
 * tier as template management — this is rendering configuration, not
 * ordinary content.
 */
class FontPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function view(User $user, Font $font): bool
    {
        return $user->isSuperadmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function update(User $user, Font $font): bool
    {
        return $user->isSuperadmin();
    }

    public function delete(User $user, Font $font): bool
    {
        return $user->isSuperadmin();
    }
}
