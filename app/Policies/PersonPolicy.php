<?php

namespace App\Policies;

use App\Models\Person;
use App\Models\User;
use App\Support\ReaderVerificationSession;

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

    /**
     * Narrower than, and independent of, `view()` above — a Reader never
     * gets the Person record itself (the People index/show screens stay
     * Superadmin/Admin-only), only a 60-second photo window earned by
     * verifying this exact person (architecture §9.2, CLAUDE.md rule 23).
     * `PersonPhotoController` is the only caller.
     */
    public function viewPhoto(User $user, Person $person): bool
    {
        if ($user->isSuperadmin() || $user->isAdmin()) {
            return true;
        }

        return $user->isReader() && app(ReaderVerificationSession::class)->isRecentlyVerified($person->id);
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
