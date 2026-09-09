<?php

namespace App\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use GdImage;

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

    /**
     * A raster QR for `CardRenderer` to composite directly onto a card's
     * canvas. bacon/bacon-qr-code ships no GD back end (only SVG, EPS, and
     * Imagick — this box has GD, not Imagick), and round-tripping through
     * SVG would need a rasterizer this codebase doesn't have either. A QR
     * is just a module grid with no curves, so drawing the encoder's own
     * bit matrix straight onto a GD canvas — one filled square per module,
     * plus the standard 4-module quiet zone — is simpler and more direct
     * than implementing bacon-qr-code's full path-based
     * `ImageBackEndInterface` for what is, in the end, a black-and-white
     * grid.
     */
    public function rasterFor(string $controlNumber, int $sizeInPixels): GdImage
    {
        $matrix = Encoder::encode($controlNumber, ErrorCorrectionLevel::M())->getMatrix();
        $modules = $matrix->getWidth();
        $quietZoneModules = 4;
        $totalModules = $modules + ($quietZoneModules * 2);
        $moduleSize = max(1, intdiv($sizeInPixels, $totalModules));
        $canvasSize = $moduleSize * $totalModules;

        $image = imagecreatetruecolor($canvasSize, $canvasSize);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, $canvasSize - 1, $canvasSize - 1, $white);

        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $left = ($x + $quietZoneModules) * $moduleSize;
                    $top = ($y + $quietZoneModules) * $moduleSize;
                    imagefilledrectangle($image, $left, $top, $left + $moduleSize - 1, $top + $moduleSize - 1, $black);
                }
            }
        }

        return $image;
    }
}
