<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alternative ingredients (RN-42): lines of a recipe with the same group are «one of them»,
     * and each planned dish keeps the option chosen for it. Preparations (RN-43): a recipe can
     * include servings of another recipe. Existing recipes have neither and do not change.
     */
    public function up(): void
    {
        Schema::table('recipe_ingredients', function (Blueprint $table) {
            $table->unsignedTinyInteger('alternative_group')->nullable()->after('position');
        });

        Schema::create('planned_dish_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planned_dish_id')->constrained()->cascadeOnDelete();
            // The recipe the group belongs to: the dish's own or one of its preparations.
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('alternative_group');
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['planned_dish_id', 'recipe_id', 'alternative_group'], 'planned_dish_choices_group_unique');
        });

        Schema::create('recipe_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            // A preparation in use cannot be deleted (RN-14), only archived.
            $table->foreignId('component_recipe_id')->constrained('recipes')->restrictOnDelete();
            $table->unsignedSmallInteger('servings');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['recipe_id', 'component_recipe_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_components');
        Schema::dropIfExists('planned_dish_choices');

        Schema::table('recipe_ingredients', function (Blueprint $table) {
            $table->dropColumn('alternative_group');
        });
    }
};
