<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The only writer of `site_settings` (Phase 16). Every change writes one
 * audit row through AuditLogger; saving a value that didn't change writes
 * nothing, since nothing happened.
 *
 * The logo lives on the private `local` disk beside person photos, so the
 * nightly app backup (storage/app/private) takes it too. It is re-encoded
 * here, never stored as uploaded: a PNG of at most 512 × 512, transparency
 * kept. SVG is refused outright — an SVG can carry script, and the logo is
 * served to people who haven't signed in (CLAUDE.md 68).
 */
class SiteSettingsManager
{
    public const LOGO_MAX_BYTES = 1024 * 1024;

    /** The stored logo's largest side, in pixels. */
    public const LOGO_MAX_SIDE = 512;

    /**
     * The largest source image accepted. A 1 MB PNG of flat colour can be
     * enormous once decoded. Measured with the framework loaded, decoding
     * and resizing peaks at about 73 MB for 2400 × 2400, 94 MB for 3000 and
     * 113 MB for 3200. 3000 leaves about 30 MB spare under PHP's default
     * 128 MB limit. It was 2048 until a real 2400 × 2400 logo was refused.
     */
    public const LOGO_MAX_SOURCE_SIDE = 3000;

    private const LOGO_DIRECTORY = 'branding';

    private const ALLOWED_LOGO_TYPES = ['image/png', 'image/jpeg'];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function rename(User $actor, string $name): void
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        if ($name === '' || mb_strlen($name) > SiteSetting::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException('The site name must be 1 to '.SiteSetting::MAX_NAME_LENGTH.' characters.');
        }
        if (preg_match('/\p{C}/u', $name)) {
            throw new InvalidArgumentException('The site name can only contain printable characters.');
        }

        DB::transaction(function () use ($actor, $name) {
            $settings = $this->lockedRow();
            $previous = $settings->siteName();

            if ($previous === $name) {
                return;
            }

            $settings->forceFill(['site_name' => $name])->save();

            $this->auditLogger->log(
                actor: $actor,
                action: 'site_name_changed',
                subject: $settings,
                previousValue: ['site_name' => $previous],
                newValue: ['site_name' => $name],
            );
        });
    }

    public function changeNavbarColor(User $actor, string $color): void
    {
        $color = strtolower(trim($color));

        if (! preg_match('/^#[0-9a-f]{6}$/', $color)) {
            throw new InvalidArgumentException('The navbar colour must be a hex colour like #1e3a8a.');
        }

        DB::transaction(function () use ($actor, $color) {
            $settings = $this->lockedRow();
            $previous = $settings->navbarColor();

            if ($previous === $color) {
                return;
            }

            $settings->forceFill(['navbar_color' => $color])->save();

            $this->auditLogger->log(
                actor: $actor,
                action: 'navbar_color_changed',
                subject: $settings,
                previousValue: ['navbar_color' => $previous],
                newValue: ['navbar_color' => $color],
            );
        });
    }

    public function replaceLogo(User $actor, UploadedFile $file): void
    {
        $side = $this->validateLogo($file);
        $png = $this->normalizeLogo((string) $file->getRealPath(), $side);

        $path = self::LOGO_DIRECTORY.'/logo-'.Str::uuid()->toString().'.png';
        Storage::disk('local')->put($path, $png);

        try {
            $previous = DB::transaction(function () use ($actor, $path, $side) {
                $settings = $this->lockedRow();
                $previous = $settings->logo_path;

                $settings->forceFill(['logo_path' => $path])->save();

                $this->auditLogger->log(
                    actor: $actor,
                    action: 'site_logo_changed',
                    subject: $settings,
                    previousValue: ['logo_path' => $previous],
                    newValue: ['logo_path' => $path, 'uploaded_side_px' => $side],
                );

                return $previous;
            });
        } catch (\Throwable $e) {
            // The row still points at the old logo; don't leave the new file orphaned.
            Storage::disk('local')->delete($path);

            throw $e;
        }

        if ($previous) {
            Storage::disk('local')->delete($previous);
        }
    }

    public function removeLogo(User $actor): void
    {
        $previous = DB::transaction(function () use ($actor) {
            $settings = $this->lockedRow();
            $previous = $settings->logo_path;

            if (! $previous) {
                return null;
            }

            $settings->forceFill(['logo_path' => null])->save();

            $this->auditLogger->log(
                actor: $actor,
                action: 'site_logo_removed',
                subject: $settings,
                previousValue: ['logo_path' => $previous],
            );

            return $previous;
        });

        if ($previous) {
            Storage::disk('local')->delete($previous);
        }
    }

    private function lockedRow(): SiteSetting
    {
        return SiteSetting::query()->lockForUpdate()->findOrFail(1);
    }

    /**
     * Checks the upload before decoding anything: size, the real image type
     * (sniffed from the bytes, not the client's claim), square, and not so
     * large that decoding it would exhaust memory. Returns the side length.
     */
    private function validateLogo(UploadedFile $file): int
    {
        $size = $file->getSize();

        if ($size === false || $size > self::LOGO_MAX_BYTES) {
            throw new InvalidArgumentException('The logo must be 1 MB or smaller.');
        }

        $info = @getimagesize((string) $file->getRealPath());

        if ($info === false || ! in_array($info['mime'], self::ALLOWED_LOGO_TYPES, true)) {
            throw new InvalidArgumentException('The logo must be a PNG or JPEG image. SVG and other formats aren\'t accepted.');
        }

        [$width, $height] = $info;

        if ($width !== $height) {
            throw new InvalidArgumentException("The logo must be square — this one is {$width} × {$height} pixels.");
        }
        if ($width > self::LOGO_MAX_SOURCE_SIDE) {
            $max = self::LOGO_MAX_SOURCE_SIDE;
            throw new InvalidArgumentException("The logo can be at most {$max} × {$max} pixels — this one is {$width} × {$height}.");
        }

        return $width;
    }

    /** Re-encodes to a PNG no larger than LOGO_MAX_SIDE, keeping transparency. */
    private function normalizeLogo(string $path, int $side): string
    {
        $source = @imagecreatefromstring((string) file_get_contents($path));

        if (! $source instanceof GdImage) {
            throw new InvalidArgumentException('The logo could not be read as an image.');
        }

        $target = min($side, self::LOGO_MAX_SIDE);
        $canvas = imagecreatetruecolor($target, $target);

        if (! $canvas instanceof GdImage) {
            throw new InvalidArgumentException('The logo could not be processed.');
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $target, $target, $side, $side);
        imagedestroy($source);

        ob_start();
        imagepng($canvas, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($canvas);

        if ($png === '') {
            throw new InvalidArgumentException('The logo could not be processed.');
        }

        return $png;
    }
}
