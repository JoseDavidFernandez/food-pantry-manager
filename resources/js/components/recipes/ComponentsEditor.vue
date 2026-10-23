<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ComponentOption, RecipeComponentLine } from '@/types/pantry';

/**
 * Preparations included in a recipe (RN-43): another recipe of the household and how many of
 * its servings this recipe uses for its base servings. Its ingredients are added when planning.
 */
const props = defineProps<{
    initialRows: RecipeComponentLine[];
    options: ComponentOption[];
    errors: Record<string, string | undefined>;
}>();

type Row = { key: number; recipe_id: string; servings: string };

let nextKey = 0;
const rows = ref<Row[]>(
    props.initialRows.map((row) => ({
        key: nextKey++,
        recipe_id: String(row.recipe_id),
        servings: String(row.servings),
    })),
);

const choices = props.options.map((option) => ({
    value: String(option.id),
    label: `${option.name} (${option.base_servings} rac.)${option.archived ? ' · archivada' : ''}`,
}));

function addRow(): void {
    rows.value.push({ key: nextKey++, recipe_id: '', servings: '1' });
}

function removeRow(key: number): void {
    rows.value = rows.value.filter((row) => row.key !== key);
}
</script>

<template>
    <div class="grid gap-3">
        <p v-if="options.length === 0" class="text-sm text-muted-foreground">
            No hay otras recetas que se puedan incluir. Guarda primero la
            preparación (por ejemplo, una salsa) como receta.
        </p>

        <div
            v-for="(row, index) in rows"
            :key="row.key"
            class="grid gap-2 rounded-md border p-3 sm:grid-cols-[1fr_7rem_auto] sm:items-start"
        >
            <div class="grid gap-1">
                <Label :for="`component-recipe-${row.key}`" class="text-xs"
                    >Preparación</Label
                >
                <NativeSelect
                    :id="`component-recipe-${row.key}`"
                    :name="`components[${index}][recipe_id]`"
                    :options="choices"
                    :default-value="row.recipe_id"
                    placeholder="Elige una receta"
                />
                <InputError
                    :message="errors[`components.${index}.recipe_id`]"
                />
            </div>
            <div class="grid gap-1">
                <Label :for="`component-servings-${row.key}`" class="text-xs"
                    >Raciones que usa</Label
                >
                <Input
                    :id="`component-servings-${row.key}`"
                    :name="`components[${index}][servings]`"
                    type="number"
                    min="1"
                    max="100"
                    :default-value="row.servings"
                />
                <InputError :message="errors[`components.${index}.servings`]" />
            </div>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="sm:mt-5"
                :aria-label="`Quitar la preparación ${index + 1}`"
                @click="removeRow(row.key)"
            >
                <Trash2 />
            </Button>
        </div>

        <InputError :message="errors.components" />

        <Button
            v-if="options.length > 0"
            type="button"
            variant="outline"
            size="sm"
            class="justify-self-start"
            @click="addRow"
        >
            <Plus />
            Incluir una preparación
        </Button>
    </div>
</template>
