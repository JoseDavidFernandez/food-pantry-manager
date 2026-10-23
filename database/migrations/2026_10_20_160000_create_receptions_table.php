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
        // A list may have several receptions (partial purchases); each one is confirmed at once (RN-29).
        Schema::create('receptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shopping_list_id')->constrained()->cascadeOnDelete();
            // Idempotency token of the form: a resubmitted confirmation is never applied twice (RN-30).
            $table->uuid('token')->unique();
            $table->timestamp('confirmed_at');
            $table->timestamps();
        });

        Schema::create('reception_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reception_id')->constrained()->cascadeOnDelete();
            // Kept as history if the list line is later removed or recalculated away.
            $table->foreignId('shopping_list_item_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_unforeseen')->default(false);
            $table->foreignId('ingredient_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('outcome', 15);
            $table->decimal('suggested_quantity', 12, 3)->nullable();
            $table->string('suggested_unit', 10)->nullable();
            $table->decimal('quantity_received', 12, 3)->nullable();
            $table->string('unit', 10)->nullable();
            $table->string('unit_group', 10)->nullable();
            $table->string('location', 10)->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE reception_items ADD CONSTRAINT reception_items_outcome_valid CHECK (outcome IN ('received', 'not_purchased'))");
            DB::statement("ALTER TABLE reception_items ADD CONSTRAINT reception_items_received_complete CHECK (outcome <> 'received' OR (quantity_received > 0 AND unit IS NOT NULL AND location IS NOT NULL AND ingredient_id IS NOT NULL))");
            DB::statement("ALTER TABLE reception_items ADD CONSTRAINT reception_items_not_purchased_empty CHECK (outcome <> 'not_purchased' OR (quantity_received IS NULL AND unit IS NULL AND location IS NULL))");
            DB::statement("ALTER TABLE reception_items ADD CONSTRAINT reception_items_unforeseen_received CHECK (is_unforeseen = 0 OR outcome = 'received')");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reception_items');
        Schema::dropIfExists('receptions');
    }
};
