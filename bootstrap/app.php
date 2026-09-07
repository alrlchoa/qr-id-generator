<?php

use App\Http\Middleware\EnsurePasswordIsCurrent;
use App\Http\Middleware\EnsureSystemIsBootstrapped;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
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
        //
        // Registration order alone does not guarantee this. Laravel sorts
        // the merged group + route middleware for each request against a
        // fixed priority list (see Middleware::$priority) whenever a route
        // mixes both — which every 'auth'-protected route here does. Custom
        // middleware absent from that list keeps its *registration* position
        // but can still end up running after a *listed* middleware like
        // Authenticate, silently. That happened here: with only `append()`,
        // this gate never ran at all for /dashboard, /profile, or any other
        // 'auth' route — confirmed by logging inside the middleware and
        // watching it never fire for those requests, only for routes with
        // no other middleware to sort against.
        $middleware->web(append: [
            EnsureSystemIsBootstrapped::class,
            EnsurePasswordIsCurrent::class,
        ]);

        // The `before` target has to be the CONTRACT, not the concrete
        // class: Laravel's own default priority list names
        // `AuthenticatesRequests` (the interface `Authenticate` implements),
        // not `Illuminate\Auth\Middleware\Authenticate` itself. Passing the
        // concrete class here matches nothing in that list, and
        // prependToPriorityList() fails silently rather than erroring —
        // it just appends to the *end* of the priority list instead of
        // inserting before the target, which is indistinguishable from
        // "did nothing" for a middleware that needs to run *early*.
        // Confirmed by dumping Kernel::$middlewarePriority at runtime.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: EnsureSystemIsBootstrapped::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
