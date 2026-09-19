<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks the session and XSRF cookies Secure whenever the request arrived
 * over HTTPS — directly on the LAN (Caddy's :443), or at Cloudflare's edge
 * and then through the tunnel over plain HTTP, which X-Forwarded-Proto
 * reports. One setting can't be right for both: `true` would drop the
 * cookie on any plain-HTTP request, `false` would send it unmarked over
 * HTTPS.
 *
 * Global middleware, appended after TrustProxies — so isSecure() already
 * reads X-Forwarded-Proto — and running before the web group's
 * StartSession and VerifyCsrfToken, which read the setting when they write
 * their cookies. SESSION_SECURE_COOKIE, if set in .env, still wins.
 */
class SecureCookiesOverHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('session.secure') === null) {
            config(['session.secure' => $request->isSecure()]);
        }

        return $next($request);
    }
}
