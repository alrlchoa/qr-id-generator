<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7, architecture §13: a blocked deletion attempt writes
 * `deletion_blocked` to `security_events` (CLAUDE.md rule 45 — a refused
 * mutation writes no `audit_logs` row; this is the existing pattern for the
 * case that's worth recording). Forward-only (CLAUDE.md rule 26) — drop and
 * recreate the check constraint, matching
 * `2026_09_06_120000_add_setup_wizard_blocked_to_security_events_event_type.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE security_events DROP CONSTRAINT chk_security_events_event_type');
        DB::statement("ALTER TABLE security_events ADD CONSTRAINT chk_security_events_event_type CHECK (event_type IN ('login_failed', 'authorization_denied', 'qr_verify_miss', 'setup_wizard_blocked', 'deletion_blocked'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE security_events DROP CONSTRAINT chk_security_events_event_type');
        DB::statement("ALTER TABLE security_events ADD CONSTRAINT chk_security_events_event_type CHECK (event_type IN ('login_failed', 'authorization_denied', 'qr_verify_miss', 'setup_wizard_blocked'))");
    }
};
