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
        Schema::create('shopping_lists', function (Blueprint $table) {
            $table->id();
            // RN-20: at most one list per menu.
            $table->foreignId('menu_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->timestamp('generated_at');
            $table->timestamp('regenerated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shopping_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopping_list_id')->constrained()->cascadeOnDelete();
            $table->string('origin', 10);
            $table->foreignId('ingredient_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->decimal('quantity', 12, 3)->nullable();
            $table->string('unit', 10)->nullable();
            $table->string('unit_group', 10)->nullable();
            // "{ingredient_id}:{unit_group}"; null for free-text lines (RN-24).
            $table->string('match_key', 40)->nullable();
            $table->decimal('calculated_quantity', 12, 3)->nullable();
            $table->string('calculated_unit', 10)->nullable();
            $table->boolean('is_user_modified')->default(false);
            $table->boolean('is_dismissed')->default(false);
            $table->boolean('is_purchased')->default(false);
            $table->boolean('needs_review')->default(false);
            $table->string('review_reason', 30)->nullable();
            $table->timestamps();

            // No duplicate lines for the same ingredient and compatible unit (RN-24, RN-27).
            $table->unique(['shopping_list_id', 'match_key']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE shopping_list_items ADD CONSTRAINT shopping_list_items_quantity_positive CHECK (quantity IS NULL OR quantity > 0)');
            DB::statement("ALTER TABLE shopping_list_items ADD CONSTRAINT shopping_list_items_origin_valid CHECK (origin IN ('generated', 'manual'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopping_list_items');
        Schema::dropIfExists('shopping_lists');
    }
};
