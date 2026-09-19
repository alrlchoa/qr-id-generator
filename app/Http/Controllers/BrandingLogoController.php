<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the site logo (Phase 16) — the one file this system serves to
 * someone who hasn't signed in, because the login and setup pages show it
 * (CLAUDE.md 68). That's safe only because of what it is: a logo, public by
 * nature, stored as a PNG the server re-encoded itself, never an SVG.
 * Photos stay behind their authenticated route (rule 22); nothing else
 * joins this one.
 *
 * The URL carries ?v=<last save>, so the response can be cached forever: a
 * new logo gets a new URL.
 */
class BrandingLogoController extends Controller
{
    public function __invoke(): Response
    {
        $path = SiteSetting::current()->logo_path;

        if (! $path || ! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return response((string) Storage::disk('local')->get($path), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
