<?php

use App\Models\SecurityEvent;
use Illuminate\Support\Facades\Route;

/**
 * Phase 18: the app behind a Cloudflare Tunnel. Cloudflare's edge ends
 * TLS and cloudflared hands the request to Caddy's :80 over plain HTTP,
 * with the public hostname as Host and these headers set by Cloudflare.
 */
function throughTunnel(): array
{
    return [
        'X-Forwarded-Proto' => 'https',
        'Cf-Ray' => '8c0ffee0-SIN',
        'Cf-Connecting-IP' => '203.0.113.9',
    ];
}

test('through the tunnel, redirects go to the public hostname over https', function () {
    bootstrapSystem();

    $this->withHeaders(throughTunnel())
        ->get('http://ids.example.com/dashboard')
        ->assertRedirect('https://ids.example.com/login');
});

test('the same app on the LAN redirects to the LAN address', function () {
    bootstrapSystem();

    $this->get('https://192.168.100.177/dashboard')
        ->assertRedirect('https://192.168.100.177/login');
});

test('a forged X-Forwarded-Host cannot move where a redirect points', function () {
    bootstrapSystem();

    $this->withHeaders(throughTunnel() + ['X-Forwarded-Host' => 'evil.example'])
        ->get('http://ids.example.com/dashboard')
        ->assertRedirect('https://ids.example.com/login');
});

test('the client IP is the one Cloudflare saw, not the connector\'s', function () {
    Route::get('/_test/ip', fn () => request()->ip());

    $this->withHeaders(throughTunnel())
        ->get('http://ids.example.com/_test/ip')
        ->assertSeeText('203.0.113.9');
});

test('a forged X-Forwarded-For does not change the client IP', function () {
    Route::get('/_test/ip', fn () => request()->ip());

    $this->withHeaders(['X-Forwarded-For' => '198.51.100.7'])
        ->get('/_test/ip')
        ->assertSeeText('127.0.0.1');
});

test('an invalid Cf-Connecting-IP is ignored', function () {
    Route::get('/_test/ip', fn () => request()->ip());

    $this->withHeaders(['Cf-Connecting-IP' => 'not-an-ip'])
        ->get('/_test/ip')
        ->assertSeeText('127.0.0.1');
});

test('cookies are Secure over HTTPS through the tunnel, and not over plain HTTP', function () {
    bootstrapSystem();

    $xsrf = fn ($response) => collect($response->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === 'XSRF-TOKEN');

    $viaTunnel = $this->withHeaders(throughTunnel())->get('http://ids.example.com/login')->assertOk();
    expect($xsrf($viaTunnel)->isSecure())->toBeTrue();

    $this->flushHeaders();
    config(['session.secure' => null]);

    $plain = $this->get('http://192.168.100.177/login')->assertOk();
    expect($xsrf($plain)->isSecure())->toBeFalse();
});

test('an unclaimed system refuses the setup wizard through the tunnel, and records it', function () {
    $this->withHeaders(throughTunnel())
        ->get('http://ids.example.com/setup')
        ->assertForbidden()
        ->assertSee('Finish the first-run setup from the local network');

    // Its ip_address is null here only because the logger records none
    // under the console, which a test run is (rule 46); the client-IP tests
    // above cover what a real request records.
    expect(SecurityEvent::where('event_type', 'setup_via_tunnel_refused')->exists())->toBeTrue();
});

test('an unclaimed system refuses every page through the tunnel, not only the wizard', function () {
    $this->withHeaders(throughTunnel())
        ->get('http://ids.example.com/login')
        ->assertForbidden();
});

test('an unclaimed system still answers the health check through the tunnel', function () {
    $this->withHeaders(throughTunnel())
        ->get('http://ids.example.com/up')
        ->assertOk();
});

test('an unclaimed system still serves the setup wizard on the LAN', function () {
    $this->get('https://192.168.100.177/setup')->assertOk();
});
