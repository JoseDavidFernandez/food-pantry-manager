<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('normalized_name', 100);
            $table->string('default_unit', 10)->nullable();
            $table->timestamps();

            $table->unique(['household_id', 'normalized_name']);
        });

        Schema::create('ingredient_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('alias', 100);
            $table->string('normalized_alias', 100);
            $table->timestamps();

            $table->unique(['household_id', 'normalized_alias']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredient_aliases');
        Schema::dropIfExists('ingredients');
    }
};
