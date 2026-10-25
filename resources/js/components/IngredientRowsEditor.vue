<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Plus, Sparkles, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { IngredientLine, UnitOption } from '@/types/pantry';

const props = defineProps<{
    initialRows: IngredientLine[];
    units: UnitOption[];
    suggestions: string[];
    errors: Record<string, string | undefined>;
    idPrefix: string;
    withNotes?: boolean;
    /** Recipes (RN-42): a row can be an alternative («o bien») to the row before it. */
    withAlternatives?: boolean;
}>();

// `create`: the user explicitly asked to create this name as a new ingredient (RN-03).
// `edited`: the row changed after the last server error, so that error no longer applies.
// `alternative`: «o bien» of the row before it (RN-42).
type Row = IngredientLine & {
    key: number;
    create: boolean;
    edited: boolean;
    alternative: boolean;
};

let nextKey = 0;
const blankRow = (): Row => ({
    key: nextKey++,
    ingredient: '',
    quantity: '',
    unit: 'g',
    note: '',
    create: false,
    edited: false,
    alternative: false,
});

const rows = ref<Row[]>(
    props.initialRows.length > 0
        ? props.initialRows.map((row) => ({
              ...row,
              key: nextKey++,
              create: false,
              edited: false,
              alternative: row.alternative === true,
          }))
        : [blankRow()],
);

// Similar ingredients sent by the server for each rejected row; never applied automatically (RN-04).
const page = usePage();
const similar = computed(
    () =>
        (page.flash as { ingredientSuggestions?: Record<number, string[]> })
            .ingredientSuggestions ?? {},
);

watch(
    () => props.errors,
    () => rows.value.forEach((row) => (row.edited = false)),
);

function rowError(index: number): string | undefined {
    const row = rows.value[index];

    return row.edited || row.create
        ? undefined
        : props.errors[`ingredients.${index}.ingredient`];
}

function typed(row: Row): void {
    row.create = false;
    row.edited = true;
}

function useExisting(row: Row, name: string): void {
    row.ingredient = name;
    row.create = false;
    row.edited = true;
}

function createNew(row: Row): void {
    row.create = true;
}

function addRow(): void {
    rows.value.push(blankRow());
}

function addAlternative(index: number): void {
    rows.value.splice(index + 1, 0, { ...blankRow(), alternative: true });
}

function previousName(index: number): string {
    return rows.value[index - 1]?.ingredient.trim() || 'la fila anterior';
}

function removeRow(key: number): void {
    rows.value = rows.value.filter((row) => row.key !== key);
}
</script>

