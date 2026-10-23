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
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedSmallInteger('base_servings');
            $table->text('instructions')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['household_id', 'archived_at']);
        });

        Schema::create('recipe_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->string('unit', 10);
            $table->string('unit_group', 10);
            $table->string('note', 150)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            // One row per ingredient and compatible unit group in a recipe.
            $table->unique(['recipe_id', 'ingredient_id', 'unit_group']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_base_servings_positive CHECK (base_servings >= 1)');
            DB::statement('ALTER TABLE recipe_ingredients ADD CONSTRAINT recipe_ingredients_quantity_positive CHECK (quantity > 0)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipe_ingredients');
        Schema::dropIfExists('recipes');
    }
};
