<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 12 hotfix-shaped decision, recorded per rule 27: the uploaded
     * artwork composites *last*, on top of every field — a cut-out in the
     * PNG frames the photo as a border — so it is an overlay, not a
     * background. The old column names said the opposite of what they now
     * do; forward-only rename (rule 26 — never edit the shipped migration
     * that created these columns).
     *
     * The three changes added here are new invariants this phase
     * introduces, not renames: `overlay_path_front` becomes nullable
     * because a template is now created (name, id_type, orientation)
     * *before* any artwork is uploaded — the original schema made it
     * `NOT NULL` back when a template couldn't exist without a background
     * image already in hand. CR80 at 300 DPI is exactly two sizes
     * (portrait/landscape), and at most one template per id_type may be
     * active at a time — the thing `IssuanceManager` and card rendering
     * resolve against, mirroring the partial-unique-index pattern rule 30
     * already uses for "at most one" invariants.
     */
    public function up(): void
    {
        Schema::table('templates', function ($table) {
            $table->renameColumn('background_path_front', 'overlay_path_front');
            $table->renameColumn('background_path_back', 'overlay_path_back');
        });

        DB::statement('ALTER TABLE templates ALTER COLUMN overlay_path_front DROP NOT NULL');
        DB::statement('ALTER TABLE templates ALTER COLUMN field_positions_front DROP NOT NULL');

        DB::statement(
            'ALTER TABLE templates ADD CONSTRAINT chk_templates_cr80_dimensions '.
            'CHECK ((width_px = 1011 AND height_px = 638) OR (width_px = 638 AND height_px = 1011))'
        );

        DB::statement(
            'CREATE UNIQUE INDEX uq_templates_active_per_id_type ON templates (id_type) WHERE is_active'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_templates_active_per_id_type');
        DB::statement('ALTER TABLE templates DROP CONSTRAINT IF EXISTS chk_templates_cr80_dimensions');
        DB::statement("UPDATE templates SET overlay_path_front = '' WHERE overlay_path_front IS NULL");
        DB::statement('ALTER TABLE templates ALTER COLUMN overlay_path_front SET NOT NULL');
        DB::statement("UPDATE templates SET field_positions_front = '{}' WHERE field_positions_front IS NULL");
        DB::statement('ALTER TABLE templates ALTER COLUMN field_positions_front SET NOT NULL');

        Schema::table('templates', function ($table) {
            $table->renameColumn('overlay_path_front', 'background_path_front');
            $table->renameColumn('overlay_path_back', 'background_path_back');
        });
    }
};
