<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\SecurityEvent;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one authenticated route a photo is servable through (CLAUDE.md rule
 * 22, architecture §9.2). No public disk, no symlink, no signed URL, no
 * base64 — a policy check (`viewPhoto`, not the broader `view`) runs on
 * every request, not once at page load, so a Reader's 60-second window
 * (rule 23) is enforced per-request rather than per-session-load.
 */
class PersonPhotoController extends Controller
{
    public function __invoke(Person $person): StreamedResponse|Response
    {
        if (Gate::denies('viewPhoto', $person)) {
            SecurityEvent::create([
                'occurred_at' => now(),
                'user_id' => auth()->id(),
                'event_type' => 'authorization_denied',
                'detail' => ['person_id' => $person->id, 'route' => 'people.photo'],
                'ip_address' => request()->ip(),
            ]);

            abort(403);
        }

        if (! $person->photo_path || ! Storage::disk('local')->exists($person->photo_path)) {
            abort(404);
        }

        return Storage::disk('local')->response($person->photo_path);
    }
}
