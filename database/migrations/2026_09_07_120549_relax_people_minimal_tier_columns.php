<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6, architecture §3 "Profile completeness — three tiers, one table."
 * The column-level NOT NULL set shrinks to the Minimal tier: a name for the
 * kind is all a `people` row needs to exist. Everything relaxed here is
 * required again by the *operation* that needs it (contactable for a
 * primary owner, cardable for card issuance) — never by the schema, and
 * never retroactively against rows already stored (CLAUDE.md rule 33).
 *
 * Forward-only (CLAUDE.md rule 26) — the original NOT NULL columns shipped
 * in 2026_09_04_141158_create_people_table.php and are never edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE people ALTER COLUMN photo_path DROP NOT NULL');
        DB::statement('ALTER TABLE people ALTER COLUMN gender DROP NOT NULL');
        DB::statement('ALTER TABLE people ALTER COLUMN home_address DROP NOT NULL');
        DB::statement('ALTER TABLE people ALTER COLUMN mobile_number DROP NOT NULL');
        DB::statement('ALTER TABLE people ALTER COLUMN email DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE people ALTER COLUMN photo_path SET NOT NULL');
        DB::statement('ALTER TABLE people ALTER COLUMN gender SET NOT NULL');
        DB::statement('ALTER TABLE people ALTER COLUMN home_address SET NOT NULL');
        DB::statement('ALTER TABLE people ALTER COLUMN mobile_number SET NOT NULL');
        DB::statement('ALTER TABLE people ALTER COLUMN email SET NOT NULL');
    }
};
