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
        // A single unique code, not structured building/tower/floor columns:
        // the numbering scheme is configurable, not hard-coded (§3), and the
        // condo's own convention already encodes location in the code itself
        // (e.g. "M06" = Mezzanine 06, "A1223" = Building A, 12th floor, unit
        // 23). The only fixed invariant across every format observed is that
        // the code always ends in the 2-digit unit number.
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('unit_code')->unique('uq_units_unit_code');
            $table->softDeletes();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE units ADD CONSTRAINT chk_units_unit_code_format CHECK (unit_code ~ '[0-9]{2}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
