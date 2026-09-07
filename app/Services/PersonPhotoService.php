<?php

namespace App\Services;

use App\Models\Person;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The photo pipeline (Phase 6 plan): validate, crop 1:1, compress, store on
 * the private `local` disk under a UUID filename. No public disk, no
 * `storage:link`, no signed URL, no base64 (CLAUDE.md rule 22) — the only
 * way back to the bytes is `PersonPhotoController`, one authenticated route
 * with a policy check per request.
 */
class PersonPhotoService
{
    private const DIRECTORY = 'people-photos';

    private const MAX_BYTES = 1024 * 1024;

    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png'];

    /**
     * Store a new or replacement photo for $person, unlinking the previous
     * file (if any) once the new one is safely written. Returns the stored
     * path — caller is responsible for saving it onto the Person.
     */
    public function store(Person $person, UploadedFile $file): string
    {
        $this->validate($file);

        $cropped = $this->cropToSquareAndCompress($file->getRealPath());

        $filename = self::DIRECTORY.'/'.Str::uuid()->toString().'.jpg';

        Storage::disk('local')->put($filename, $cropped);

        $previousPath = $person->photo_path;

        if ($previousPath && $previousPath !== $filename) {
            Storage::disk('local')->delete($previousPath);
        }

        return $filename;
    }

    private function validate(UploadedFile $file): void
    {
        if ($file->getSize() === false || $file->getSize() > self::MAX_BYTES) {
            throw new InvalidArgumentException('Photo must be 1MB or smaller.');
        }

        // MIME sniff — the actual bytes, not the client-supplied extension
        // or Content-Type header, both of which are attacker-controlled.
        $sniffed = @getimagesize($file->getRealPath());

        if ($sniffed === false || ! in_array($sniffed['mime'], self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidArgumentException('Photo must be a JPEG or PNG image.');
        }
    }

    /**
     * Crop to a centered 1:1 square and re-encode as compressed JPEG.
     */
    private function cropToSquareAndCompress(string $path): string
    {
        $source = imagecreatefromstring((string) file_get_contents($path));

        if ($source === false) {
            throw new InvalidArgumentException('Photo could not be read as an image.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $srcX = intdiv($width - $side, 2);
        $srcY = intdiv($height - $side, 2);

        $square = imagecreatetruecolor($side, $side);
        imagecopyresampled($square, $source, 0, 0, $srcX, $srcY, $side, $side, $side, $side);
        imagedestroy($source);

        ob_start();
        imagejpeg($square, null, 75);
        $encoded = ob_get_clean();
        imagedestroy($square);

        if ($encoded === false) {
            throw new InvalidArgumentException('Photo could not be processed.');
        }

        return $encoded;
    }

    public function delete(Person $person): void
    {
        if ($person->photo_path) {
            Storage::disk('local')->delete($person->photo_path);
        }
    }

    /**
     * The stored file's actual size on disk — after crop/compress, not
     * whatever was originally uploaded. Null when there's no photo to
     * measure.
     */
    public function sizeInBytes(Person $person): ?int
    {
        if (! $person->photo_path || ! Storage::disk('local')->exists($person->photo_path)) {
            return null;
        }

        return Storage::disk('local')->size($person->photo_path);
    }
}
