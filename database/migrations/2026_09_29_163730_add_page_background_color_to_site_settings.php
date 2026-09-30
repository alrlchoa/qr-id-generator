<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same shape as `navbar_color` (Phase 16) — a nullable hex string,
     * empty meaning "the default," backstopped by the identical check
     * constraint. Forward-only (CLAUDE.md rule 26): `site_settings` already
     * shipped, so this adds a column rather than editing that migration.
     */
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->char('page_background_color', 7)->nullable()->after('navbar_color');
        });

        DB::statement("ALTER TABLE site_settings ADD CONSTRAINT ck_site_settings_page_background_color CHECK (page_background_color IS NULL OR page_background_color ~ '^#[0-9a-f]{6}$')");
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn('page_background_color');
        });
    }
};
