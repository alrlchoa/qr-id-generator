<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsCurrent
{
    /**
     * Force a rotation before any other authenticated route is reachable.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Matched on path, not route name: Livewire's own endpoints are not
        // named 'livewire.*' (the update endpoint is 'default.livewire.update',
        // and its asset route has no name at all — see
        // EnsureSystemIsBootstrapped for the full story). Redirecting either
        // one leaves the change-password form rendered but unsubmittable:
        // wire:submit posts to /livewire/update, which this middleware would
        // otherwise bounce back to the very page that request came from.
        if ($user && $user->must_change_password
            && ! $request->routeIs('password.change')
            && ! $request->is('livewire/*')) {
            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
