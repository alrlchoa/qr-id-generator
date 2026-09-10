<?php

namespace App\Services;

use App\Models\IdCard;
use App\Models\User;
use InvalidArgumentException;
use ZipArchive;

/**
 * Produces the front/back PNG pair for an issued card as a single zip, for
 * loading into external card-printer software (architecture §10 — this
 * system's job ends at the raster images; printing itself is always a
 * separate, external workflow). Printing a card is a one-way action: once
 * `printed_at` is set, `print()` refuses to run again for that card. There
 * is no "un-print" — a card that needs a new physical copy after this is a
 * replacement (`IdCardLifecycleManager::replace()`), which is a fresh card
 * with `printed_at` starting `null` again, not a reset of this one's flag.
 */
class CardPrintService
{
    public function __construct(
        private readonly CardRenderer $renderer,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return string Zip file bytes — front.png and back.png.
     */
    public function print(?User $actor, IdCard $card, ?string $actingAs = null): string
    {
        if ($card->isPrinted()) {
            throw new InvalidArgumentException("Card #{$card->control_number} has already been printed.");
        }

        $front = $this->renderer->renderFront($card);
        $back = $this->renderer->renderBack($card);
        $zip = $this->buildZip($card->control_number, $front, $back);

        $card->forceFill(['printed_at' => now()])->save();

        $this->auditLogger->log(
            actor: $actor,
            action: 'id_printed',
            subject: $card,
            newValue: ['printed_at' => $card->printed_at->toISOString()],
            actingAs: $actingAs,
        );

        return $zip;
    }

    private function buildZip(string $controlNumber, string $frontPng, string $backPng): string
    {
        $path = tempnam(sys_get_temp_dir(), 'card-print').'.zip';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString("{$controlNumber}-front.png", $frontPng);
        $zip->addFromString("{$controlNumber}-back.png", $backPng);
        $zip->close();

        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }
}
