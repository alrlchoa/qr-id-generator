<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No `is_active` column: a vacant unit is not a deleted unit —
        // vacancy is a filter over relationships, not a stored state (§3).
        //
        // Unit codes follow the fixed shape ABBCC: A = building code (a
        // single letter, nullable — omitted from the code entirely when
        // null), BB = 2-character floor code, CC = 2-digit unit number.
        // floor_code/unit_number are always stored left-padded to 2
        // characters (padding is applied by the model's mutators — see
        // App\Models\Unit); the check constraints below are the DB-level
        // backstop for writes that bypass the model.
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->char('building_code', 1)->nullable();
            $table->char('floor_code', 2);
            $table->char('unit_number', 2);
            $table->softDeletes();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE units ADD CONSTRAINT chk_units_building_code CHECK (building_code IS NULL OR building_code ~ '^[A-Z]$')");
        DB::statement("ALTER TABLE units ADD CONSTRAINT chk_units_floor_code CHECK (floor_code ~ '^[A-Z0-9]{2}$')");
        DB::statement("ALTER TABLE units ADD CONSTRAINT chk_units_unit_number CHECK (unit_number ~ '^[0-9]{2}$')");

        // A plain composite unique constraint wouldn't work here: Postgres
        // treats NULL as distinct from NULL, so two units with no building
        // code but the same floor/unit would both be allowed. COALESCE-ing
        // building_code to '' in the index makes "no building" a real,
        // deduplicated value instead of a uniqueness loophole.
        DB::statement("CREATE UNIQUE INDEX uq_units_code ON units (COALESCE(building_code, ''), floor_code, unit_number)");
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
