<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «Próximos 7/14/30 días» (RN-36): one list per household and quick period whose window
     * moves with today, instead of a new list every day. Null for custom periods and old weekly
     * lists, which keep their fixed dates. Existing rows are not touched.
     */
    public function up(): void
    {
        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->unsignedTinyInteger('rolling_days')->nullable()->after('period_end');
            $table->unique(['household_id', 'rolling_days']);
        });
    }

    public function down(): void
    {
        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->dropUnique(['household_id', 'rolling_days']);
            $table->dropColumn('rolling_days');
        });
    }
};
