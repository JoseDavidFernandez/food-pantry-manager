<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Ingredient;
use App\Support\IngredientName;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ingredient>
 */
class IngredientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->randomElement(['Arroz', 'Lentejas', 'Tomate frito', 'Leche', 'Huevos', 'Pasta', 'Aceite de oliva', 'Cebolla', 'Atún', 'Yogur']);

        return [
            'household_id' => Household::factory(),
            'name' => $name,
            'normalized_name' => IngredientName::normalize($name),
            'default_unit' => null,
        ];
    }

    public function named(string $name): static
    {
        return $this->state(fn () => [
            'name' => $name,
            'normalized_name' => IngredientName::normalize($name),
        ]);
    }
}
