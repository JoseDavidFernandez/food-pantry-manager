<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { minimum } from '@/routes/ingredients';
import type { Ingredient, UnitOption } from '@/types/pantry';

/**
 * «Mínimo en casa» of a food (RN-47): the list asks for it when less would be left at home
 * after the planned meals. Also shows the single restock asked for by «Se acabó».
 */
defineProps<{
    food: Ingredient;
    units: UnitOption[];
    readonly?: boolean;
}>();
</script>

<template>
    <section
        class="grid gap-3 border-t pt-4"
        :aria-labelledby="`food-restock-${food.id}`"
        data-restock
    >
        <div class="grid gap-1">
            <h3 :id="`food-restock-${food.id}`" class="text-sm font-medium">
                Mínimo en casa
            </h3>
            <p class="text-xs text-muted-foreground">
                Para lo que siempre quieres tener (leche, aceite…). La lista lo
                pide solo cuando, tras las comidas del menú, vaya a quedar
                menos. Cuando se acabe, pulsa «Se acabó» en su tarjeta.
            </p>
        </div>

        <p
            v-if="food.restock?.mode === 'once' && food.restock.display"
            class="rounded-md bg-muted/50 px-3 py-2 text-sm"
        >
            Pendiente de reponer: <strong>{{ food.restock.display }}</strong
            >, hasta que registres su compra.
        </p>

        <p v-if="readonly" class="text-sm">
            <template v-if="food.restock?.mode === 'minimum'"
                >Al menos
                <strong
                    >{{ food.restock.quantity }}
                    {{
                        units.find((unit) => unit.value === food.restock?.unit)
                            ?.label
                    }}</strong
                ></template
            >
            <template v-else>Sin mínimo.</template>
        </p>
        <Form
            v-else
            v-bind="minimum.form(food.id)"
            :error-bag="`minimum${food.id}`"
            :options="{ preserveScroll: true }"
            v-slot="{ errors, processing }"
            class="grid gap-2"
        >
            <div class="grid grid-cols-[6rem_1fr] gap-2">
                <div class="grid gap-1">
                    <Label :for="`food-minimum-${food.id}`" class="text-xs"
                        >Al menos</Label
                    >
                    <Input
                        :id="`food-minimum-${food.id}`"
                        name="quantity"
                        inputmode="decimal"
                        :default-value="
                            food.restock?.mode === 'minimum'
                                ? (food.restock.quantity ?? '')
                                : ''
                        "
                        placeholder="2"
                        class="h-11 sm:h-9"
                    />
                </div>
                <div class="grid gap-1">
                    <Label :for="`food-minimum-unit-${food.id}`" class="text-xs"
                        >Unidad</Label
                    >
                    <NativeSelect
                        :id="`food-minimum-unit-${food.id}`"
                        name="unit"
                        :options="
                            units.map((unit) => ({
                                value: unit.value,
                                label: unit.label,
                            }))
                        "
                        :default-value="
                            food.restock?.mode === 'minimum'
                                ? food.restock.unit
                                : (food.default_unit ?? null)
                        "
                        placeholder="Elige la unidad"
                        class="h-11 sm:h-9"
                    />
                </div>
            </div>
            <InputError :message="errors.quantity ?? errors.unit" />
            <div class="flex flex-wrap gap-2">
                <Button
                    type="submit"
                    variant="outline"
                    class="min-h-11 sm:min-h-9"
                    :disabled="processing"
                >
                    Guardar mínimo
                </Button>
            </div>
        </Form>
        <Form
            v-if="!readonly && food.restock?.mode === 'minimum'"
            v-bind="minimum.form(food.id)"
            :options="{ preserveScroll: true }"
            v-slot="{ processing }"
        >
            <input type="hidden" name="quantity" value="" />
            <Button
                type="submit"
                variant="ghost"
                class="min-h-11 sm:min-h-9"
                :disabled="processing"
            >
                Quitar el mínimo
            </Button>
        </Form>
    </section>
</template>
