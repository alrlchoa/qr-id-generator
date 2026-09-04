<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Added once `people` exists — the column itself was created with
        // the users table so the two tables' creation order is unconstrained
        // by the other's, but the FK constraint needs both to exist.
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('person_id')->references('id')->on('people');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['person_id']);
        });
    }
};
