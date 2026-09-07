<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7, architecture §3/§5.4: every unit has exactly one primary owner,
 * always. "At most one" is a database guarantee — the partial unique index
 * below, checked at statement end, unconditionally, because a unique
 * *index* (unlike a unique *constraint*) is never deferrable in Postgres.
 * "At least one" cannot be expressed declaratively (it would need "this row
 * must have a partner") and is an application-layer check inside the
 * transaction that would otherwise remove the last one (CLAUDE.md rule 30).
 *
 * Forward-only (CLAUDE.md rule 26).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('person_unit_relationships', function (Blueprint $table) {
            $table->boolean('is_primary_owner')->default(false);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX uq_pur_one_primary_owner_per_unit
            ON person_unit_relationships (unit_id)
            WHERE is_primary_owner IS TRUE AND ended_at IS NULL
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE person_unit_relationships ADD CONSTRAINT chk_pur_primary_owner_is_owner_type
            CHECK (is_primary_owner IS FALSE OR type = 'owner')
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE person_unit_relationships DROP CONSTRAINT chk_pur_primary_owner_is_owner_type');
        DB::statement('DROP INDEX uq_pur_one_primary_owner_per_unit');

        Schema::table('person_unit_relationships', function (Blueprint $table) {
            $table->dropColumn('is_primary_owner');
        });
    }
};
