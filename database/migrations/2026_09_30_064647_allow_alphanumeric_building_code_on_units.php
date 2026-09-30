<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * **[Changed, 2026-09-30 — explicit user decision.]** Building code
     * widens from letters-only to alphanumeric — "1A", "2A" and similar
     * are now valid — still 1-2 characters, still optional, still never
     * padded (unchanged from the previous widening,
     * `2026_09_30_062610_widen_building_code_on_units.php`). Forward-only
     * (CLAUDE.md rule 26).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE units DROP CONSTRAINT chk_units_building_code');
        DB::statement("ALTER TABLE units ADD CONSTRAINT chk_units_building_code CHECK (building_code IS NULL OR building_code ~ '^[A-Z0-9]{1,2}$')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE units DROP CONSTRAINT chk_units_building_code');
        DB::statement("ALTER TABLE units ADD CONSTRAINT chk_units_building_code CHECK (building_code IS NULL OR building_code ~ '^[A-Z]{1,2}$')");
    }
};
