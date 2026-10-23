<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What happened to each stock row (RN-44): additions (purchases and manual additions),
     * counts (the user states the real quantity: editing, ± or deleting) and moves between
     * locations. With the planned meals they give the estimated stock; the registered rows in
     * `inventory_items` never change by themselves.
     *
     * Every existing row starts with a count equal to what is registered now, so the estimate
     * of every household equals its registered stock right after migrating.
     */
    public function up(): void
    {
        Schema::create('stock_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            // add | count | move
            $table->string('kind', 5);
            // The row: its location and compatible group (unique per ingredient in inventory_items).
            $table->string('location', 10);
            $table->string('unit_group', 20);
            // Moves only: where the row went.
            $table->string('to_location', 10)->nullable();
            // add: amount added; count: quantity stated; move: the moved row's new quantity
            // when it changed while moving (null when only the location changed).
            $table->decimal('quantity', 12, 3)->nullable();
            $table->string('unit', 10)->nullable();
            $table->timestamp('occurred_at', 6);
            $table->foreignId('reception_item_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['household_id', 'occurred_at']);
            $table->index(['ingredient_id', 'occurred_at']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE stock_events ADD CONSTRAINT stock_events_kind_check CHECK (kind IN ('add', 'count', 'move'))");
            DB::statement('ALTER TABLE stock_events ADD CONSTRAINT stock_events_quantity_check CHECK (quantity IS NULL OR quantity >= 0)');
        }

        $now = now();

        DB::table('inventory_items')->orderBy('id')->each(function (object $item) use ($now) {
            DB::table('stock_events')->insert([
                'household_id' => $item->household_id,
                'ingredient_id' => $item->ingredient_id,
                'kind' => 'count',
                'location' => $item->location,
                'unit_group' => $item->unit_group,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_events');
    }
};
