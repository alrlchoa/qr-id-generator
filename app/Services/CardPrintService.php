<?php

namespace App\Services;

use App\Models\IdCard;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Produces a single card's Smart IDesigner import zip (Phase 19 plan) —
 * the same shape `BulkCardExportService` produces for many, via the one
 * shared builder, `SmartIdesignerZip`. Printing a card is a one-way
 * action: once `printed_at` is set, `print()` refuses to run again for
 * that card. There is no "un-print" — a card that needs a new physical
 * copy after this is a replacement (`IdCardLifecycleManager::replace()`),
 * which is a fresh card with `printed_at` starting `null` again, not a
 * reset of this one's flag.
 *
 * Only an `active` card can be printed — producing a physical copy of a
 * lost, revoked, or expired card would hand someone a working-looking
 * credential for an entitlement that no longer exists, the same
 * physical-object concern rule 7 already guards for `id_cards` rows
 * generally (a status transition, never an edit to what's already out).
 * Unlike rendering (`CardRenderer`), this no longer needs a template —
 * Smart IDesigner does the layout, so a card with no `template_id` prints
 * like any other.
 */
class CardPrintService
{
    public function __construct(
        private readonly SmartIdesignerZip $zipBuilder,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return string Zip file bytes — the card's own Smart IDesigner folder,
     *                the other two type folders present but header-only.
     */
    public function print(?User $actor, IdCard $card, ?string $actingAs = null): string
    {
        if ($card->status !== 'active') {
            throw new InvalidArgumentException("Card #{$card->control_number} is {$card->status} — only an active card can be printed.");
        }

        if ($card->isPrinted()) {
            throw new InvalidArgumentException("Card #{$card->control_number} has already been printed.");
        }

        $card->loadMissing(['person', 'unit']);

        $path = tempnam(sys_get_temp_dir(), 'card-print').'.zip';
        $this->zipBuilder->build(new Collection([$card]), $path);
        $bytes = (string) file_get_contents($path);
        unlink($path);

        $card->forceFill(['printed_at' => now()])->save();

        $this->auditLogger->log(
            actor: $actor,
            action: 'id_printed',
            subject: $card,
            newValue: ['printed_at' => $card->printed_at->toISOString()],
            actingAs: $actingAs,
        );

        return $bytes;
    }
}
