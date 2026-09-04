<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No `is_active` column: a vacant unit is not a deleted unit —
        // vacancy is a filter over relationships, not a stored state (§3).
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('building');
            $table->string('tower')->nullable();
            $table->string('floor')->nullable();
            $table->string('unit_number');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
