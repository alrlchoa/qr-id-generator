<?php

namespace App\Services;

use App\Models\Person;
use Closure;
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
     * The stored photo's side, at most. The card is 1011 × 638 at 300 DPI,
     * so no photo box can use more; a bigger file only costs memory every
     * time a card renders.
     */
    public const MAX_STORED_SIDE = 1024;

    /**
     * The most pixels a source image may have. 1 MB is no guard on its
     * own: a compressed JPEG that small can be a 24-megapixel photo, and
     * GD decodes every pixel into memory. Measured with the framework
     * loaded: a 12 MP phone photo peaks near 74 MB, 16 MP near 90 MB, and
     * 24 MP at 127 MB — against PHP's default 128 MB limit. 16 MP leaves
     * room and still takes an ordinary phone photo.
     */
    public const MAX_SOURCE_PIXELS = 16_000_000;

    /**
     * Store a new or replacement photo for $person, unlinking the previous
     * file (if any) once the new one is safely written. Returns the stored
     * path — caller is responsible for saving it onto the Person.
     */
    /**
     * The photo field's validation rules — one definition for every form
     * that takes a photo, so the pixel limit refuses the upload as a field
     * error before anything is saved, instead of as an exception halfway
     * through a save. `validate()` below still checks the same limits, as
     * the backstop for any caller that skips these.
     *
     * @return array<int, mixed>
     */
    public static function rules(): array
    {
        return [
            'nullable',
            'image',
            'max:'.intdiv(self::MAX_BYTES, 1024),
            function (string $attribute, mixed $value, Closure $fail): void {
                $size = $value instanceof UploadedFile ? @getimagesize((string) $value->getRealPath()) : false;

                if ($size !== false && ($message = self::pixelLimitMessage($size[0], $size[1])) !== null) {
                    $fail($message);
                }
            },
        ];
    }

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

        // Read from the header, before anything is decoded — decoding is
        // what would exhaust memory.
        $message = self::pixelLimitMessage($sniffed[0], $sniffed[1]);

        if ($message !== null) {
            throw new InvalidArgumentException($message);
        }
    }

    private static function pixelLimitMessage(int $width, int $height): ?string
    {
        if ($width * $height <= self::MAX_SOURCE_PIXELS) {
            return null;
        }

        $megapixels = number_format($width * $height / 1_000_000, 1);
        $max = self::MAX_SOURCE_PIXELS / 1_000_000;

        return "Photo can be at most {$max} megapixels — this one is {$width} × {$height} ({$megapixels} MP). Resize it (for example to 2000 pixels wide) and try again.";
    }

    /**
     * Crop to a centered 1:1 square, scale it down to MAX_STORED_SIDE if
     * it's bigger, and re-encode as compressed JPEG. The crop and the
     * scale are one resample, so there's never a second full-size image
     * in memory beside the decoded source.
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

        $target = min($side, self::MAX_STORED_SIDE);

        $square = imagecreatetruecolor($target, $target);
        imagecopyresampled($square, $source, 0, 0, $srcX, $srcY, $target, $target, $side, $side);
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
