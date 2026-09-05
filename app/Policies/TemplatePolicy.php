<?php

namespace App\Policies;

use App\Models\Template;
use App\Models\User;

/**
 * Architecture §11: card templates are a Superadmin-only concern.
 */
class TemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function view(User $user, Template $template): bool
    {
        return $user->isSuperadmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function update(User $user, Template $template): bool
    {
        return $user->isSuperadmin();
    }

    public function delete(User $user, Template $template): bool
    {
        return $user->isSuperadmin();
    }
}
