<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 18: while the system is unclaimed, a request through the Cloudflare
 * Tunnel is refused and recorded as `setup_via_tunnel_refused` — someone
 * on the internet reached a system nobody has set up yet
 * (EnsureSystemIsBootstrapped). Forward-only (CLAUDE.md rule 26) — drop and
 * recreate the check constraint, matching
 * `2026_09_07_122845_add_deletion_blocked_to_security_events_event_type.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE security_events DROP CONSTRAINT chk_security_events_event_type');
        DB::statement("ALTER TABLE security_events ADD CONSTRAINT chk_security_events_event_type CHECK (event_type IN ('login_failed', 'authorization_denied', 'qr_verify_miss', 'setup_wizard_blocked', 'deletion_blocked', 'setup_via_tunnel_refused'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE security_events DROP CONSTRAINT chk_security_events_event_type');
        DB::statement("ALTER TABLE security_events ADD CONSTRAINT chk_security_events_event_type CHECK (event_type IN ('login_failed', 'authorization_denied', 'qr_verify_miss', 'setup_wizard_blocked', 'deletion_blocked'))");
    }
};
