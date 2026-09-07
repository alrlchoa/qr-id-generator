<?php

namespace App\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Renders `id_cards.control_number` as a QR code, in plaintext, exactly as
 * stored (architecture §8, CLAUDE.md rule 15). No encryption, no token
 * column, no encoding scheme beyond the QR format itself — the number is
 * printed on the card face anyway, so the payload hides nothing the card
 * doesn't already show.
 *
 * Error correction level M, not the library's default L: a printed card
 * spends a year in a wallet and gets scanned at an angle on cheap
 * guardhouse hardware (§8's own reasoning for why the old encrypted design
 * failed) — M buys back some of that margin for the eight-character
 * plaintext payload without pushing the QR version up meaningfully.
 */
class QrCodeGenerator
{
    public function svgFor(string $controlNumber, int $sizeInPixels = 300): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($sizeInPixels),
            new SvgImageBackEnd,
        );

        return (new Writer($renderer))->writeString($controlNumber, ecLevel: ErrorCorrectionLevel::M());
    }
}
