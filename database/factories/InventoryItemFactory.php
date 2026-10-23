<?php

namespace Database\Factories;

use App\Enums\StorageLocation;
use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\InventoryItem;
use App\Models\StockEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ingredient_id' => Ingredient::factory(),
            'household_id' => fn (array $attributes) => Ingredient::query()->whereKey($attributes['ingredient_id'])->firstOrFail()->household_id,
            'location' => StorageLocation::Pantry,
            'unit' => Unit::Gram,
            'unit_group' => Unit::Gram->group(),
            'quantity' => '500',
            'label' => null,
        ];
    }

    /**
     * A row created directly states its quantity, like a count by the user (RN-44), so the
     * estimated stock starts from it (the migration did the same with the existing rows).
     */
    public function configure(): static
    {
        return $this->afterCreating(function (InventoryItem $item) {
            StockEvent::query()->create([
                'household_id' => $item->household_id,
                'ingredient_id' => $item->ingredient_id,
                'kind' => StockEvent::COUNT,
                'location' => $item->location,
                'unit_group' => $item->unit_group,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'occurred_at' => CarbonImmutable::now(),
            ]);
        });
    }

    public function of(string $quantity, Unit $unit, StorageLocation $location = StorageLocation::Pantry): static
    {
        return $this->state(fn () => [
            'quantity' => $quantity,
            'unit' => $unit,
            'unit_group' => $unit->group(),
            'location' => $location,
        ]);
    }
}
