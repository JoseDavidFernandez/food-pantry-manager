<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { purchaseUnit } from '@/routes/ingredients';
import {
    destroy as destroyConversion,
    store as storeConversion,
} from '@/routes/ingredients/conversions';
import type { Ingredient, UnitOption } from '@/types/pantry';

/**
 * The units of a food (RN-40, RN-41): its own equivalences («1 cabeza = 10 dientes»,
 * «1 bolsa = 500 g») and the pack it is bought in. Only this food uses them.
 */
const props = defineProps<{
    /** Inside a folded section that already shows the title (R4). */
    headless?: boolean;
    food: Ingredient;
    units: UnitOption[];
    readonly?: boolean;
}>();

const unitOptions = computed(() =>
    props.units.map((unit) => ({ value: unit.value, label: unit.label })),
);

// Packs and pieces are bought whole; buying by weight needs no format.
const packOptions = computed(() =>
    props.units
        .filter((unit) => unit.group !== 'mass' && unit.group !== 'volume')
        .map((unit) => ({ value: unit.value, label: unit.label })),
);

const purchaseLabel = computed(
    () =>
        props.units.find((unit) => unit.value === props.food.purchase_unit)
            ?.label,
);
</script>

<template>
    <section
        :class="['grid gap-3', !headless && 'border-t pt-4']"
        :aria-labelledby="`food-units-${food.id}`"
    >
        <div class="grid gap-1">
            <h3
                :id="`food-units-${food.id}`"
                :class="headless ? 'sr-only' : 'text-sm font-medium'"
            >
                Unidades y compra
            </h3>
            <p class="text-xs text-muted-foreground">
                Equivalencias propias de este alimento, para comparar lo que
                piden las recetas con lo que tienes y para comprar en envases
                enteros. g y kg, ml y l ya se convierten solos.
            </p>
        </div>

        <ul
            class="flex flex-wrap items-center gap-2"
            aria-label="Equivalencias"
        >
            <li
                v-for="conversion in food.conversions ?? []"
                :key="conversion.id"
                class="max-sm:py-3"
            >
                <Badge variant="secondary" class="gap-1 overflow-visible pr-1">
                    {{ conversion.label }}
                    <Form
                        v-if="!readonly"
                        v-bind="
                            destroyConversion.form({
                                ingredient: food.id,
                                conversion: conversion.id,
                            })
                        "
                        :options="{ preserveScroll: true }"
                        class="inline-flex"
                    >
                        <button
                            type="submit"
                            class="relative rounded-full p-0.5 after:absolute after:-inset-3.5 after:content-[''] hover:bg-muted-foreground/20 sm:after:-inset-1"
                            :aria-label="`Quitar la equivalencia ${conversion.label}`"
                        >
                            <X class="size-3" />
                        </button>
                    </Form>
                </Badge>
            </li>
            <li
                v-if="(food.conversions ?? []).length === 0"
                class="text-sm text-muted-foreground"
            >
                Ninguna
            </li>
        </ul>

        <Form
            v-if="!readonly"
            v-bind="storeConversion.form(food.id)"
            :error-bag="`conversion${food.id}`"
            reset-on-success
            :options="{ preserveScroll: true }"
            v-slot="{ errors, processing }"
            class="grid gap-2"
        >
            <fieldset class="grid gap-2">
                <legend class="text-xs font-medium">Nueva equivalencia</legend>
                <div
                    class="grid grid-cols-[auto_5rem_minmax(0,1fr)] items-center gap-2 sm:grid-cols-[auto_minmax(8rem,1fr)_auto_5rem_minmax(8rem,1fr)]"
                >
                    <span class="text-sm tabular-nums">1</span>
                    <div class="col-span-2 sm:col-span-1">
                        <Label
                            :for="`food-conv-unit-${food.id}`"
                            class="sr-only"
                            >Unidad</Label
                        >
                        <NativeSelect
                            class="h-11 sm:h-9"
                            :id="`food-conv-unit-${food.id}`"
                            name="unit"
                            :options="unitOptions"
                            placeholder="cabeza, bolsa…"
                        />
                    </div>
                    <span class="text-sm">=</span>
                    <div>
                        <Label
                            :for="`food-conv-factor-${food.id}`"
                            class="sr-only"
                            >Cantidad equivalente</Label
                        >
                        <Input
                            class="h-11 sm:h-9"
                            :id="`food-conv-factor-${food.id}`"
                            name="factor"
                            inputmode="decimal"
                            placeholder="10"
                            required
                        />
                    </div>
                    <div class="sm:col-start-5">
                        <Label :for="`food-conv-to-${food.id}`" class="sr-only"
                            >Unidad equivalente</Label
                        >
                        <NativeSelect
                            class="h-11 sm:h-9"
                            :id="`food-conv-to-${food.id}`"
                            name="to_unit"
                            :options="unitOptions"
                            placeholder="dientes, g…"
                        />
                    </div>
                    <Button
                        type="submit"
                        variant="outline"
                        class="col-span-3 min-h-11 justify-self-start sm:col-span-5 sm:min-h-9"
                        :disabled="processing"
                    >
                        <Plus />
                        Añadir equivalencia
                    </Button>
                </div>
            </fieldset>
            <InputError :message="errors.unit" />
            <InputError :message="errors.factor" />
            <InputError :message="errors.to_unit" />
        </Form>

        <p v-if="readonly" class="text-sm">
            Se compra en:
            <strong>{{ purchaseLabel ?? 'la cantidad que falta' }}</strong>
            <span v-if="food.pack" class="text-muted-foreground">
                ({{ food.pack }})</span
            >
        </p>
        <Form
            v-else
            v-bind="purchaseUnit.form(food.id)"
            :error-bag="`purchase${food.id}`"
            :options="{ preserveScroll: true }"
            v-slot="{ errors, processing }"
            class="grid gap-2"
        >
            <Label :for="`food-purchase-${food.id}`">Se compra en</Label>
            <div class="flex gap-2">
                <NativeSelect
                    class="h-11 sm:h-9"
                    :id="`food-purchase-${food.id}`"
                    :key="food.purchase_unit ?? 'none'"
                    name="purchase_unit"
                    :options="packOptions"
                    :default-value="food.purchase_unit ?? ''"
                    placeholder="La cantidad que falta (sin redondear a envases)"
                />
                <Button
                    type="submit"
                    variant="outline"
                    class="min-h-11 sm:min-h-9"
                    :disabled="processing"
                >
                    Guardar
                </Button>
            </div>
            <p class="text-xs text-muted-foreground">
                <template v-if="food.pack"
                    >Ahora: {{ food.pack }}. La lista redondea hacia arriba a
                    envases enteros y explica cuánto falta de verdad.</template
                >
                <template v-else
                    >Con un envase (bolsa, caja, malla…) la lista pide envases
                    enteros. Añade antes cuánto contiene.</template
                >
            </p>
            <InputError :message="errors.purchase_unit" />
        </Form>
    </section>
</template>
