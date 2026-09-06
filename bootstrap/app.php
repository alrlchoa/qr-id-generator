<?php

use App\Http\Middleware\EnsurePasswordIsCurrent;
use App\Http\Middleware\EnsureSystemIsBootstrapped;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The reverse proxy (Caddy/Nginx) terminates TLS, not Laravel (§12).
        // '*' is safe here because the proxy is on infrastructure we
        // control end-to-end (a sibling LXC on the same private LAN) — this
        // is never a public-internet-facing deployment.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Order matters: the bootstrap gate runs first, so an unbootstrapped
        // system routes everyone to the wizard before any auth-dependent
        // middleware gets a chance to redirect them to a login they cannot
        // yet use.
        $middleware->web(append: [
            EnsureSystemIsBootstrapped::class,
            EnsurePasswordIsCurrent::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
