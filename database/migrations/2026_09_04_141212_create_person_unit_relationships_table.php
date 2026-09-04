<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_unit_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people');
            $table->foreignId('unit_id')->constrained('units');
            $table->string('type');
            $table->date('start_date');
            // Informational only — never drives capacity, validity, or card
            // status. `ended_at` is the sole source of truth for activity.
            $table->date('contract_end_date')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE person_unit_relationships ADD CONSTRAINT chk_pur_type CHECK (type IN ('owner', 'tenant'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('person_unit_relationships');
    }
};
