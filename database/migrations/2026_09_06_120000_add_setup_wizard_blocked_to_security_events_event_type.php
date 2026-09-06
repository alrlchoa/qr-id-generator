<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds `setup_wizard_blocked` to the security_events event_type CHECK.
 *
 * Kept distinct from `authorization_denied` (architecture §12) so that
 * someone probing the bootstrap route on a live system stays greppable on
 * its own, rather than being buried among every ordinary permission denial
 * in the system. Same reasoning §3 uses to keep the Superadmin audit
 * actions as distinct action names.
 *
 * Forward-only: the shipped migration is not edited (CLAUDE.md 26).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE security_events DROP CONSTRAINT chk_security_events_event_type');
        DB::statement("ALTER TABLE security_events ADD CONSTRAINT chk_security_events_event_type CHECK (event_type IN ('login_failed', 'authorization_denied', 'qr_verify_miss', 'setup_wizard_blocked'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE security_events DROP CONSTRAINT chk_security_events_event_type');
        DB::statement("ALTER TABLE security_events ADD CONSTRAINT chk_security_events_event_type CHECK (event_type IN ('login_failed', 'authorization_denied', 'qr_verify_miss'))");
    }
};
