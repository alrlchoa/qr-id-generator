<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('id_type');
            $table->string('name');
            $table->string('background_path');
            $table->unsignedInteger('width_px');
            $table->unsignedInteger('height_px');
            $table->jsonb('field_positions');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE templates ADD CONSTRAINT chk_templates_id_type CHECK (id_type IN ('owner', 'tenant', 'employee'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
