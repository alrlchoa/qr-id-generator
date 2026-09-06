<?php

namespace App\Http\Middleware;

use App\Models\SecurityEvent;
use App\Services\SystemBootstrap;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the whole application on the first-run wizard (architecture §12).
 *
 * Two directions, both enforced here so neither depends on a route
 * remembering to opt in:
 *
 *  - While no active Superadmin exists, every route — login included —
 *    redirects to the wizard. There is no window in which a
 *    half-configured system serves an ordinary page.
 *  - Once one exists, the wizard route itself refuses. Not hidden, not
 *    password-gated: the route 404s, and the attempt is recorded.
 */
class EnsureSystemIsBootstrapped
{
    public function __construct(private readonly SystemBootstrap $bootstrap) {}

    public function handle(Request $request, Closure $next): Response
    {
        $needsBootstrap = $this->bootstrap->needsBootstrap();
        $isSetupRoute = $request->routeIs('setup');

        if ($needsBootstrap) {
            // Livewire's own endpoints have to stay reachable or the wizard
            // cannot work at all: its script tag points at
            // /livewire/livewire.min.js and its submit posts to
            // /livewire/update. Redirect either one and the page still
            // renders, but wire:submit is inert — the form does a native
            // browser submit, reloads, and looks like a dead button.
            //
            // Matched on PATH, not route name. The names are not what you
            // would guess: the update endpoint is `default.livewire.update`,
            // not `livewire.update`, and the asset route has no name at all,
            // so `routeIs('livewire.*')` silently matched neither. The path
            // prefix is stable and is what Livewire's own client requests.
            //
            // The health endpoint stays reachable because the Phase 2 deploy
            // verifies /up before anyone opens a browser; gating it would
            // make a correct deployment look like a failed one (§12).
            if ($isSetupRoute || $request->is('livewire/*') || $request->is('up')) {
                return $next($request);
            }

            return redirect()->route('setup');
        }

        if ($isSetupRoute) {
            SecurityEvent::create([
                'occurred_at' => now(),
                'user_id' => $request->user()?->id,
                'event_type' => 'setup_wizard_blocked',
                'detail' => ['route' => $request->path()],
                'ip_address' => $request->ip(),
            ]);

            abort(404);
        }

        return $next($request);
    }
}
