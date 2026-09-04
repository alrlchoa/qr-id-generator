<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A card is two-sided. The app's job ends at producing a front and
        // back raster image per issued card — printing is a separate,
        // external workflow (a dedicated card-printer application), so this
        // table has no print-driver or printer-format concerns.
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('id_type');
            $table->string('name');
            $table->string('background_path_front');
            $table->string('background_path_back')->nullable();
            $table->unsignedInteger('width_px');
            $table->unsignedInteger('height_px');
            $table->jsonb('field_positions_front');
            $table->jsonb('field_positions_back')->nullable();
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
