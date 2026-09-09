<?php

namespace App\Services;

use App\Exceptions\TemplateFieldObscuredException;
use App\Exceptions\TemplateOverlayObscuresQrException;
use App\Models\Template;
use App\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Template CRUD, artwork upload, and field placement (Phase 12 plan).
 * Superadmin-only at the policy layer (`TemplatePolicy`) — this service
 * doesn't re-check that, the same division of responsibility every other
 * service in this codebase uses.
 *
 * The overlay composites last, on top of every field (a cut-out frames the
 * photo as a border), which is why an opaque upload is a silent failure
 * with no natural symptom — checkOverlayAgainstFields() is the whole
 * reason this class exists rather than a bare file-upload endpoint.
 */
class TemplateManager
{
    private const DIRECTORY = 'template-overlays';

    private const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * A field box counted "opaque" once its pixels are mostly non-transparent
     * — GD alpha runs 0 (opaque) to 127 (fully transparent), so <= 32 is
     * "mostly opaque" for one sampled pixel. A box is flagged once 15% or
     * more of its sampled pixels cross that line: partial coverage is
     * usually the intended border, so the bar is deliberately not "any
     * opaque pixel at all."
     */
    private const OPAQUE_ALPHA_THRESHOLD = 32;

    private const OBSCURED_COVERAGE_THRESHOLD = 0.15;

    /** Every 4th pixel in each direction — a heuristic, not an exact area. */
    private const SAMPLE_STRIDE = 4;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function createTemplate(?User $actor, string $idType, string $name, string $orientation, ?string $actingAs = null): Template
    {
        if (! in_array($idType, Template::ID_TYPES, true)) {
            throw new InvalidArgumentException("'{$idType}' isn't a valid id_type.");
        }

        $template = Template::create([
            'id_type' => $idType,
            'name' => $name,
            ...Template::dimensionsFor($orientation),
            'is_active' => false,
        ]);

        $this->auditLogger->log(
            actor: $actor,
            action: 'template_created',
            subject: $template,
            newValue: ['id_type' => $idType, 'name' => $name, 'orientation' => $orientation],
            actingAs: $actingAs,
        );

        return $template;
    }

    /**
     * `$force` bypasses a warning about non-QR field coverage only — it can
     * never bypass `TemplateOverlayObscuresQrException`, which carries no
     * such override.
     */
    public function uploadFrontOverlay(?User $actor, Template $template, UploadedFile $file, bool $force = false, ?string $actingAs = null): Template
    {
        $path = $this->storeOverlay($template, $file, side: 'front');

        if ($template->field_positions_front !== null) {
            $this->checkOverlayAgainstFields($path, $template->field_positions_front, $force);
        }

        $previous = $template->overlay_path_front;
        $template->forceFill(['overlay_path_front' => $path])->save();
        $this->deleteIfReplaced($previous, $path);

        $this->auditLogger->log(actor: $actor, action: 'template_overlay_uploaded', subject: $template, newValue: ['side' => 'front'], actingAs: $actingAs);

        return $template;
    }

    /**
     * The back carries no placeable fields (Phase 12 plan), so there is
     * nothing to alpha-check against — the artwork is the whole image.
     */
    public function uploadBackOverlay(?User $actor, Template $template, UploadedFile $file, ?string $actingAs = null): Template
    {
        $path = $this->storeOverlay($template, $file, side: 'back');

        $previous = $template->overlay_path_back;
        $template->forceFill(['overlay_path_back' => $path])->save();
        $this->deleteIfReplaced($previous, $path);

        $this->auditLogger->log(actor: $actor, action: 'template_overlay_uploaded', subject: $template, newValue: ['side' => 'back'], actingAs: $actingAs);

        return $template;
    }

    /**
     * `$positions` must name exactly `$template->placeableFields()` — no
     * fewer (the renderer would draw nothing for a missing field) and no
     * more (an unknown key would silently do nothing, which is worse than
     * refusing it). Each box is validated to sit inside the canvas before
     * the overlay is ever inspected, since a malformed box is a cheaper,
     * input-shape problem (the same ordering RelationshipManager's own
     * date validation uses).
     *
     * @param  array<string, array<string, mixed>>  $positions  Unvalidated input — form data or a decoded JSON
     *                                                          payload, never trusted to already match the strict
     *                                                          box shape. validatePositions() below is what proves
     *                                                          it, and hands back the shape everything downstream
     *                                                          (the model column, checkOverlayAgainstFields())
     *                                                          actually relies on.
     */
    public function saveFieldPositions(?User $actor, Template $template, array $positions, bool $force = false, ?string $actingAs = null): Template
    {
        $validated = $this->validatePositions($template, $positions);

        if ($template->overlay_path_front !== null) {
            $this->checkOverlayAgainstFields($template->overlay_path_front, $validated, $force);
        }

        $previous = $template->field_positions_front;
        $template->forceFill(['field_positions_front' => $validated])->save();

        $this->auditLogger->log(
            actor: $actor,
            action: 'template_field_positions_updated',
            subject: $template,
            previousValue: ['field_positions_front' => $previous],
            newValue: ['field_positions_front' => $validated],
            actingAs: $actingAs,
        );

        return $template;
    }

