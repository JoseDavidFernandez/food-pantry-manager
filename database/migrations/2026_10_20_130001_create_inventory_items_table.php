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
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->string('location', 10);
            $table->string('unit', 10);
            $table->string('unit_group', 10);
            $table->decimal('quantity', 12, 3);
            $table->string('label', 100)->nullable();
            $table->timestamps();

            // RN-08: one row per ingredient, location and compatible unit group.
            $table->unique(['household_id', 'ingredient_id', 'location', 'unit_group'], 'inventory_items_compatible_unique');
        });

        if (DB::getDriverName() === 'mysql') {
            // RN-07: stored quantities are always greater than zero.
            DB::statement('ALTER TABLE inventory_items ADD CONSTRAINT inventory_items_quantity_positive CHECK (quantity > 0)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
