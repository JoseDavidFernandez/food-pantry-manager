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
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->string('status', 10)->default('draft');
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamps();

            // RN-15: one menu per household and week.
            $table->unique(['household_id', 'week_start']);
        });

        Schema::create('planned_dishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day');
            $table->string('meal', 10);
            $table->string('type', 10);
            // RN-14: a referenced recipe cannot be deleted, only archived.
            $table->foreignId('recipe_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 120)->nullable();
            $table->unsignedSmallInteger('servings');
            $table->timestamps();

            // RN-16: a slot holds at most one dish; an empty slot has no row.
            $table->unique(['menu_id', 'day', 'meal']);
        });

        Schema::create('planned_dish_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planned_dish_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->string('unit', 10);
            $table->string('unit_group', 10);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['planned_dish_id', 'ingredient_id', 'unit_group'], 'planned_dish_ingredients_compatible_unique');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE planned_dishes ADD CONSTRAINT planned_dishes_day_range CHECK (day BETWEEN 1 AND 7)');
            DB::statement('ALTER TABLE planned_dishes ADD CONSTRAINT planned_dishes_servings_positive CHECK (servings >= 1)');
            DB::statement("ALTER TABLE planned_dishes ADD CONSTRAINT planned_dishes_type_coherent CHECK ((type = 'recipe' AND recipe_id IS NOT NULL) OR (type = 'adhoc' AND name IS NOT NULL))");
            DB::statement('ALTER TABLE planned_dish_ingredients ADD CONSTRAINT planned_dish_ingredients_quantity_positive CHECK (quantity > 0)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('planned_dish_ingredients');
        Schema::dropIfExists('planned_dishes');
        Schema::dropIfExists('menus');
    }
};
