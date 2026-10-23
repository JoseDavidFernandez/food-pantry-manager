<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import IngredientRowsEditor from '@/components/IngredientRowsEditor.vue';
import InputError from '@/components/InputError.vue';
import ComponentsEditor from '@/components/recipes/ComponentsEditor.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index, show, store, update } from '@/routes/recipes';
import type {
    ComponentOption,
    IngredientLine,
    RecipeComponentLine,
    UnitOption,
} from '@/types/pantry';

const props = defineProps<{
    recipe: {
        id: number;
        name: string;
        base_servings: number;
        instructions: string | null;
        ingredients: IngredientLine[];
        components: RecipeComponentLine[];
    } | null;
    units: UnitOption[];
    suggestions: string[];
    componentOptions: ComponentOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Recetas', href: index() }],
    },
});

const title = computed(() =>
    props.recipe ? `Editar «${props.recipe.name}»` : 'Nueva receta',
);
const action = computed(() =>
    props.recipe ? update.form(props.recipe.id) : store.form(),
);
const cancelHref = computed(() =>
    props.recipe ? show(props.recipe.id) : index(),
);
</script>

<template>
    <Head :title="title" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 sm:p-6">
        <Heading
            :title="title"
            description="Indica las cantidades para las raciones base. Los ingredientes deben estar en tu catálogo (por nombre o alias)."
        />

        <Form
            v-bind="action"
            v-slot="{ errors, processing }"
            class="grid gap-6"
        >
            <div class="grid gap-4 sm:grid-cols-[1fr_8rem]">
                <div class="grid gap-2">
                    <Label for="name">Nombre</Label>
                    <Input
                        id="name"
                        name="name"
                        :default-value="recipe?.name ?? ''"
                        placeholder="Por ejemplo: pollo con arroz"
                        required
                    />
                    <InputError :message="errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="base_servings">Raciones</Label>
                    <Input
                        id="base_servings"
                        name="base_servings"
                        type="number"
                        min="1"
                        max="100"
                        :default-value="String(recipe?.base_servings ?? 4)"
                        required
                    />
                    <InputError :message="errors.base_servings" />
                </div>
            </div>

            <section class="grid gap-2">
                <h2 class="font-medium">Ingredientes</h2>
                <IngredientRowsEditor
                    id-prefix="recipe"
                    :initial-rows="recipe?.ingredients ?? []"
                    :units="units"
                    :suggestions="suggestions"
                    :errors="errors"
                    with-notes
                    with-alternatives
                />
            </section>

            <section class="grid gap-2" aria-labelledby="components-title">
                <div class="grid gap-1">
                    <h2 id="components-title" class="font-medium">
                        Preparaciones incluidas
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Otra receta que forma parte de esta (por ejemplo, una
                        salsa boloñesa en unos macarrones). Sus ingredientes se
                        suman al planificar, a escala, y si la cambias cambia en
                        todas las recetas que la usan.
                    </p>
                </div>
                <ComponentsEditor
                    :initial-rows="recipe?.components ?? []"
                    :options="componentOptions"
                    :errors="errors"
                />
            </section>

            <div class="grid gap-2">
                <Label for="instructions">Preparación (opcional)</Label>
                <textarea
                    id="instructions"
                    name="instructions"
                    rows="5"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
                    :value="recipe?.instructions ?? ''"
                />
                <InputError :message="errors.instructions" />
            </div>

            <div class="flex flex-wrap justify-end gap-2">
                <Button variant="outline" as-child>
                    <Link :href="cancelHref">Cancelar</Link>
                </Button>
                <Button type="submit" :disabled="processing">
                    <Spinner v-if="processing" />
                    Guardar receta
                </Button>
            </div>
        </Form>
    </div>
</template>
