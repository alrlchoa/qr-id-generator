<?php

namespace App\Http\Controllers;

use App\Models\Person;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one authenticated route a photo is servable through (CLAUDE.md rule
 * 22, architecture §9.2). No public disk, no symlink, no signed URL, no
 * base64 — a policy check runs on every request, not once at page load.
 */
class PersonPhotoController extends Controller
{
    public function __invoke(Person $person): StreamedResponse|Response
    {
        Gate::authorize('view', $person);

        if (! $person->photo_path || ! Storage::disk('local')->exists($person->photo_path)) {
            abort(404);
        }

        return Storage::disk('local')->response($person->photo_path);
    }
}
