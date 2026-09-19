<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A request through the Cloudflare Tunnel (Phase 18) reaches PHP from the
 * cloudflared connector, so without this every visitor from the internet
 * would share the connector's LAN address — one login throttle bucket for
 * everyone, and audit rows that all name the same machine.
 *
 * Cloudflare's edge sets Cf-Connecting-IP to the address it actually saw,
 * replacing any value the visitor sent, so through the tunnel it can't be
 * chosen by the visitor. X-Forwarded-For can: Cloudflare appends to
 * whatever the visitor put there, and with every proxy trusted Laravel would
 * read the visitor's own first entry. That's why bootstrap/app.php trusts
 * only X-Forwarded-Proto, and the client IP comes from here instead.
 *
 * A machine on the LAN can still send this header itself and choose its
 * own address — the same trust the LAN has always had, and never used to
 * grant anything.
 */
class UseCloudflareClientIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->headers->get('Cf-Connecting-IP');

        if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            $request->server->set('REMOTE_ADDR', $ip);
        }

        return $next($request);
    }
}
