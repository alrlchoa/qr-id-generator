<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Immutability is enforced at the application layer (model boot
        // hooks) in Phase 4 — Approach B (DB-level grant/trigger
        // enforcement) is deferred to Phase 13 per architecture §15.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at');
            // Nullable: null for console-originated actions (§12).
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('user_role');
            $table->string('action');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->jsonb('previous_value')->nullable();
            $table->jsonb('new_value')->nullable();
            $table->string('ip_address')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
