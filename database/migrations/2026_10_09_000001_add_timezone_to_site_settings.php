<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 22. The program's display time zone: an IANA name such as
     * `Asia/Manila`, empty meaning "the default" (UTC), like the other
     * site settings. Only the length is checked here — whether a name is a
     * real zone is PHP's list to answer, and the manager checks it against
     * that. Forward-only (CLAUDE.md rule 26): `site_settings` already
     * shipped, so this adds a column rather than editing its migration.
     *
     * Nothing already stored changes. Every timestamp column holds UTC and
     * stays that way; this setting only changes how a time is shown.
     */
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable()->after('page_background_color');
        });

        DB::statement('ALTER TABLE site_settings ADD CONSTRAINT ck_site_settings_timezone CHECK (timezone IS NULL OR char_length(timezone) BETWEEN 1 AND 64)');
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
