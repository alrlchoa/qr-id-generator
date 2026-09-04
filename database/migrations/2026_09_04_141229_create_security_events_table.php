<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at');
            // Nullable — may be unauthenticated (e.g. a failed login).
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('event_type');
            $table->jsonb('detail')->nullable();
            $table->string('ip_address')->nullable();
        });

        DB::statement("ALTER TABLE security_events ADD CONSTRAINT chk_security_events_event_type CHECK (event_type IN ('login_failed', 'authorization_denied', 'qr_verify_miss'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
