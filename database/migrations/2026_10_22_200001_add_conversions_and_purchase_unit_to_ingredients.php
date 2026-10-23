<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Units of a food (RN-40): its own conversions between units that are not universally
     * equivalent ("1 cabeza = 10 dientes", "1 bolsa = 500 g") and, optionally, the unit it is
     * bought in. Nothing changes for foods without them.
     */
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->string('purchase_unit', 10)->nullable()->after('default_unit');
        });

        Schema::create('ingredient_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            // 1 `unit` = `factor` `to_unit`.
            $table->string('unit', 10);
            $table->decimal('factor', 14, 6);
            $table->string('to_unit', 10);
            $table->timestamps();

            $table->unique(['ingredient_id', 'unit', 'to_unit']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE ingredient_conversions ADD CONSTRAINT ingredient_conversions_factor_positive CHECK (factor > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_conversions');

        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn('purchase_unit');
        });
    }
};
