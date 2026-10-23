<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staple foods (RN-47): a minimum to keep at home, or a single restock after «Se acabó».
     * `restock_mode` is `minimum` (always) or `once` (until a purchase of the food is added
     * after `restock_since`). Every existing food starts without any of them.
     */
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->decimal('restock_quantity', 12, 3)->nullable()->after('purchase_unit');
            $table->string('restock_unit', 10)->nullable()->after('restock_quantity');
            $table->string('restock_mode', 10)->nullable()->after('restock_unit');
            $table->timestamp('restock_since', 6)->nullable()->after('restock_mode');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE ingredients ADD CONSTRAINT ingredients_restock_check CHECK (
                (restock_mode IS NULL AND restock_quantity IS NULL AND restock_unit IS NULL AND restock_since IS NULL)
                OR (restock_mode IN ('minimum', 'once') AND restock_quantity > 0 AND restock_unit IS NOT NULL)
            )");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE ingredients DROP CHECK ingredients_restock_check');
        }

        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn(['restock_quantity', 'restock_unit', 'restock_mode', 'restock_since']);
        });
    }
};
