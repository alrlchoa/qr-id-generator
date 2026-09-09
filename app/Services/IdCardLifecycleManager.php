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
     * The only status transition that automatically replaces itself
     * (architecture §4's diagram: lost -> a new card, `replacement_reason
     * = 'lost'`). `$reason` is free text for the audit trail — `id_cards`
     * has no column for it; `replacement_reason` is the fixed enum the new
     * card carries as provenance, not the admin's explanation.
     */
    public function markLost(?User $actor, IdCard $card, string $reason, ?string $actingAs = null): IdCard
    {
        $this->refuseUnlessActive($card);

        return DB::transaction(function () use ($actor, $card, $reason, $actingAs) {
            $this->auditLogger->log(actor: $actor, action: 'id_marked_lost', subject: $card, newValue: ['reason' => $reason], actingAs: $actingAs);

            return $this->replace($actor, $card, oldStatus: 'lost', replacementReason: 'lost', actingAs: $actingAs);
        });
    }

    /**
     * An admin deliberately withdrawing a card from someone still entitled
     * to one (CLAUDE.md rule 6) — never issues a replacement. A future
     * issuance for this person is a fresh admin decision, not automatic.
     */
    public function revoke(?User $actor, IdCard $card, string $reason, ?string $actingAs = null): IdCard
    {
        $this->refuseUnlessActive($card);

        $card->forceFill(['status' => 'revoked'])->save();

        $this->auditLogger->log(actor: $actor, action: 'id_revoked', subject: $card, newValue: ['reason' => $reason], actingAs: $actingAs);

        return $card;
    }

    /**
     * Direct, admin-triggered expiry — the entitlement lapsed and an admin
     * is recording that fact by hand. Distinct from `expireForClosure()`
     * below, which the relationship-closure cascade drives automatically
     * and which never takes a free-text reason from a form.
     */
    public function expire(?User $actor, IdCard $card, string $reason, ?string $actingAs = null): IdCard
    {
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
                ? Unit::where('id', $oldCard->unit_id)->lockForUpdate()->first()
                : null;

            $oldCard->forceFill(['status' => $oldStatus])->save();

            if ($unit !== null && $oldCard->person_id !== $unit->primaryOwnerPersonId() && $unit->nonPrimaryOwnerActiveCardCount() >= 6) {
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
}
