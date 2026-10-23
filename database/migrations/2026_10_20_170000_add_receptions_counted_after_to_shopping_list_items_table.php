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
        Schema::table('shopping_list_items', function (Blueprint $table) {
            // Receptions up to this id were already in stock when the line's quantity was last
            // calculated, so only later ones reduce what is pending. Null: every reception counts.
            $table->unsignedBigInteger('receptions_counted_after')->nullable()->after('calculated_unit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shopping_list_items', function (Blueprint $table) {
            $table->dropColumn('receptions_counted_after');
        });
    }
};
