<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Check, Minus, PackageX, Pencil, Plus, Trash2, X } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import IngredientThumb from '@/components/IngredientThumb.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { depleted } from '@/routes/ingredients';
import { adjust, destroy, update } from '@/routes/inventory';
import type { InventoryItem, Option, UnitOption } from '@/types/pantry';

const props = defineProps<{
    item: InventoryItem;
    units: UnitOption[];
    locations: Option[];
    showLocation: boolean;
    /** Viewers (RN-38) see the stock without its actions. */
    readonly?: boolean;
}>();

const emit = defineEmits<{ food: [ingredientId: number] }>();

const editOpen = ref(false);
// RN-47: «Se acabó» sets every location of the food to zero, after confirming.
const depletedOpen = ref(false);
const deleteOpen = ref(false);

// Quick adjustment (USER-FLOWS §3): ± one unit for indivisible units, or type the new amount.
const adjusting = ref(false);
const quickInput = ref<InstanceType<typeof Input> | null>(null);
const stepper = computed(() => props.item.can_step);

async function startAdjusting(): Promise<void> {
    adjusting.value = true;
    await nextTick();
    (quickInput.value?.$el as HTMLInputElement | undefined)?.select();
}

// RN-44: what is probably left now, when it differs from what was registered. Corrections
// (typing or ±) start from it, and saving them is a count by the user; nothing is required.
const shown = computed(
    () => props.item.estimate?.quantity ?? props.item.quantity,
);
const estimatedAmount = computed(() =>
    Number((props.item.estimate?.quantity ?? '0').replace(',', '.')),
);

// The unit can only change within its compatible group (g ↔ kg, ml ↔ l).
const compatibleUnits = computed(() =>
    props.units.filter((unit) => unit.group === props.item.unit_group),
);
</script>

