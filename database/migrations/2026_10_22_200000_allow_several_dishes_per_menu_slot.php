<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Several dishes in the same lunch or dinner, in order (RN-16 updated on 2026-10-22). The
     * unique (menu_id, day, meal) goes; its index is replaced first, because it also serves the
     * menu_id foreign key. Every existing dish is alone in its slot, so position 0 keeps it.
     */
    public function up(): void
    {
        Schema::table('planned_dishes', function (Blueprint $table) {
            $table->unsignedSmallInteger('position')->default(0)->after('meal');
            $table->index(['menu_id', 'day', 'meal', 'position'], 'planned_dishes_slot_position_index');
            $table->dropUnique(['menu_id', 'day', 'meal']);
        });
    }

    /**
     * Going back requires leaving one dish per slot first.
     */
    public function down(): void
    {
        Schema::table('planned_dishes', function (Blueprint $table) {
            $table->unique(['menu_id', 'day', 'meal']);
            $table->dropIndex('planned_dishes_slot_position_index');
            $table->dropColumn('position');
        });
    }
};
