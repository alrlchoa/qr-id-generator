<?php

namespace App\Services;

use App\Models\Font;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ZipArchive;

/**
 * Font upload and activation for card rendering (Phase 12 follow-up).
 * Superadmin-only at the policy layer (`FontPolicy`) — this service
 * doesn't re-check that, the same division of responsibility every other
 * service in this codebase uses.
 *
 * Fonts live on the private `local` disk, same as photos and template
 * overlays — never in `resources/`, which is source code and gets
 * overwritten by every deploy's `git reset --hard`.
 */
class FontManager
{
    private const DIRECTORY = 'card-fonts';

    private const MAX_BYTES_PER_FONT = 5 * 1024 * 1024;

    private const MAX_ZIP_BYTES = 30 * 1024 * 1024;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Accepts either a single `.ttf` or a `.zip` containing one or more —
     * the "set of TTFs" case, e.g. a whole family package downloaded from
     * a font foundry in one file. A zip that yields zero valid TTF
     * entries is refused outright; one that yields a mix of valid and
     * invalid entries stores the valid ones and reports the rest, rather
     * than discarding a whole family over one corrupt file.
     *
     * @return list<Font>
     */
    public function upload(?User $actor, UploadedFile $file, ?string $actingAs = null): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return match ($extension) {
            'ttf' => [$this->storeSingleTtf($actor, $file, $actingAs)],
            'zip' => $this->storeZipOfTtfs($actor, $file, $actingAs),
            default => throw new InvalidArgumentException('Upload a .ttf file, or a .zip containing one or more .ttf files.'),
        };
    }

    /**
     * Retire-then-set (rule 32/56's pattern, reused here): the previously
     * active font, if any, is deactivated in the same transaction before
     * this one is activated — the reverse order would violate
     * `uq_fonts_active` immediately.
     */
    public function activate(?User $actor, Font $font, ?string $actingAs = null): void
    {
        DB::transaction(function () use ($actor, $font, $actingAs) {
            $outgoing = Font::where('is_active', true)->where('id', '!=', $font->id)->lockForUpdate()->first();

            if ($outgoing !== null) {
                $outgoing->forceFill(['is_active' => false])->save();
            }

            $font->forceFill(['is_active' => true])->save();

            $this->auditLogger->log(
                actor: $actor,
                action: 'font_activated',
                subject: $font,
                previousValue: ['outgoing_font_id' => $outgoing?->id],
                newValue: ['name' => $font->name],
                actingAs: $actingAs,
            );
        });
    }

    public function deactivate(?User $actor, Font $font, ?string $actingAs = null): void
    {
        $font->forceFill(['is_active' => false])->save();

        $this->auditLogger->log(actor: $actor, action: 'font_deactivated', subject: $font, actingAs: $actingAs);
    }

    /**
     * Deleting the active font would silently degrade every subsequent
     * card render to the built-in fallback with no admin action that
     * looks like it caused it — refused for the same reason
     * `TemplateManager::delete()` refuses a template with issued cards:
     * the effect would be real but invisible at the point it happens.
     */
    public function delete(?User $actor, Font $font, ?string $actingAs = null): void
    {
        if ($font->is_active) {
            throw new InvalidArgumentException(
                "'{$font->name}' is the active font — deactivate it, or activate a different font, before deleting it."
            );
        }

        $this->auditLogger->log(actor: $actor, action: 'font_deleted', subject: $font, actingAs: $actingAs);

        $path = $font->storage_path;
        $font->delete();
        Storage::disk('local')->delete($path);
    }

    private function storeSingleTtf(?User $actor, UploadedFile $file, ?string $actingAs): Font
    {
        if ($file->getSize() === false || $file->getSize() > self::MAX_BYTES_PER_FONT) {
            throw new InvalidArgumentException('Font file must be 5MB or smaller.');
        }

        $bytes = (string) file_get_contents($file->getRealPath());
        $originalName = $file->getClientOriginalName();

        return $this->storeValidatedTtf($actor, $bytes, $originalName, $actingAs);
    }

    /**
     * @return list<Font>
     */
    private function storeZipOfTtfs(?User $actor, UploadedFile $file, ?string $actingAs): array
    {
        if ($file->getSize() === false || $file->getSize() > self::MAX_ZIP_BYTES) {
            throw new InvalidArgumentException('Zip file must be 30MB or smaller.');
        }

        $zip = new ZipArchive;

        if ($zip->open($file->getRealPath()) !== true) {
            throw new InvalidArgumentException('That zip file could not be opened.');
        }

        $stored = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);

            if ($entryName === false || ! str_ends_with(strtolower($entryName), '.ttf')) {
                continue;
            }

            $bytes = $zip->getFromIndex($i);

            if ($bytes === false || strlen($bytes) > self::MAX_BYTES_PER_FONT) {
                continue;
            }

            try {
                $stored[] = $this->storeValidatedTtf($actor, $bytes, basename($entryName), $actingAs);
            } catch (InvalidArgumentException) {
                // Not a real TTF despite the extension — skipped, not
                // fatal to the rest of the archive (see method docblock).
                continue;
            }
        }

        $zip->close();

        if ($stored === []) {
            throw new InvalidArgumentException('That zip contained no valid .ttf files.');
        }

        return $stored;
    }

    private function storeValidatedTtf(?User $actor, string $bytes, string $originalName, ?string $actingAs): Font
    {
        $this->assertLooksLikeTtf($bytes);

        $filename = self::DIRECTORY.'/'.Str::uuid()->toString().'.ttf';
        Storage::disk('local')->put($filename, $bytes);

        // The library check below needs a real filesystem path — GD's
        // TTF functions don't accept in-memory bytes — so it runs against
        // the file only after it's written to the disk it will actually
        // be read from at render time.
        if (! $this->gdCanUseFont(Storage::disk('local')->path($filename))) {
            Storage::disk('local')->delete($filename);

            throw new InvalidArgumentException("'{$originalName}' has the right file signature but GD couldn't use it as a font.");
        }

        $name = pathinfo($originalName, PATHINFO_FILENAME);

        $font = Font::create([
            'name' => $name,
            'original_filename' => $originalName,
            'storage_path' => $filename,
            'is_active' => false,
        ]);

        $this->auditLogger->log(
            actor: $actor,
            action: 'font_uploaded',
            subject: $font,
            newValue: ['name' => $name, 'original_filename' => $originalName],
            actingAs: $actingAs,
        );

        return $font;
    }

    /**
     * The sfnt magic number every real TTF/OTF starts with — the same
     * "check the actual bytes, not the client-supplied extension" shape
     * `PersonPhotoService` uses via `getimagesize()`, applied to a format
     * GD has no equivalent sniffing function for.
     */
    private function assertLooksLikeTtf(string $bytes): void
    {
        $header = substr($bytes, 0, 4);
        $validHeaders = ["\x00\x01\x00\x00", 'OTTO', 'true', 'ttcf'];

        if (! in_array($header, $validHeaders, true)) {
            throw new InvalidArgumentException('That file is not a valid TrueType or OpenType font.');
        }
    }

    /**
     * The real validation: does GD itself accept this file as a usable
     * font, not just "does it have the right magic bytes." A file can
     * pass the header check and still be a corrupt or unsupported font.
     */
    private function gdCanUseFont(string $absolutePath): bool
    {
        return @imagettfbbox(12, 0, $absolutePath, 'Aa') !== false;
    }
}
