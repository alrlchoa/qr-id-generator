<?php

namespace App\Http\Controllers;

use App\Models\Font;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one authenticated route an uploaded card font is servable through —
 * used by the template placement editor to preview sample text in the
 * exact font a real card would use (`CardRenderer::activeFontPath()` reads
 * the same `storage_path`). Not privacy-sensitive like a photo (rule 22) —
 * a font file carries no personal data — so this is a plain policy-gated
 * stream rather than a base64 embed: a font can run several MB, and
 * inlining one into every page load the way `overlayDataUri()` does for a
 * small PNG would bloat the template editor badly.
 */
class FontFileController extends Controller
{
    public function __invoke(Font $font): StreamedResponse
    {
        Gate::authorize('view', $font);

        abort_unless(Storage::disk('local')->exists($font->storage_path), 404);

        return Storage::disk('local')->response($font->storage_path, $font->original_filename, [
            'Content-Type' => 'font/ttf',
        ]);
    }
}
