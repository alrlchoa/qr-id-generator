<?php

use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\SecurityEventLogger;

/**
 * Phase 14: SecurityEventLogger is the one writer of security_events, the way
 * AuditLogger is for audit_logs (CLAUDE.md rules 43, 46). Each caller is
 * covered by its own feature test; these pin the logger's own contract.
 */
test('writes one row with the given event type, user and detail, deriving occurred_at', function () {
    $user = User::factory()->create();
    $this->freezeSecond();

    $event = app(SecurityEventLogger::class)->log('login_failed', $user, ['username' => $user->username]);

    expect(SecurityEvent::count())->toBe(1)
        ->and($event->event_type)->toBe('login_failed')
        ->and($event->user_id)->toBe($user->id)
        ->and($event->detail)->toBe(['username' => $user->username])
        ->and($event->occurred_at->equalTo(now()))->toBeTrue();
});

test('needs no user — a security event is usually about someone unauthenticated or refused', function () {
    $event = app(SecurityEventLogger::class)->log('setup_wizard_blocked', null, ['route' => 'setup']);

    expect($event->user_id)->toBeNull();
});

test('records no IP when running in the console, the same way AuditLogger does (rule 46)', function () {
    // Pest itself runs in the console, so this is the console branch. The
    // web branch — request()->ip() — only differs inside a real HTTP request.
    $event = app(SecurityEventLogger::class)->log('qr_verify_miss', null, ['attempted_value' => '00000000']);

    expect($event->ip_address)->toBeNull();
});
