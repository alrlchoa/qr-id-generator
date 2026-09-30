<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * **[Changed, 2026-09-30 — explicit user decision.]** Building code
     * widens from exactly one letter to one or two, still optional, still
     * never padded — unlike `floor_code`/`unit_number`, which are always
     * left-padded to two characters, a one-letter building code stays
     * exactly one letter. `varchar`, not `char`: Postgres blank-pads a
     * `char(n)` value shorter than `n` with trailing spaces on storage and
     * retrieval, which would silently violate "no padding" for every
     * single-letter code the moment the column widens to 2. Forward-only
     * (CLAUDE.md rule 26) — `units` already shipped, so this alters rather
     * than edits `2026_09_04_141207_create_units_table.php`. Blueprint's
     * `->change()` needs doctrine/dbal, not installed here, so the type
     * change is a raw `ALTER COLUMN`, the same reason other migrations in
     * this codebase already use one (see `Person`'s docblock).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE units DROP CONSTRAINT chk_units_building_code');
        DB::statement('ALTER TABLE units ALTER COLUMN building_code TYPE varchar(2)');
        DB::statement("ALTER TABLE units ADD CONSTRAINT chk_units_building_code CHECK (building_code IS NULL OR building_code ~ '^[A-Z]{1,2}$')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE units DROP CONSTRAINT chk_units_building_code');
        DB::statement('ALTER TABLE units ALTER COLUMN building_code TYPE char(1)');
        DB::statement("ALTER TABLE units ADD CONSTRAINT chk_units_building_code CHECK (building_code IS NULL OR building_code ~ '^[A-Z]$')");
    }
};
