<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «No comido» (RN-37): the dish stays in the menu, but its ingredients stop counting.
     * Additive and nullable: every existing dish keeps counting as before.
     */
    public function up(): void
    {
        Schema::table('planned_dishes', function (Blueprint $table) {
            $table->timestamp('not_eaten_at')->nullable()->after('servings');
        });
    }

    public function down(): void
    {
        Schema::table('planned_dishes', function (Blueprint $table) {
            $table->dropColumn('not_eaten_at');
        });
    }
};
