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

        if ($user && $user->must_change_password
            && ! $request->routeIs('password.change')
            && ! $request->routeIs('livewire.*')) {
            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
