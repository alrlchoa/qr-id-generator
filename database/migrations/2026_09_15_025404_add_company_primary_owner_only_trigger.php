<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13 security review — the DB-level backstop for CLAUDE.md rule 36
 * ("a company can only ever be a unit's primary owner"). Confirmed
 * 2026-09-09 (architecture §15) that this was app-layer only:
 * `RelationshipManager::openRelationship()` refuses a company outright,
 * but a raw `INSERT` into `person_unit_relationships` naming a company
 * with `type = 'tenant'`, or an ordinary `type = 'owner'` row
 * (`is_primary_owner = false`), bypasses it entirely and reads back
 * without error. A plain `CHECK` constraint can't reach this on its own —
 * `entity_type` lives on `people`, not this table — so it needs an
 * actual trigger function that looks the person up.
 *
 * Fires on both INSERT and UPDATE: the rule is "a company can only ever
 * be a primary owner," a standing state, not just an entry condition —
 * a row created correctly and later flipped to `is_primary_owner = false`
 * via a raw UPDATE would be the same violation reached a different way.
 */
return new class extends Migration
{
    public function up(): void
    {
        // CREATE OR REPLACE — see the append-only triggers migration's own
        // note on why a bare CREATE isn't safe against migrate:fresh.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_company_primary_owner_only() RETURNS trigger AS $$
            DECLARE
                v_entity_type text;
            BEGIN
                SELECT entity_type INTO v_entity_type FROM people WHERE id = NEW.person_id;

                IF v_entity_type = 'company' AND NOT COALESCE(NEW.is_primary_owner, false) THEN
                    RAISE EXCEPTION 'A company can only ever be a unit''s primary owner (person_id=%, unit_id=%)', NEW.person_id, NEW.unit_id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE TRIGGER trg_pur_company_primary_owner_only
                BEFORE INSERT OR UPDATE ON person_unit_relationships
                FOR EACH ROW EXECUTE FUNCTION enforce_company_primary_owner_only();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_pur_company_primary_owner_only ON person_unit_relationships');
        DB::statement('DROP FUNCTION IF EXISTS enforce_company_primary_owner_only()');
    }
};
