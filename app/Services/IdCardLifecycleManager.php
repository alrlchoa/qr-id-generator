<?php

namespace App\Services;

use App\Exceptions\UnitAtCapacityException;
use App\Models\IdCard;
use App\Models\Template;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Every status transition in architecture §4 that isn't issuance itself
 * (Phase 8's `IssuanceManager`): losing a card, revoking one, expiring one
 * directly, and the replacement mechanic §9.3's mandatory reissue and §5.3's
 * relationship-closure cascade both build on.
 */
class IdCardLifecycleManager
{
    public function __construct(
        private readonly ControlNumberGenerator $controlNumbers,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * **[Changed, 2026-09-30 — explicit user decision.]** No longer
     * auto-replaces. A lost card just becomes `lost`, with no active card
     * behind it for that person/type until someone deliberately acts:
     * `markFound()` revives this exact row, or `revoke()` closes it out —
     * either way, a brand-new card only ever comes from a fresh `Issue ID`
     * call, never automatically from this one. `$reason` is free text for
     * the audit trail — `id_cards` has no column for it.
     */
    public function markLost(?User $actor, IdCard $card, string $reason, ?string $actingAs = null): IdCard
    {
        $this->refuseUnlessActive($card);

        $card->forceFill(['status' => 'lost'])->save();

        $this->auditLogger->log(actor: $actor, action: 'id_marked_lost', subject: $card, newValue: ['reason' => $reason], actingAs: $actingAs);

        return $card;
    }

    /**
     * **[Widened, 2026-09-30]** Usable on an `active` card (an admin
     * deliberately withdrawing a card from someone still entitled to one,
     * CLAUDE.md rule 6) or on a `lost` one (closing out a lost card for
     * good, the other way — besides `markFound()` — of releasing the
     * person/type slot a lost card would otherwise hold open). Never
     * issues a replacement either way; a future issuance is always a
     * fresh, deliberate admin decision.
     */
    public function revoke(?User $actor, IdCard $card, string $reason, ?string $actingAs = null): IdCard
    {
        $this->refuseUnlessActiveOrLost($card);

        $card->forceFill(['status' => 'revoked'])->save();

        $this->auditLogger->log(actor: $actor, action: 'id_revoked', subject: $card, newValue: ['reason' => $reason], actingAs: $actingAs);

        return $card;
    }

    /**
     * **[Added, 2026-09-30 — explicit user decision.]** The one reverse
     * status transition in this system — every other one (rule 6's family)
     * is one-way. The card was physically found, so the same row goes back
     * to `active` rather than a new one being minted: same control number,
     * same `printed_at` (rule 59's "no un-print" is untouched — a card
     * that was printed before going missing still can't be printed again
     * now that it's active once more). Re-checks the unit's six-slot cap
     * (§5.2) under lock, the same way every other path that adds an active
     * card back onto a unit does — the unit may have filled up while this
     * card sat lost.
     */
    public function markFound(?User $actor, IdCard $card, ?string $actingAs = null): IdCard
    {
        $this->refuseUnlessLost($card);

        return DB::transaction(function () use ($actor, $card, $actingAs) {
            if ($card->unit_id !== null) {
                $lockedUnit = Unit::lockById($card->unit_id);

                if ($card->person_id !== $lockedUnit->primaryOwnerPersonId() && $lockedUnit->nonPrimaryOwnerActiveCardCount() >= Unit::OCCUPANT_SLOTS) {
                    throw new UnitAtCapacityException($lockedUnit);
                }
            }

            $card->forceFill(['status' => 'active'])->save();

            $this->auditLogger->log(actor: $actor, action: 'id_marked_found', subject: $card, actingAs: $actingAs);

            return $card;
        });
    }

    /**
     * Direct, admin-triggered expiry — the entitlement lapsed and an admin
     * is recording that fact by hand. Distinct from `expireForClosure()`
     * below, which the relationship-closure cascade drives automatically
     * and which never takes a free-text reason from a form.
     */
    public function expire(?User $actor, IdCard $card, string $reason, ?string $actingAs = null): IdCard
    {
        $this->refuseUnlessTenant($card);
        $this->refuseUnlessActive($card);

        $card->forceFill(['status' => 'expired'])->save();

        $this->auditLogger->log(actor: $actor, action: 'id_expired', subject: $card, newValue: ['reason' => $reason], actingAs: $actingAs);

        return $card;
    }

    /**
     * §5.3's cascade. Deliberately opens no transaction of its own — the
     * caller (`RelationshipManager::closeRelationship()`) already holds
     * one, and this write has to commit or roll back with the relationship
     * closure it belongs to, not independently.
     *
     * Deliberately does *not* call `refuseUnlessTenant()` — that guard is
     * specifically about the manual "Expire" button (a lease running out
     * is the only thing an admin should be able to click "expired" for by
     * hand), not about this cascade. An owner's relationship closing is a
     * genuine, correct expiry of their card's entitlement regardless of
     * type, and this path predates the manual-button restriction by a
     * phase — restricting it too would silently break §5.3's own tested
     * behavior. An employee card can never reach here in practice: it
     * carries no `unit_id` and no relationship, so it never matches this
     * cascade's own `unit_id`/`person_id` lookup in the first place.
     */
    public function expireForClosure(?User $actor, IdCard $card, ?string $actingAs = null): void
    {
        $this->refuseUnlessActive($card);

        $card->forceFill(['status' => 'expired'])->save();

        $this->auditLogger->log(actor: $actor, action: 'id_expired', subject: $card, newValue: ['reason' => 'relationship_closed'], actingAs: $actingAs);
    }

    /**
     * The shared replacement mechanic: retire the old card to `$oldStatus`
     * *before* counting capacity for the new one (CLAUDE.md rule 19) — a
     * unit at 6/6 must not reject its own occupant's replacement. Type and
     * unit default to the old card's own, since a straight reissue never
     * changes what a card is *for*, only what's printed on it — a type or
     * unit change is a transfer/promotion decision made elsewhere.
     */
    public function replace(?User $actor, IdCard $oldCard, string $oldStatus, string $replacementReason, ?string $actingAs = null): IdCard
    {
        $this->refuseUnlessActive($oldCard);

        return DB::transaction(function () use ($actor, $oldCard, $oldStatus, $replacementReason, $actingAs) {
            $unit = $oldCard->unit_id !== null
                ? Unit::lockById($oldCard->unit_id)
                : null;

            $oldCard->forceFill(['status' => $oldStatus])->save();

            if ($unit !== null && $oldCard->person_id !== $unit->primaryOwnerPersonId() && $unit->nonPrimaryOwnerActiveCardCount() >= Unit::OCCUPANT_SLOTS) {
                throw new UnitAtCapacityException($unit);
            }

            $newCard = $this->controlNumbers->createWithUniqueControlNumber([
                'person_id' => $oldCard->person_id,
                'unit_id' => $oldCard->unit_id,
                'type' => $oldCard->type,
                'status' => 'active',
                'position' => $oldCard->position,
                'department' => $oldCard->department,
                // Phase 12: re-resolved against whatever is active *now*,
                // not inherited from the retired card. template_id is
                // provenance for the card it's actually stamped on — a
                // replacement is a fresh issuance in every sense that
                // matters, so it gets today's active template (or null),
                // never the old card's, even if the design has since
                // changed. There is no historical reprint (rule 13); this
                // is the same principle applied to the card that succeeds
                // one, not just the one being replaced.
                'template_id' => Template::activeFor($oldCard->type)?->id,
                'replacement_reason' => $replacementReason,
                'replaces_id_card_id' => $oldCard->id,
                'issued_at' => now(),
            ]);

            $this->auditLogger->log(
                actor: $actor,
                action: 'id_replaced',
                subject: $newCard,
                previousValue: ['replaced_card_id' => $oldCard->id, 'old_status' => $oldStatus, 'control_number' => $oldCard->control_number],
                newValue: ['control_number' => $newCard->control_number, 'replacement_reason' => $replacementReason],
                actingAs: $actingAs,
            );

            return $newCard;
        });
    }

    private function refuseUnlessActive(IdCard $card): void
    {
        if ($card->status !== 'active') {
            throw new InvalidArgumentException("Card #{$card->control_number} is already {$card->status} — only an active card can transition.");
        }
    }

    private function refuseUnlessActiveOrLost(IdCard $card): void
    {
        if (! in_array($card->status, ['active', 'lost'], true)) {
            throw new InvalidArgumentException("Card #{$card->control_number} is already {$card->status} — only an active or lost card can transition.");
        }
    }

    private function refuseUnlessLost(IdCard $card): void
    {
        if ($card->status !== 'lost') {
            throw new InvalidArgumentException("Card #{$card->control_number} is {$card->status} — only a lost card can be marked found.");
        }
    }

    /**
     * The manual "Expire" button is only for a tenant's lease running
     * out — the paradigm case rule 6 defines "expired" against ("the
     * entitlement lapsed"). An owner's or employee's entitlement doesn't
     * lapse on a timer the way a lease does; ending either is always a
     * deliberate admin decision, which is exactly what revoke() already
     * means. Checked before `refuseUnlessActive()` — a kind-based refusal
     * ahead of a state-based one, the same ordering `IssuanceManager`
     * already uses for refusing a company by kind before checking tier.
     */
    private function refuseUnlessTenant(IdCard $card): void
    {
        if ($card->type !== 'tenant') {
            throw new InvalidArgumentException(
                "Card #{$card->control_number} is a {$card->type} card — only a tenant's card can expire. Revoke it, or mark it lost, instead."
            );
        }
    }
}
