<?php

namespace App\Policies;

use App\Models\IdCard;
use App\Models\User;

/**
 * Architecture §11: card issuance/status changes are Superadmin/Admin;
 * viewing is also open to Reader (needed for the verify flow). Deletion is
 * hardcoded false — `id_cards` rows are never deleted (CLAUDE.md rule 7);
 * a status transition plus a new `replaces_id_card_id` row is the only path.
 */
class IdCardPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperadmin() || $user->isAdmin() || $user->isReader();
    }

    public function view(User $user, IdCard $idCard): bool
    {
        return $user->isSuperadmin() || $user->isAdmin() || $user->isReader();
    }

    public function create(User $user): bool
    {
        return $user->isSuperadmin() || $user->isAdmin();
    }

    public function update(User $user, IdCard $idCard): bool
    {
        return $user->isSuperadmin() || $user->isAdmin();
    }

    public function delete(User $user, IdCard $idCard): bool
    {
        return false;
    }
}
