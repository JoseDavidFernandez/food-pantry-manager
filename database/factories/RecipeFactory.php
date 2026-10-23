<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recipe>
 */
class RecipeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'name' => fake()->randomElement(['Lentejas estofadas', 'Pollo con arroz', 'Tortilla de patatas', 'Ensalada']),
            'base_servings' => 4,
            'instructions' => null,
            'archived_at' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => now()]);
    }

    /**
     * @param  list<array{0: Ingredient, 1: Quantity}>  $lines
     */
    public function withIngredients(array $lines): static
    {
        return $this->afterCreating(function (Recipe $recipe) use ($lines) {
            foreach ($lines as $position => [$ingredient, $quantity]) {
                $line = $recipe->ingredients()->make(['ingredient_id' => $ingredient->id, 'position' => $position]);
                $line->setQuantity($quantity);
                $line->save();
            }
        });
    }
}
