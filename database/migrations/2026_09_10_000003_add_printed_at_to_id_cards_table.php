<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Printed" is orthogonal to `status` (rule 6's family of distinctions
     * — expired isn't revoked, and now printed isn't a lifecycle state
     * either): a card is printed or not *regardless* of whether it's
     * active, lost, revoked, or expired. It never belongs in the `status`
     * check constraint, which is exclusively about entitlement state.
     * `printed_at`, not a boolean — when it happened is worth keeping,
     * the same reasoning `issued_at` already uses.
     */
    public function up(): void
    {
        Schema::table('id_cards', function (Blueprint $table) {
            $table->timestamp('printed_at')->nullable()->after('issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('id_cards', function (Blueprint $table) {
            $table->dropColumn('printed_at');
        });
    }
};
