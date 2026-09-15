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

    /**
     * Employee issuance is Superadmin-only (Phase 8 plan, architecture §5.1)
     * — narrower than ordinary owner/tenant issuance, which `create()` above
     * already opens to Admin too.
     */
    public function issueEmployee(User $user): bool
    {
        return $user->isSuperadmin();
    }

    /**
     * The Card lifecycle screen (index/show) and rendered card images
     * (`CardRenderer`, `IdCardRenderController`) are Superadmin/Admin-only
     * (Phase 12 plan, architecture §11) — narrower than `view()`/`viewAny()`
     * above, which deliberately include Reader for the verify flow's own
     * internal single-card authorization check, a different screen
     * entirely. A Reader reaching a rendered front image would see the
     * embedded photo with no 60-second gate at all (rule 23) — this
     * ability exists specifically to keep that gate meaningful.
     */
    public function manageLifecycle(User $user): bool
    {
        return $user->isSuperadmin() || $user->isAdmin();
    }
}
