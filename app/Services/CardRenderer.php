<?php

namespace App\Services;

use App\Models\Font;
use App\Models\IdCard;
use GdImage;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Compositing a card's front and back into raster PNGs (Phase 12 plan,
 * architecture §10). Three layers, in this order, deliberately not the
 * order the original Phase 1 schema implied:
 *
 *   1. A solid white CR80 canvas at the template's own dimensions.
 *   2. Every placeable field, drawn from the card's own data.
 *   3. The uploaded overlay PNG, composited last, on top — a cut-out in
 *      the artwork frames a field (most often the photo) as a border. This
 *      is why an opaque overlay upload is a silent failure with no natural
 *      symptom (`TemplateManager`'s alpha check exists precisely to catch
 *      it before it reaches here).
 *
 * Renders from `$card->template` — whatever `template_id` recorded at
 * issuance — never from `Template::activeFor()`. `template_id` is
 * provenance (rule 13): it answers "which template produced this card,"
 * which is exactly the template this method has to render from. A design
 * change after issuance is not retroactive; there is no historical
 * reprint.
 */
class CardRenderer
{
    private const QUIET_MARGIN_RATIO = 0.08;

    public function __construct(private readonly QrCodeGenerator $qr) {}

    public function renderFront(IdCard $card): string
    {
        $template = $card->template;

        if ($template === null) {
            throw new InvalidArgumentException("Card #{$card->control_number} has no template on record — nothing to render from.");
        }

        if (! $template->isComplete()) {
            throw new InvalidArgumentException("'{$template->name}' isn't fully built yet — it can't render a card.");
        }

        $canvas = $this->whiteCanvas($template->width_px, $template->height_px);

        foreach ($template->field_positions_front ?? [] as $field => $box) {
            $this->drawField($canvas, $card, $field, $box);
        }

        $this->compositeOverlay($canvas, $template->overlay_path_front);

        return $this->encodePng($canvas);
    }

    /**
     * The back carries no placeable fields — it is the white canvas plus
     * the overlay artwork alone.
     */
    public function renderBack(IdCard $card): string
    {
        $template = $card->template;

        if ($template === null) {
            throw new InvalidArgumentException("Card #{$card->control_number} has no template on record — nothing to render from.");
        }

        if ($template->overlay_path_back === null) {
            throw new InvalidArgumentException("'{$template->name}' has no back artwork uploaded yet.");
        }

        $canvas = $this->whiteCanvas($template->width_px, $template->height_px);
        $this->compositeOverlay($canvas, $template->overlay_path_back);

        return $this->encodePng($canvas);
    }