    /**
     * Retire-then-set (rule 32's pattern, reused here): the outgoing active
     * template for this id_type, if any, is deactivated in the same
     * transaction before the incoming one is activated — the reverse order
     * would violate uq_templates_active_per_id_type immediately, the same
     * reason primary-owner promotion clears the outgoing flag first.
     */
    public function activate(?User $actor, Template $template, ?string $actingAs = null): void
    {
        if (! $template->isComplete()) {
            throw new InvalidArgumentException(
                "'{$template->name}' can't be activated yet — it needs both sides uploaded and every field positioned first."
            );
        }

        DB::transaction(function () use ($actor, $template, $actingAs) {
            $outgoing = Template::where('id_type', $template->id_type)
                ->where('is_active', true)
                ->where('id', '!=', $template->id)
                ->lockForUpdate()
                ->first();

            if ($outgoing !== null) {
                $outgoing->forceFill(['is_active' => false])->save();
            }

            $template->forceFill(['is_active' => true])->save();

            $this->auditLogger->log(
                actor: $actor,
                action: 'template_activated',
                subject: $template,
                previousValue: ['outgoing_template_id' => $outgoing?->id],
                newValue: ['id_type' => $template->id_type],
                actingAs: $actingAs,
            );
        });
    }

    public function deactivate(?User $actor, Template $template, ?string $actingAs = null): void
    {
        $template->forceFill(['is_active' => false])->save();

        $this->auditLogger->log(actor: $actor, action: 'template_deactivated', subject: $template, actingAs: $actingAs);
    }

    /**
     * Guarded, never cascading (the same shape rule 9 uses for people and
     * units) — a template any issued card still points to as provenance
     * (rule 13) is never deleted out from under that record.
     */
    public function delete(?User $actor, Template $template, ?string $actingAs = null): void
    {
        if ($template->idCards()->exists()) {
            throw new InvalidArgumentException(
                "'{$template->name}' produced at least one card and can't be deleted — deactivate it instead."
            );
        }

        $this->auditLogger->log(actor: $actor, action: 'template_deleted', subject: $template, actingAs: $actingAs);

        $frontPath = $template->overlay_path_front;
        $backPath = $template->overlay_path_back;

        $template->delete();

        if ($frontPath) {
            Storage::disk('local')->delete($frontPath);
        }

        if ($backPath) {
            Storage::disk('local')->delete($backPath);
        }
    }

    private function storeOverlay(Template $template, UploadedFile $file, string $side): string
    {
        if ($file->getSize() === false || $file->getSize() > self::MAX_BYTES) {
            throw new InvalidArgumentException('Artwork must be 5MB or smaller.');
        }

        // PNG only, never JPEG — the overlay composites last and needs
        // real transparency to be anything but an opaque background.
        $sniffed = @getimagesize($file->getRealPath());

        if ($sniffed === false || $sniffed['mime'] !== 'image/png') {
            throw new InvalidArgumentException('Artwork must be a PNG image with transparency.');
        }

        if ($sniffed[0] !== $template->width_px || $sniffed[1] !== $template->height_px) {
            throw new InvalidArgumentException(
                "Artwork must be exactly {$template->width_px}x{$template->height_px}px — this template is ".$template->orientation().
                " (uploaded image was {$sniffed[0]}x{$sniffed[1]}px)."
            );
        }

        $filename = self::DIRECTORY.'/'.Str::uuid()->toString()."-{$side}.png";

        Storage::disk('local')->put($filename, (string) file_get_contents($file->getRealPath()));

        return $filename;
    }

    private function deleteIfReplaced(?string $previousPath, string $newPath): void
    {
        if ($previousPath !== null && $previousPath !== $newPath) {
            Storage::disk('local')->delete($previousPath);
        }
    }

