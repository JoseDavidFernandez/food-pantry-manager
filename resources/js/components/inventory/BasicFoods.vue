<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import FoldSection from '@/components/FoldSection.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/ingredients';
import type { BasicFood } from '@/types/pantry';

/**
 * Usual foods to add to the catalog from «Sin existencias» (RN-49). Choosing one goes through
 * the usual creation of a food (`POST /ingredients`, RN-03/RN-04): no stock is added, and a name
 * that looks like an existing food asks for the same confirmation as anywhere else.
 */
const props = defineProps<{
    basics: BasicFood[];
    /** A search or category filter is active: the matching groups open. */
    filtering: boolean;
}>();

const groups = computed(() => {
    const byCategory = new Map<string, BasicFood[]>();

    for (const food of props.basics) {
        byCategory.set(food.category, [
            ...(byCategory.get(food.category) ?? []),
            food,
        ]);
    }

    return [...byCategory.entries()].map(([category, foods]) => ({
        category,
        label: foods[0].category_label,
        foods,
    }));
});

const saving = ref<string | null>(null);
// The refused name and why: either it already exists, or it looks like another food and
// can be created anyway (same message and confirmation as IngredientRequest).
const refused = ref<{ name: string; message: string } | null>(null);
const looksLike = computed(
    () => refused.value?.message.startsWith('Se parece a') ?? false,
);

function create(name: string, confirmNew = false): void {
    router.post(
        store.url(),
        { name, confirm_new: confirmNew },
        {
            errorBag: 'basicFood',
            preserveScroll: true,
            preserveState: true,
            onStart: () => (saving.value = name),
            onFinish: () => (saving.value = null),
            onSuccess: () => (refused.value = null),
            onError: (errors) => {
                refused.value = {
                    name,
                    message: errors.name ?? Object.values(errors)[0] ?? '',
                };
            },
        },
    );
}
</script>

<template>
    <section
        aria-labelledby="basic-foods-title"
        class="flex flex-col gap-1 rounded-sm bg-card p-4"
        data-basic-foods
    >
        <h2 id="basic-foods-title" class="font-semibold">
            Alimentos habituales
        </h2>
        <p class="text-sm text-muted-foreground">
            Añádelos a tu catálogo con un toque. No se suma nada al inventario:
            después usa «Añadir al inventario» cuando los tengas en casa.
        </p>

        <div
            v-if="refused"
            role="alert"
            class="my-2 flex flex-col gap-3 rounded-sm border border-warning/40 bg-warning/10 p-3 text-sm"
        >
            <p>{{ refused.message }}</p>
            <div class="flex flex-wrap justify-end gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    class="min-h-11 sm:min-h-8"
                    @click="refused = null"
                >
                    {{ looksLike ? 'Cancelar' : 'Cerrar' }}
                </Button>
                <Button
                    v-if="looksLike"
                    size="sm"
                    class="min-h-11 sm:min-h-8"
                    :disabled="saving !== null"
                    @click="create(refused.name, true)"
                >
                    Crear «{{ refused.name }}» igualmente
                </Button>
            </div>
        </div>

        <div class="mt-2">
            <FoldSection
                v-for="group in groups"
                :key="group.category"
                :title="group.label"
                :summary="`${group.foods.length} ${group.foods.length === 1 ? 'alimento' : 'alimentos'}`"
                :open="filtering"
            >
                <ul class="flex flex-wrap gap-2">
                    <li v-for="food in group.foods" :key="food.name">
                        <Button
                            variant="outline"
                            size="sm"
                            class="min-h-11 sm:min-h-8"
                            :aria-label="`Añadir ${food.name} al catálogo`"
                            :disabled="saving !== null"
                            @click="create(food.name)"
                        >
                            <Spinner v-if="saving === food.name" />
                            <Plus v-else aria-hidden="true" />
                            {{ food.name }}
                        </Button>
                    </li>
                </ul>
            </FoldSection>
        </div>
    </section>
</template>
