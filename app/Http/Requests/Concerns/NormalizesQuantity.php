<?php

namespace App\Http\Requests\Concerns;

/**
 * Accept the Spanish decimal comma ("1,5") in quantity fields.
 */
trait NormalizesQuantity
{
    protected function prepareForValidation(): void
    {
        if ($this->has('quantity')) {
            $this->merge([
                'quantity' => str_replace(',', '.', trim((string) $this->input('quantity'))),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    protected function quantityRules(): array
    {
        return ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999999'];
    }
}