    /**
     * Validates and coerces raw, unvalidated input into the strict box
     * shape everything downstream relies on — a Livewire form or a decoded
     * JSON payload may hand back numeric strings rather than true PHP
     * ints, so this accepts either and normalizes, rather than rejecting a
     * perfectly valid "100" the way a bare `is_int()` check would.
     *
     * @param  array<string, array<string, mixed>>  $positions
     * @return array<string, array{x: int, y: int, width: int, height: int}>
     */
    private function validatePositions(Template $template, array $positions): array
    {
        $required = $template->placeableFields();
        $given = array_keys($positions);
        $missing = array_diff($required, $given);
        $unexpected = array_diff($given, $required);

        if ($missing !== [] || $unexpected !== []) {
            $parts = [];

            if ($missing !== []) {
                $parts[] = 'missing: '.implode(', ', $missing);
            }

            if ($unexpected !== []) {
                $parts[] = 'unexpected: '.implode(', ', $unexpected);
            }

            throw new InvalidArgumentException("Field positions don't match this template's type ({$template->id_type}) — ".implode('; ', $parts).'.');
        }

        $validated = [];

        foreach ($positions as $field => $box) {
            $x = $this->coerceBoxValue($box['x'] ?? null, $field, 'x');
            $y = $this->coerceBoxValue($box['y'] ?? null, $field, 'y');
            $width = $this->coerceBoxValue($box['width'] ?? null, $field, 'width');
            $height = $this->coerceBoxValue($box['height'] ?? null, $field, 'height');

            if ($x + $width > $template->width_px || $y + $height > $template->height_px) {
                throw new InvalidArgumentException("The {$field} field's box falls outside the {$template->width_px}x{$template->height_px}px canvas.");
            }

            $validated[$field] = ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height];
        }

        return $validated;
    }

    /**
     * A Livewire form or a decoded JSON payload may hand back a numeric
     * string rather than a true PHP int — accepted and normalized here,
     * rather than rejecting a perfectly valid "100" the way a bare
     * `is_int()` check would.
     */
    private function coerceBoxValue(mixed $value, string $field, string $key): int
    {
        $isWholeNumber = is_int($value)
            || (is_string($value) && ctype_digit($value))
            || (is_float($value) && $value === floor($value));

        if (! $isWholeNumber) {
            throw new InvalidArgumentException("The {$field} field's {$key} must be a non-negative integer.");
        }

        $coerced = (int) $value;

        if ($coerced < 0) {
            throw new InvalidArgumentException("The {$field} field's {$key} must be a non-negative integer.");
        }

        return $coerced;
    }

    /**
     * @param  array<string, array{x: int, y: int, width: int, height: int}>  $positions
     */
    private function checkOverlayAgainstFields(string $overlayPath, array $positions, bool $force): void
    {
        $bytes = Storage::disk('local')->get($overlayPath);
        $image = $bytes !== null ? @imagecreatefromstring($bytes) : false;

        if ($image === false) {
            return;
        }

        $obscured = [];

        foreach ($positions as $field => $box) {
            if ($this->opacityCoverage($image, $box) >= self::OBSCURED_COVERAGE_THRESHOLD) {
                $obscured[] = $field;
            }
        }

        imagedestroy($image);

        if (in_array('qr', $obscured, true)) {
            throw new TemplateOverlayObscuresQrException;
        }

        if ($obscured !== [] && ! $force) {
            throw new TemplateFieldObscuredException(
                $obscured,
                'The uploaded artwork substantially covers: '.implode(', ', $obscured).
                '. This is often the intended border effect — confirm to save anyway, or reposition the field.'
            );
        }
    }

    /**
     * @param  array{x: int, y: int, width: int, height: int}  $box
     */
    private function opacityCoverage(GdImage $image, array $box): float
    {
        $imageWidth = imagesx($image);
        $imageHeight = imagesy($image);

        $sampled = 0;
        $opaque = 0;

        for ($y = $box['y']; $y < $box['y'] + $box['height'] && $y < $imageHeight; $y += self::SAMPLE_STRIDE) {
            for ($x = $box['x']; $x < $box['x'] + $box['width'] && $x < $imageWidth; $x += self::SAMPLE_STRIDE) {
                $rgba = imagecolorat($image, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;
                $sampled++;

                if ($alpha <= self::OPAQUE_ALPHA_THRESHOLD) {
                    $opaque++;
                }
            }
        }

        return $sampled > 0 ? $opaque / $sampled : 0.0;
    }
}
