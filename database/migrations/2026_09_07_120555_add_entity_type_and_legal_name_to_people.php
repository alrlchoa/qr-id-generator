<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6, architecture §3 "Two kinds of party, one table." `people` now
 * carries companies as well as natural persons, distinguished by
 * `entity_type`. `first_name`/`last_name` drop their NOT NULL here (not in
 * the minimal-tier migration) because relaxing them only makes sense
 * alongside the kind that doesn't use them — a `natural` row still needs
 * both, enforced by the check constraint below, not the column.
 *
 * Existing rows all have first_name + last_name already, so backfilling
 * `entity_type = 'natural'` (the column default) leaves every row
 * consistent with the constraint the moment it's added.
 *
 * New columns go through the Blueprint DSL, not raw SQL — Larastan's model
 * property extension parses migration files statically (it never queries a
 * live database) and only understands `Schema::table()`/`Blueprint` calls,
 * so a column added via `DB::statement()` alone is invisible to it. Only
 * the NOT NULL drops (an ALTER on existing columns, which needs
 * doctrine/dbal for the Blueprint `->change()` form — not installed here)
 * and the CHECK constraints (which Blueprint has no syntax for at all) stay
 * raw, matching this codebase's existing convention.
 *
 * Forward-only (CLAUDE.md rule 26).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE people ALTER COLUMN first_name DROP NOT NULL');
        DB::statement('ALTER TABLE people ALTER COLUMN last_name DROP NOT NULL');

        Schema::table('people', function (Blueprint $table) {
            $table->string('entity_type')->default('natural');
            $table->string('legal_name')->nullable();
        });

        DB::statement("ALTER TABLE people ADD CONSTRAINT chk_people_entity_type CHECK (entity_type IN ('natural', 'company'))");

        DB::statement(<<<'SQL'
            ALTER TABLE people ADD CONSTRAINT chk_people_entity_type_fields CHECK (
                (entity_type = 'natural' AND first_name IS NOT NULL AND last_name IS NOT NULL AND legal_name IS NULL)
                OR
                (entity_type = 'company' AND legal_name IS NOT NULL AND first_name IS NULL AND middle_name IS NULL AND last_name IS NULL AND suffix IS NULL)
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE people DROP CONSTRAINT chk_people_entity_type_fields');
        DB::statement('ALTER TABLE people DROP CONSTRAINT chk_people_entity_type');

        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn(['legal_name', 'entity_type']);
        });

        DB::statement('ALTER TABLE people ALTER COLUMN last_name SET NOT NULL');
        DB::statement('ALTER TABLE people ALTER COLUMN first_name SET NOT NULL');
    }
};
