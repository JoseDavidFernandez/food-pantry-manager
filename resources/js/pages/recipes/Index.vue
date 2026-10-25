<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { BookOpen, ChevronRight, Plus } from '@lucide/vue';
import FilterTabs from '@/components/FilterTabs.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { create, index, show } from '@/routes/recipes';
import { useHouseholdAccess } from '@/composables/useHouseholdAccess';
import type { RecipeSummary } from '@/types/pantry';

defineProps<{
    recipes: RecipeSummary[];
    showArchived: boolean;
    archivedCount: number;
}>();

const { canWrite } = useHouseholdAccess();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Recetas', href: index() }],
    },
});
</script>

<template>
    <Head title="Recetas" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Recetas"
                description="Tu recetario. Las cantidades son para las raciones base y se escalan al planificar."
            />
            <Button v-if="canWrite" as-child>
                <Link :href="create()">
                    <Plus />
                    Nueva receta
                </Link>
            </Button>
        </div>

        <FilterTabs
            label="Filtrar recetas"
            :tabs="[
                { label: 'Activas', href: index(), active: !showArchived },
                {
                    label: 'Archivadas',
                    href: index({ query: { archived: 1 } }),
                    active: showArchived,
                    count: archivedCount,
                },
            ]"
        />

        <div
            v-if="recipes.length === 0"
            class="flex flex-col items-center gap-2 rounded-md bg-muted p-8 text-center"
        >
            <BookOpen class="size-8 text-muted-foreground" />
            <p class="font-medium">
                {{
                    showArchived
                        ? 'No hay recetas archivadas.'
                        : 'Crea tu primera receta.'
                }}
            </p>
            <p v-if="!showArchived" class="text-sm text-muted-foreground">
                También puedes planificar platos sencillos directamente en el
                menú.
            </p>
        </div>

        <ul v-else class="grid gap-px overflow-hidden rounded-md bg-border">
            <li v-for="recipe in recipes" :key="recipe.id">
                <Link
                    :href="show(recipe.id)"
                    class="flex items-center gap-3 bg-card px-4 py-3 hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
                >
                    <span
                        class="grid size-10 shrink-0 place-items-center rounded-sm bg-secondary"
                        aria-hidden="true"
                    >
                        <BookOpen class="size-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-semibold">{{ recipe.name }}</p>
                        <p class="text-sm text-muted-foreground">
                            {{ recipe.base_servings }}
                            {{
                                recipe.base_servings === 1
                                    ? 'ración'
                                    : 'raciones'
                            }}
                            ·
                            {{ recipe.ingredient_count }}
                            {{
                                recipe.ingredient_count === 1
                                    ? 'ingrediente'
                                    : 'ingredientes'
                            }}
                        </p>
                    </div>
                    <Badge v-if="recipe.archived" variant="secondary"
                        >Archivada</Badge
                    >
                    <Badge
                        v-else-if="recipe.ingredient_count === 0"
                        variant="warning"
                    >
                        Sin ingredientes
                    </Badge>
                    <ChevronRight
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                </Link>
            </li>
        </ul>
    </div>
</template>
