<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 12 follow-up: a Superadmin-uploaded TrueType font for card
     * rendering, closing the "no font bundled" gap `CardRenderer` shipped
     * with — that fallback (GD's five built-in bitmap sizes) stays exactly
     * as the code path taken when no font is active, never removed.
     *
     * At most one font may be active at a time — the one `CardRenderer`
     * actually uses — the same "at most one" shape rule 30/56 already use
     * for primary owners and per-type templates, just without a
     * partitioning column since this is a single, system-wide choice
     * rather than one per id_type.
     */
    public function up(): void
    {
        Schema::create('fonts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('original_filename');
            $table->string('storage_path');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX uq_fonts_active ON fonts (is_active) WHERE is_active');
    }

    public function down(): void
    {
        Schema::dropIfExists('fonts');
    }
};
