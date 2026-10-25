<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import IngredientThumb from '@/components/IngredientThumb.vue';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/ingredients';
import type { Ingredient, Option } from '@/types/pantry';

/**
 * Quick classification of the foods without a category (RN-31): one selector per food, saved
 * on change through the same update as the food sheet (its name and usual unit are kept).
 * The list is fixed when the dialog opens, so a classified food stays visible and can go back
 * to «Sin clasificar».
 */
const props = defineProps<{
    foods: Ingredient[];
    categories: Option[];
}>();

const open = defineModel<boolean>('open', { required: true });

const ids = ref<number[]>([]);
const saving = ref<number | null>(null);
const saved = ref<number[]>([]);
const errors = ref<Record<number, string | undefined>>({});

watch(
    open,
    (value) => {
        if (value) {
            ids.value = props.foods
                .filter((food) => food.category === null)
                .map((food) => food.id);
            saved.value = [];
            errors.value = {};
        }
    },
    { immediate: true },
);

const rows = computed(() =>
    ids.value
        .map((id) => props.foods.find((food) => food.id === id))
        .filter((food): food is Ingredient => food !== undefined),
);

function classify(food: Ingredient, category: string): void {
    router.put(
        update.url(food.id),
        { name: food.name, default_unit: food.default_unit, category },
        {
            errorBag: `classify${food.id}`,
            preserveScroll: true,
            preserveState: true,
            onStart: () => (saving.value = food.id),
            onFinish: () => (saving.value = null),
            onSuccess: () => {
                errors.value[food.id] = undefined;
                saved.value = [...saved.value, food.id];
            },
            onError: (response) => {
                errors.value[food.id] =
                    response.category ?? Object.values(response)[0];
            },
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Clasificar alimentos</DialogTitle>
                <DialogDescription>
                    Elige la categoría de cada alimento; se guarda al momento.
                    Puedes dejar cualquiera en «Sin clasificar».
                </DialogDescription>
            </DialogHeader>

            <p v-if="rows.length === 0" class="text-sm text-muted-foreground">
                No hay alimentos sin clasificar.
            </p>
            <ul v-else class="divide-y">
                <li
                    v-for="food in rows"
                    :key="food.id"
                    class="grid gap-2 py-3 sm:grid-cols-[minmax(0,1fr)_13rem] sm:items-center"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <IngredientThumb
                            :image-url="food.image_url"
                            :category="food.category"
                        />
                        <Label
                            :for="`classify-${food.id}`"
                            class="min-w-0 font-medium break-words"
                            >{{ food.name }}</Label
                        >
                        <Spinner v-if="saving === food.id" class="shrink-0" />
                        <Check
                            v-else-if="saved.includes(food.id)"
                            class="size-4 shrink-0 text-success"
                            aria-label="Guardado"
                        />
                    </div>
                    <div class="grid gap-1">
                        <select
                            :id="`classify-${food.id}`"
                            :value="food.category ?? ''"
                            :disabled="saving === food.id"
                            class="h-11 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring sm:h-10"
                            @change="
                                classify(
                                    food,
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option value="">Sin clasificar</option>
                            <option
                                v-for="option in categories"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                        <InputError :message="errors[food.id]" />
                    </div>
                </li>
            </ul>
        </DialogContent>
    </Dialog>
</template>
