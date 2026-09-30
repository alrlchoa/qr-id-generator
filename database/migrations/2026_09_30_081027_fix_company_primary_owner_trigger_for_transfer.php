<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * **Bug fix, 2026-09-30.** `enforce_company_primary_owner_only()`
 * (2026_09_15_025404) fires on every UPDATE that flips a company's
 * `is_primary_owner` to false — including
 * `UnitLifecycleManager::transferPrimaryOwnership()`'s own retire step,
 * which sets `is_primary_owner = false` and `ended_at = now()` in the
 * *same* statement. The trigger couldn't tell "this relationship is being
 * closed" from "this relationship continues as an ordinary non-primary
 * one" — the second is the actual violation rule 36 exists to prevent, the
 * first is routine history. The practical effect: transferring ownership
 * away from any company-owned unit raised
 * `SQLSTATE[P0001]: A company can only ever be a unit's primary owner`
 * and 500'd, unconditionally, for every such unit — never caught by a
 * test, since the existing tests only cover a company *becoming* primary
 * owner, not ownership moving away from one.
 *
 * Fix: the check now only fires while the row stays open
 * (`NEW.ended_at IS NULL`). `promotePrimaryOwner()` is unaffected and
 * still correctly refused for a company outgoing owner — promotion never
 * sets `ended_at`, so demoting a company to an ongoing co-owner
 * relationship (the real violation) is still caught.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_company_primary_owner_only() RETURNS trigger AS $$
            DECLARE
                v_entity_type text;
            BEGIN
                SELECT entity_type INTO v_entity_type FROM people WHERE id = NEW.person_id;

                IF v_entity_type = 'company' AND NOT COALESCE(NEW.is_primary_owner, false) AND NEW.ended_at IS NULL THEN
                    RAISE EXCEPTION 'A company can only ever be a unit''s primary owner (person_id=%, unit_id=%)', NEW.person_id, NEW.unit_id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
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
    }
};
