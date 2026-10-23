<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { BookOpen, Plus } from '@lucide/vue';
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

        <nav class="flex gap-2 text-sm" aria-label="Filtrar recetas">
            <Link
                :href="index()"
                :class="
                    !showArchived
                        ? 'font-semibold underline'
                        : 'text-muted-foreground'
                "
            >
                Activas
            </Link>
            <span class="text-muted-foreground">·</span>
            <Link
                :href="index({ query: { archived: 1 } })"
                :class="
                    showArchived
                        ? 'font-semibold underline'
                        : 'text-muted-foreground'
                "
            >
                Archivadas ({{ archivedCount }})
            </Link>
        </nav>

        <div
            v-if="recipes.length === 0"
            class="flex flex-col items-center gap-2 rounded-xl border border-dashed p-8 text-center"
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

        <ul v-else class="divide-y rounded-xl border">
            <li v-for="recipe in recipes" :key="recipe.id">
                <Link
                    :href="show(recipe.id)"
                    class="flex items-center gap-3 px-4 py-3 hover:bg-accent"
                >
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium">{{ recipe.name }}</p>
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
                        variant="outline"
                    >
                        Sin ingredientes
                    </Badge>
                </Link>
            </li>
        </ul>
    </div>
</template>
