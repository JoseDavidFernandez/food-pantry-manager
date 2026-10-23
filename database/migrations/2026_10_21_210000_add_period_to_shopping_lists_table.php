<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shopping lists per purchase period (RN-36). Existing weekly lists keep their menu and get
     * no period: they stay as history with their lines, purchases and receptions. A period list
     * has no menu. MySQL unique indexes allow several NULLs, so both kinds coexist.
     */
    public function up(): void
    {
        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->unsignedBigInteger('menu_id')->nullable()->change();
            $table->date('period_start')->nullable()->after('household_id');
            $table->date('period_end')->nullable()->after('period_start');
            $table->unique(['household_id', 'period_start', 'period_end'], 'shopping_lists_period_unique');
        });
    }

    /**
     * Going back requires deleting the period lists first: a list without menu cannot become a
     * weekly one again.
     */
    public function down(): void
    {
        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->dropUnique('shopping_lists_period_unique');
            $table->dropColumn(['period_start', 'period_end']);
            $table->unsignedBigInteger('menu_id')->nullable(false)->change();
        });
    }
};
