<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();

            // Identity
            $table->char('user_id_number', 8)->unique('uq_people_user_id_number');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('suffix')->nullable();
            $table->string('photo_path');

            // Personal
            $table->date('date_of_birth')->nullable();
            $table->string('place_of_birth')->nullable();
            $table->string('gender');

            // Contact
            $table->text('home_address');
            $table->string('mobile_number');
            $table->string('landline_number')->nullable();
            $table->string('email');

            // Emergency contact
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_number')->nullable();
            $table->string('emergency_contact_relation')->nullable();

            // Record
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE people ADD CONSTRAINT chk_people_user_id_number_format CHECK (user_id_number ~ '^[0-9]{8}$')");
        DB::statement("ALTER TABLE people ADD CONSTRAINT chk_people_gender CHECK (gender IN ('male', 'female', 'prefer_not_to_say'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};
