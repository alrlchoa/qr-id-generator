<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13 security review — Approach B audit immutability (architecture
 * §15, CLAUDE.md rule 8). App-layer immutability already exists
 * (`AppendOnly` trait, Phase 4) — this is the DB-level backstop.
 *
 * A plain `REVOKE UPDATE, DELETE ON audit_logs FROM <app_user>` was the
 * originally planned shape, but checked against this deployment before
 * writing it: the app's own DB user (`qrid`) is these tables' owner
 * (`SELECT tableowner FROM pg_tables`), and PostgreSQL's REVOKE has no
 * effect on a table's owner — ownership grants every privilege
 * unconditionally, REVOKE or not. A REVOKE statement here would run
 * without error and protect nothing, which is worse than doing nothing:
 * it would read as a real backstop in a security review while being pure
 * theater. Achieving the grant-based split needs a second, non-owner
 * runtime DB role the app connects as day-to-day (owner reserved for
 * `migrate`) — a deployment-topology change, not a migration, and out of
 * scope for this phase; flagged for Phase 14 (production cutover) instead.
 *
 * A `BEFORE UPDATE OR DELETE` trigger has no such gap — it fires for
 * every writer against the table, ownership included, so it is the one
 * layer that actually backstops the app-layer guard under the current
 * single-role deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        // CREATE OR REPLACE, not a bare CREATE: Laravel's migrate:fresh drops
        // and recreates tables on Postgres but leaves standalone functions
        // alone, so a function from a previous migrate:fresh's run of this
        // same migration can still be sitting there — confirmed directly
        // during this phase's own work, a bare CREATE FUNCTION collided
        // with exactly that leftover. CREATE OR REPLACE TRIGGER (PG 14+,
        // this deployment runs 16) gets the same idempotency for the
        // triggers themselves.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_append_only_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only — % is not permitted', TG_TABLE_NAME, TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE TRIGGER trg_audit_logs_append_only
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION reject_append_only_mutation();
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE TRIGGER trg_security_events_append_only
                BEFORE UPDATE OR DELETE ON security_events
                FOR EACH ROW EXECUTE FUNCTION reject_append_only_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_audit_logs_append_only ON audit_logs');
        DB::statement('DROP TRIGGER IF EXISTS trg_security_events_append_only ON security_events');
        DB::statement('DROP FUNCTION IF EXISTS reject_append_only_mutation()');
    }
};
