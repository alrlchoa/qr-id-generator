<?php

namespace App\Services;

use App\Models\SecurityEvent;
use App\Models\User;

/**
 * The one call-site shape for `security_events` — `AuditLogger`'s sibling.
 * Until Phase 14, seven callers each wrote `SecurityEvent::create()` with the
 * same five columns spelled out by hand, and derived the IP two different
 * ways. `occurred_at` and `ip_address` are derived here, never passed,
 * exactly as rule 46 already requires for audit rows: a console run has no
 * request to read an IP from, so `app()->runningInConsole()` decides that —
 * not a caller remembering to.
 *
 * Unlike `AuditLogger`, there is no actor requirement. A security event is
 * usually about someone who isn't authenticated or isn't allowed: a failed
 * login names the account that was tried, if one exists; a blocked visit to
 * the setup wizard usually has no user at all. `$user` is whoever the event
 * concerns, or null.
 */
class SecurityEventLogger
{
    /**
     * @param  array<string, mixed>  $detail
     */
    public function log(string $eventType, ?User $user, array $detail): SecurityEvent
    {
        return SecurityEvent::create([
            'occurred_at' => now(),
            'user_id' => $user?->id,
            'event_type' => $eventType,
            'detail' => $detail,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
