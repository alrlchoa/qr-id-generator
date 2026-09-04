<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No `deleted_at` and no stored validity boolean: `status = 'active'`
        // is the single source of truth (§3/§4). Never edit this table's
        // shape to add either — that is the trap this phase exists to avoid.
        Schema::create('id_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people');
            $table->foreignId('unit_id')->nullable()->constrained('units');
            $table->char('control_number', 8)->unique('uq_id_cards_control_number');
            $table->string('type');
            $table->string('status');
            $table->string('replacement_reason')->nullable();
            $table->foreignId('replaces_id_card_id')->nullable()->constrained('id_cards');
            $table->foreignId('template_id')->constrained('templates');
            $table->string('position')->nullable();
            $table->string('department')->nullable();
            $table->timestamp('issued_at');
            $table->timestamps();
        });

        DB::statement("ALTER TABLE id_cards ADD CONSTRAINT chk_id_cards_control_number_format CHECK (control_number ~ '^[0-9]{8}$')");
        DB::statement("ALTER TABLE id_cards ADD CONSTRAINT chk_id_cards_type CHECK (type IN ('owner', 'tenant', 'employee'))");
        DB::statement("ALTER TABLE id_cards ADD CONSTRAINT chk_id_cards_status CHECK (status IN ('active', 'lost', 'revoked', 'expired', 'replaced'))");
        DB::statement("ALTER TABLE id_cards ADD CONSTRAINT chk_id_cards_replacement_reason CHECK (replacement_reason IS NULL OR replacement_reason IN ('lost', 'type_change', 'unit_transfer', 'photo_change', 'name_change', 'employment_change'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('id_cards');
    }
};
