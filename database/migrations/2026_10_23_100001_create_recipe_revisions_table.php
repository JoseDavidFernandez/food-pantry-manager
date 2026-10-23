<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Earlier compositions of a recipe (RN-45): saved when a recipe already used by dishes whose
     * reference time has passed is edited, so the estimated consumption of those dishes never
     * changes with the edit. `valid_until` is the moment of the edit.
     */
    public function up(): void
    {
        Schema::create('recipe_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->timestamp('valid_until', 6);
            $table->unsignedSmallInteger('base_servings');
            // [{ingredient_id, quantity, unit, alternative_group, position}]
            $table->json('ingredients');
            // [{component_recipe_id, servings, position}]
            $table->json('components');
            $table->timestamps();

            $table->index(['recipe_id', 'valid_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_revisions');
    }
};
