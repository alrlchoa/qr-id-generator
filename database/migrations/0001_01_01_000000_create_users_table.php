<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // No email column and no password_reset_tokens table: login is by
        // username (architecture §3, users), there is no mail server, and
        // the Breeze/Fortify password-reset scaffolding that assumes one is
        // stripped during Phase 3 setup, not merely left unused.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('person_id')->nullable();
            $table->string('username')->unique();
            $table->string('name');
            $table->string('password');
            $table->string('role');
            $table->boolean('must_change_password')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT chk_users_role CHECK (role IN ('superadmin', 'admin', 'reader'))");

        // A plain unique constraint would survive a soft delete: once a
        // departed employee's account is soft-deleted, Postgres still sees
        // their person_id as taken, and no new account could ever be linked
        // to that person again. Scoping the uniqueness to live rows lets a
        // rehired person get a new account after their old one is retired.
        DB::statement('CREATE UNIQUE INDEX uq_users_person_id_active ON users (person_id) WHERE deleted_at IS NULL');

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};