<template>
    <li
        class="flex flex-col gap-3 rounded-xl border bg-card p-3 shadow-xs transition-colors hover:border-input"
    >
        <div class="flex items-start gap-3">
            <button
                type="button"
                class="-m-1 flex min-w-0 flex-1 items-start gap-3 rounded-lg p-1 text-left hover:bg-accent/60 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                :aria-label="`Ficha de ${item.ingredient}`"
                @click="emit('food', item.ingredient_id)"
            >
                <IngredientThumb
                    :image-url="item.image_url"
                    :category="item.category"
                />
                <span class="grid min-w-0 flex-1">
                    <span class="truncate font-medium">{{
                        item.ingredient
                    }}</span>
                    <span
                        class="flex flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground"
                    >
                        <span>{{ item.category_label }}</span>
                        <template v-if="showLocation">
                            <span aria-hidden="true">·</span>
                            <span>{{ item.location_label }}</span>
                        </template>
                    </span>
                </span>
            </button>
            <div v-if="!readonly" class="-mt-1 -mr-1 flex shrink-0">
                <Dialog v-model:open="editOpen">
                    <DialogTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-11 sm:size-9"
                            :aria-label="`Editar las existencias de ${item.ingredient} (${item.location_label})`"
                        >
                            <Pencil />
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle
                                >Existencias de
                                {{ item.ingredient }}</DialogTitle
                            >
                            <DialogDescription>
                                Cantidad, unidad y ubicación de estas
                                existencias. Indica la cantidad que tienes
                                ahora. Si la mueves a otra ubicación donde ya
                                hay existencias compatibles, se suman.
                            </DialogDescription>
                        </DialogHeader>
                        <Form
                            v-bind="update.form(item.id)"
                            :error-bag="`editStock${item.id}`"
                            :options="{ preserveScroll: true }"
                            v-slot="{ errors, processing }"
                            class="grid gap-4"
                            @success="editOpen = false"
                        >
                            <div class="grid grid-cols-2 gap-3">
                                <div class="grid gap-2">
                                    <Label :for="`quantity-${item.id}`"
                                        >Cantidad</Label
                                    >
                                    <Input
                                        class="h-11 sm:h-9"
                                        :id="`quantity-${item.id}`"
                                        name="quantity"
                                        inputmode="decimal"
                                        :default-value="shown"
                                        required
                                    />
                                    <InputError :message="errors.quantity" />
                                </div>
                                <div class="grid gap-2">
                                    <Label :for="`unit-${item.id}`"
                                        >Unidad</Label
                                    >
                                    <NativeSelect
                                        class="h-11 sm:h-9"
                                        :id="`unit-${item.id}`"
                                        name="unit"
                                        :options="compatibleUnits"
                                        :default-value="item.unit"
                                    />
                                    <InputError :message="errors.unit" />
                                </div>
                            </div>
                            <div class="grid gap-2">
                                <Label :for="`location-${item.id}`"
                                    >Ubicación</Label
                                >
                                <NativeSelect
                                    class="h-11 sm:h-9"
                                    :id="`location-${item.id}`"
                                    name="location"
                                    :options="locations"
                                    :default-value="item.location"
                                />
                                <InputError :message="errors.location" />
                            </div>
                            <div class="grid gap-2">
                                <Label :for="`label-${item.id}`"
                                    >Etiqueta (opcional)</Label
                                >
                                <Input
                                    class="h-11 sm:h-9"
                                    :id="`label-${item.id}`"
                                    name="label"
                                    :default-value="item.label ?? ''"
                                    placeholder="Por ejemplo: paquete abierto"
                                />
                                <InputError :message="errors.label" />
                            </div>
                            <DialogFooter class="gap-2">
                                <DialogClose as-child>
                                    <Button
                                        class="min-h-11 sm:min-h-0"
                                        type="button"
                                        variant="outline"
                                        >Cancelar</Button
                                    >
                                </DialogClose>
                                <Button
                                    class="min-h-11 sm:min-h-0"
                                    type="submit"
                                    :disabled="processing"
                                >
                                    <Spinner v-if="processing" />
                                    Guardar cambios
                                </Button>
                            </DialogFooter>
                        </Form>
                    </DialogContent>
                </Dialog>

                <Dialog v-model:open="deleteOpen">
                    <DialogTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-11 sm:size-9"
                            :aria-label="`Eliminar las existencias de ${item.ingredient} (${item.location_label})`"
                        >
                            <Trash2 />
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle
                                >¿Eliminar estas existencias?</DialogTitle
                            >
                            <DialogDescription>
                                Se quitarán {{ item.quantity }}
                                {{ item.unit_label }} de
                                {{ item.ingredient }} ({{
                                    item.location_label
                                }}). El ingrediente seguirá en tu catálogo.
                            </DialogDescription>
                        </DialogHeader>
                        <Form
                            v-bind="destroy.form(item.id)"
                            :options="{ preserveScroll: true }"
                            v-slot="{ processing }"
                            @success="deleteOpen = false"
                        >
                            <DialogFooter class="gap-2">
                                <DialogClose as-child>
                                    <Button
                                        class="min-h-11 sm:min-h-0"
                                        type="button"
                                        variant="outline"
                                        >Cancelar</Button
                                    >
                                </DialogClose>
                                <Button
                                    class="min-h-11 sm:min-h-0"
                                    type="submit"
                                    variant="destructive"
                                    :disabled="processing"
                                >
                                    <Spinner v-if="processing" />
                                    Eliminar existencias
                                </Button>
                            </DialogFooter>
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </div>

        <Badge v-if="item.label" variant="secondary" class="-mt-1 self-start">{{
            item.label
        }}</Badge>

        <details
            v-if="item.estimate"
            class="-mt-1 rounded-md bg-muted/50 px-3 py-2 text-xs text-muted-foreground"
            data-estimate
        >
            <summary
                class="min-h-6 cursor-pointer font-medium text-foreground marker:text-muted-foreground"
            >
                ≈ Es una estimación. ¿Por qué?
            </summary>
            <p class="mt-1">
                Lo registrado menos lo que usan las comidas del menú desde la
                última vez que contaste esta ubicación:
            </p>
            <ul class="mt-1 grid gap-0.5">
                <li v-for="(use, index) in item.estimate.consumed" :key="index">
                    {{ use.when }} · {{ use.dish }}: {{ use.quantity }}
                </li>
            </ul>
            <p class="mt-1">
                Se supone que se usa primero lo del frigorífico, luego la
                despensa y por último el congelador. Si no coincide con lo que
                hay, pulsa la cantidad y corrígela; no hace falta hacerlo a
                diario.
            </p>
        </details>

        <div class="flex items-center justify-between gap-2 border-t pt-3">
            <Dialog v-if="!readonly" v-model:open="depletedOpen">
                <DialogTrigger as-child>
                    <Button
                        variant="ghost"
                        class="min-h-11 px-2 text-muted-foreground sm:min-h-9"
                        :aria-label="`Se acabó: ${item.ingredient}`"
                    >
                        <PackageX />
                        Se acabó
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle
                            >Se acabó: {{ item.ingredient }}</DialogTitle
                        >
                        <DialogDescription>
                            Se pondrá a 0 en
                            {{
                                (item.food_locations ?? [item.location_label])
                                    .join(', ')
                                    .toLowerCase()
                            }}. Si tiene un mínimo en casa, la lista lo pedirá;
                            si no, se añadirá a la lista una vez, hasta que
                            registres su compra. Puedes corregir las existencias
                            cuando quieras.
                        </DialogDescription>
                    </DialogHeader>
                    <Form
                        v-bind="depleted.form(item.ingredient_id)"
                        :options="{ preserveScroll: true }"
                        v-slot="{ processing }"
                        @success="depletedOpen = false"
                    >
                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button
                                    type="button"
                                    variant="outline"
                                    class="min-h-11 sm:min-h-9"
                                    >Cancelar</Button
                                >
                            </DialogClose>
                            <Button
                                type="submit"
                                class="min-h-11 sm:min-h-9"
                                :disabled="processing"
                            >
                                <Spinner v-if="processing" />
                                Sí, se acabó
                            </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
            <span v-else />
            <p
                v-if="readonly && item.estimate"
                class="min-h-11 px-2 text-right tabular-nums sm:min-h-9"
            >
                <span class="block text-base font-semibold"
                    >≈ {{ item.estimate.display }}
                    <span class="font-normal text-muted-foreground"
                        >previstos</span
                    ></span
                >
                <span class="block text-xs text-muted-foreground"
                    >registrado {{ item.estimate.registered }}</span
                >
            </p>
            <p
                v-else-if="readonly"
                class="min-h-11 px-2 text-right text-base font-semibold tabular-nums sm:min-h-9"
            >
                {{ item.quantity }}
                <span class="font-normal text-muted-foreground">{{
                    item.unit_label
                }}</span>
            </p>
            <Form
                v-else-if="adjusting"
                v-bind="adjust.form(item.id)"
                :error-bag="`adjustStock${item.id}`"
                :options="{ preserveScroll: true }"
                v-slot="{ errors, processing }"
                class="grid justify-items-end gap-1"
                @success="adjusting = false"
                @keydown.esc="adjusting = false"
            >
                <div class="flex items-center gap-1">
                    <Label :for="`quick-${item.id}`" class="sr-only">
                        Cantidad de {{ item.ingredient }} en
                        {{ item.unit_label }}
                    </Label>
                    <Input
                        :id="`quick-${item.id}`"
                        ref="quickInput"
                        name="quantity"
                        inputmode="decimal"
                        :default-value="shown"
                        class="h-11 w-20 text-right tabular-nums sm:h-9"
                        required
                    />
                    <span class="text-sm text-muted-foreground">{{
                        item.unit_label
                    }}</span>
                    <Button
                        type="submit"
                        size="icon"
                        class="size-11 sm:size-9"
                        :disabled="processing"
                        :aria-label="`Guardar la cantidad de ${item.ingredient}`"
                    >
                        <Check />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="size-11 sm:size-9"
                        aria-label="Cancelar"
                        @click="adjusting = false"
                    >
                        <X />
                    </Button>
                </div>
                <InputError :message="errors.quantity" />
            </Form>

            <div v-else class="flex items-center gap-1">
                <Form
                    v-if="stepper"
                    v-bind="adjust.form(item.id)"
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                >
                    <input
                        v-if="item.estimate"
                        type="hidden"
                        name="quantity"
                        :value="estimatedAmount - 1"
                    />
                    <input v-else type="hidden" name="step" value="down" />
                    <Button
                        type="submit"
                        variant="outline"
                        size="icon"
                        class="size-11 sm:size-9"
                        :disabled="
                            processing ||
                            !(item.estimate
                                ? item.estimate.can_decrease
                                : item.can_decrease)
                        "
                        :aria-label="`Quitar una de ${item.ingredient}`"
                    >
                        <Minus />
                    </Button>
                </Form>
                <button
                    v-if="item.estimate"
                    type="button"
                    class="min-h-11 rounded-md px-2 text-right tabular-nums hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none sm:min-h-9"
                    :aria-label="`Corregir la cantidad de ${item.ingredient}: ≈ ${item.estimate.display} previstos, registrado ${item.estimate.registered}`"
                    @click="startAdjusting"
                >
                    <span class="block text-base font-semibold"
                        >≈ {{ item.estimate.display }}
                        <span class="font-normal text-muted-foreground"
                            >previstos</span
                        ></span
                    >
                    <span class="block text-xs text-muted-foreground"
                        >registrado {{ item.estimate.registered }}</span
                    >
                </button>
                <button
                    v-else
                    type="button"
                    class="min-h-11 rounded-md px-2 text-right text-base font-semibold tabular-nums hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none sm:min-h-9"
                    :aria-label="`Ajustar la cantidad de ${item.ingredient}: ${item.quantity} ${item.unit_label}`"
                    @click="startAdjusting"
                >
                    {{ item.quantity }}
                    <span class="font-normal text-muted-foreground">{{
                        item.unit_label
                    }}</span>
                </button>
                <Form
                    v-if="stepper"
                    v-bind="adjust.form(item.id)"
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                >
                    <input
                        v-if="item.estimate"
                        type="hidden"
                        name="quantity"
                        :value="estimatedAmount + 1"
                    />
                    <input v-else type="hidden" name="step" value="up" />
                    <Button
                        type="submit"
                        variant="outline"
                        size="icon"
                        class="size-11 sm:size-9"
                        :disabled="processing"
                        :aria-label="`Añadir una de ${item.ingredient}`"
                    >
                        <Plus />
                    </Button>
                </Form>
            </div>
        </div>
    </li>
</template>
