<?php

namespace App\Http\Requests\Menus;

use App\Enums\Meal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Move a planned dish to another slot of the same week (drag and drop or «Mover a…»).
 */
class MoveDishRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to_day' => ['required', 'integer', 'between:1,7'],
            'to_meal' => ['required', Rule::enum(Meal::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'to_day' => 'día',
            'to_meal' => 'comida o cena',
        ];
    }

    public function targetMeal(): Meal
    {
        return Meal::from($this->string('to_meal')->toString());
    }
}