<template>
    <div class="grid gap-3">
        <datalist :id="`${idPrefix}-suggestions`">
            <option v-for="name in suggestions" :key="name" :value="name" />
        </datalist>

        <div
            v-for="(row, index) in rows"
            :key="row.key"
            :class="[
                'grid gap-2 rounded-md p-3 sm:grid-cols-[1fr_6rem_8rem_auto] sm:items-start',
                withAlternatives && row.alternative && index > 0
                    ? '-mt-2 bg-muted'
                    : 'bg-card',
            ]"
        >
            <!-- R7: only an alternative row shows the box (to undo it); new alternatives come
                 from «Añadir una alternativa», so ordinary rows carry no extra control. -->
            <div
                v-if="withAlternatives && row.alternative && index > 0"
                class="flex flex-wrap items-center gap-2 text-xs sm:col-span-4"
            >
                <!-- The whole label is the touch area of the box (44 px on mobile). -->
                <Label
                    class="flex min-h-11 items-center gap-2 text-xs font-normal sm:min-h-0"
                >
                    <input
                        :id="`${idPrefix}-alternative-${row.key}`"
                        v-model="row.alternative"
                        type="checkbox"
                        class="size-4 shrink-0 accent-primary"
                    />
                    O bien: alternativa a «{{ previousName(index) }}» (se usa
                    solo una de ellas)
                </Label>
                <input
                    v-if="row.alternative"
                    type="hidden"
                    :name="`ingredients[${index}][alternative]`"
                    value="1"
                />
            </div>
            <div class="grid gap-1">
                <Label
                    :for="`${idPrefix}-ingredient-${row.key}`"
                    class="text-xs"
                >
                    Ingrediente
                </Label>
                <Input
                    :id="`${idPrefix}-ingredient-${row.key}`"
                    v-model="row.ingredient"
                    :name="`ingredients[${index}][ingredient]`"
                    :list="`${idPrefix}-suggestions`"
                    autocomplete="off"
                    placeholder="Por ejemplo: arroz"
                    @input="typed(row)"
                />
                <input
                    v-if="row.create"
                    type="hidden"
                    :name="`ingredients[${index}][create]`"
                    value="1"
                />
                <InputError :message="rowError(index)" />
                <div
                    v-if="
                        rowError(index) &&
                        similar[index] !== undefined &&
                        row.ingredient.trim() !== ''
                    "
                    class="flex flex-wrap gap-1"
                >
                    <Button
                        v-for="name in similar[index] ?? []"
                        :key="name"
                        type="button"
                        variant="secondary"
                        size="sm"
                        @click="useExisting(row, name)"
                    >
                        Usar «{{ name }}»
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="createNew(row)"
                    >
                        <Plus />
                        Crear «{{ row.ingredient.trim() }}»
                    </Button>
                </div>
                <p
                    v-if="row.create"
                    class="flex flex-wrap items-center gap-1 text-xs text-muted-foreground"
                >
                    <Sparkles class="size-3" />
                    Se creará «{{ row.ingredient.trim() }}» como ingrediente
                    nuevo al guardar.
                    <button
                        type="button"
                        class="underline"
                        @click="row.create = false"
                    >
                        Deshacer
                    </button>
                </p>
            </div>
            <div class="grid gap-1">
                <Label :for="`${idPrefix}-quantity-${row.key}`" class="text-xs">
                    Cantidad
                </Label>
                <Input
                    :id="`${idPrefix}-quantity-${row.key}`"
                    :name="`ingredients[${index}][quantity]`"
                    :default-value="row.quantity"
                    inputmode="decimal"
                    placeholder="200"
                />
                <InputError
                    :message="errors[`ingredients.${index}.quantity`]"
                />
            </div>
            <div class="grid gap-1">
                <Label :for="`${idPrefix}-unit-${row.key}`" class="text-xs">
                    Unidad
                </Label>
                <NativeSelect
                    :id="`${idPrefix}-unit-${row.key}`"
                    :name="`ingredients[${index}][unit]`"
                    :options="units"
                    :default-value="row.unit"
                />
                <InputError :message="errors[`ingredients.${index}.unit`]" />
            </div>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="sm:mt-5"
                :aria-label="`Quitar el ingrediente ${index + 1}`"
                @click="removeRow(row.key)"
            >
                <Trash2 />
            </Button>
            <div v-if="withNotes" class="grid gap-1 sm:col-span-4">
                <Label :for="`${idPrefix}-note-${row.key}`" class="sr-only">
                    Nota del ingrediente {{ index + 1 }}
                </Label>
                <Input
                    :id="`${idPrefix}-note-${row.key}`"
                    :name="`ingredients[${index}][note]`"
                    :default-value="row.note ?? ''"
                    placeholder="Nota opcional (por ejemplo: picado)"
                    class="h-8"
                />
                <InputError :message="errors[`ingredients.${index}.note`]" />
            </div>
            <Button
                v-if="withAlternatives && row.ingredient.trim() !== ''"
                type="button"
                variant="link"
                size="sm"
                class="h-auto justify-self-start p-0 text-xs sm:col-span-4"
                @click="addAlternative(index)"
            >
                <Plus />
                Añadir una alternativa a «{{ row.ingredient.trim() }}»
            </Button>
        </div>

        <p v-if="rows.length === 0" class="text-sm text-muted-foreground">
            Sin ingredientes.
        </p>

        <Button
            type="button"
            variant="outline"
            size="sm"
            class="justify-self-start"
            @click="addRow"
        >
            <Plus />
            Añadir ingrediente
        </Button>
    </div>
</template>