    private function whiteCanvas(int $width, int $height): GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, $white);

        return $canvas;
    }

    /**
     * @param  array{x: int, y: int, width: int, height: int}  $box
     */
    private function drawField(GdImage $canvas, IdCard $card, string $field, array $box): void
    {
        match ($field) {
            'photo' => $this->drawPhoto($canvas, $card, $box),
            'name' => $this->drawText($canvas, $card->person->displayName(), $box),
            'unit_number' => $this->drawText($canvas, $card->unit?->unitCode() ?? '', $box),
            'qr' => $this->drawQr($canvas, $card, $box),
            'role' => $this->drawText($canvas, $this->roleLabel($card), $box),
            default => null,
        };
    }

    private function roleLabel(IdCard $card): string
    {
        return match ($card->type) {
            'owner' => 'Unit Owner',
            'tenant' => 'Tenant',
            'employee' => 'Employee',
            default => ucfirst($card->type),
        };
    }

    /**
     * @param  array{x: int, y: int, width: int, height: int}  $box
     */
    private function drawPhoto(GdImage $canvas, IdCard $card, array $box): void
    {
        $path = $card->person->photo_path;

        if ($path === null || ! Storage::disk('local')->exists($path)) {
            return;
        }

        $bytes = Storage::disk('local')->get($path);
        $photo = $bytes !== null ? @imagecreatefromstring($bytes) : false;

        if ($photo === false) {
            return;
        }

        // Already cropped to a 1:1 square at upload (PersonPhotoService) —
        // this is a resize into the field's box, never a second crop.
        imagecopyresampled(
            $canvas, $photo,
            $box['x'], $box['y'], 0, 0,
            $box['width'], $box['height'], imagesx($photo), imagesy($photo),
        );

        imagedestroy($photo);
    }

    /**
     * @param  array{x: int, y: int, width: int, height: int}  $box
     */
    private function drawQr(GdImage $canvas, IdCard $card, array $box): void
    {
        $size = (int) round(min($box['width'], $box['height']) * (1 - self::QUIET_MARGIN_RATIO));
        $qr = $this->qr->rasterFor($card->control_number, $size);

        // Centered within the box, not stretched — a QR must stay square
        // to keep its modules square, and rasterFor() already rounds to a
        // whole module size rather than the exact pixel count requested.
        $left = $box['x'] + intdiv($box['width'] - imagesx($qr), 2);
        $top = $box['y'] + intdiv($box['height'] - imagesy($qr), 2);

        imagecopy($canvas, $qr, $left, $top, 0, 0, imagesx($qr), imagesy($qr));
        imagedestroy($qr);
    }

    /**
     * @param  array{x: int, y: int, width: int, height: int}  $box
     */
    private function drawText(GdImage $canvas, string $text, array $box): void
    {
        if ($text === '') {
            return;
        }

        $black = imagecolorallocate($canvas, 0, 0, 0);
        $fontPath = $this->activeFontPath();

        if ($fontPath !== null) {
            $this->drawTrueTypeText($canvas, $text, $box, $black, $fontPath);

            return;
        }

        // No active font (a normal state — see Font::activeFont()): GD's
        // five fixed built-in sizes are a coarser shrink than continuous
        // TTF scaling, but they render real, legible text with no
        // dependency on a Superadmin having uploaded one yet. This path
        // is replaced automatically the moment a font is uploaded and
        // activated through the Fonts screen — no code change needed.
        $this->drawBuiltInFontText($canvas, $text, $box, $black);
    }

    /**
     * @param  array{x: int, y: int, width: int, height: int}  $box
     */
    private function drawTrueTypeText(GdImage $canvas, string $text, array $box, int $color, string $fontPath): void
    {
        $size = (int) round($box['height'] * 0.7);
        $minSize = 6;

        while ($size > $minSize) {
            $bbox = imagettfbbox($size, 0, $fontPath, $text);
            $textWidth = abs($bbox[2] - $bbox[0]);
            $textHeight = abs($bbox[1] - $bbox[7]);

            if ($textWidth <= $box['width'] && $textHeight <= $box['height']) {
                break;
            }

            $size--;
        }

        $bbox = imagettfbbox($size, 0, $fontPath, $text);
        $textHeight = abs($bbox[1] - $bbox[7]);
        $baselineY = $box['y'] + intdiv($box['height'] - $textHeight, 2) + $textHeight;

        imagettftext($canvas, $size, 0, $box['x'], $baselineY, $color, $fontPath, $text);
    }

    /**
     * @param  array{x: int, y: int, width: int, height: int}  $box
     */
    private function drawBuiltInFontText(GdImage $canvas, string $text, array $box, int $color): void
    {
        $chosenFont = 1;

        foreach ([5, 4, 3, 2, 1] as $fontIndex) {
            $width = imagefontwidth($fontIndex) * strlen($text);
            $height = imagefontheight($fontIndex);

            if ($width <= $box['width'] && $height <= $box['height']) {
                $chosenFont = $fontIndex;
                break;
            }
        }

        $top = $box['y'] + intdiv(max(0, $box['height'] - imagefontheight($chosenFont)), 2);

        imagestring($canvas, $chosenFont, $box['x'], $top, $text, $color);
    }

    /**
     * The active font's real filesystem path on the `local` disk — GD's
     * TTF functions need an actual path, not in-memory bytes, which is
     * why this reads the disk's own resolved path rather than fetching
     * the file's contents. `Font::activeFont()` returning `null` (no font
     * uploaded, or none activated yet) is a normal state, not an error.
     */
    private function activeFontPath(): ?string
    {
        $font = Font::activeFont();

        if ($font === null) {
            return null;
        }

        $path = Storage::disk('local')->path($font->storage_path);

        return is_file($path) ? $path : null;
    }

    private function compositeOverlay(GdImage $canvas, ?string $overlayPath): void
    {
        if ($overlayPath === null || ! Storage::disk('local')->exists($overlayPath)) {
            return;
        }

        $bytes = Storage::disk('local')->get($overlayPath);
        $overlay = $bytes !== null ? @imagecreatefromstring($bytes) : false;

        if ($overlay === false) {
            return;
        }

        // GD's default alpha-blending mode on the destination correctly
        // blends the overlay's per-pixel alpha against what's beneath it
        // — no imagealphablending()/imagesavealpha() calls needed here,
        // since the canvas is fully opaque and the output is a flat PNG.
        imagecopy($canvas, $overlay, 0, 0, 0, 0, imagesx($overlay), imagesy($overlay));
        imagedestroy($overlay);
    }

    private function encodePng(GdImage $canvas): string
    {
        ob_start();
        imagepng($canvas);
        $bytes = ob_get_clean();
        imagedestroy($canvas);

        if ($bytes === false) {
            throw new InvalidArgumentException('The card image could not be encoded.');
        }

        return $bytes;
    }
}
