<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 16: site branding — the name, logo and navbar colour a
     * Superadmin sets. One system has one identity, so this is a single
     * row, not a key/value table: the id is pinned to 1 by a check
     * constraint and the row is inserted here, so no request ever races to
     * create it. Empty columns mean "the default" (the app's configured
     * name, the neutral mark, a white navbar).
     *
     * The same shape of database backstop the other phases use: the
     * application validates first, and these checks refuse anything that
     * gets past it by another route.
     */
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('site_name', 60)->nullable();
            $table->string('logo_path')->nullable();
            $table->char('navbar_color', 7)->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE site_settings ADD CONSTRAINT ck_site_settings_singleton CHECK (id = 1)');
        DB::statement("ALTER TABLE site_settings ADD CONSTRAINT ck_site_settings_navbar_color CHECK (navbar_color IS NULL OR navbar_color ~ '^#[0-9a-f]{6}$')");
        DB::statement('ALTER TABLE site_settings ADD CONSTRAINT ck_site_settings_site_name CHECK (site_name IS NULL OR char_length(btrim(site_name)) BETWEEN 1 AND 60)');

        DB::table('site_settings')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
