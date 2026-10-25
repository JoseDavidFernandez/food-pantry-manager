<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import IngredientRowsEditor from '@/components/IngredientRowsEditor.vue';
import InputError from '@/components/InputError.vue';
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
import { store, update } from '@/routes/menu/dishes';
import type { PlannedDish, RecipeOption, UnitOption } from '@/types/pantry';

/**
 * Add a dish to a lunch or dinner, or change one (RN-16, RN-17): a saved recipe or a one-off dish.
 * The trigger is given by the parent through the `trigger` slot, or the parent opens it with
 * `v-model:open` (from the actions menu of a dish).
 */
const props = defineProps<{
    week: string;
    day: number;
    meal: string;
    title: string;
    /** The dish to change; null to add a new one at the end of the slot. */
    dish: PlannedDish | null;
    recipes: RecipeOption[];
    units: UnitOption[];
    suggestions: string[];
    idPrefix: string;
}>();

const open = defineModel<boolean>('open', { default: false });
const type = ref<'recipe' | 'adhoc'>('recipe');
const recipeId = ref('');
const recipeSearch = ref('');

watch(open, (value) => {
    if (value) {
        type.value =
            props.dish?.type ?? (props.recipes.length > 0 ? 'recipe' : 'adhoc');
        recipeId.value =
            props.dish?.type === 'recipe' ? String(props.dish.recipe_id) : '';
        recipeSearch.value = '';
    }
});

const action = computed(() =>
    props.dish === null
        ? store.form({ week: props.week, day: props.day, meal: props.meal })
        : update.form(props.dish.id),
);

// The recipe list with a search box, instead of a long select. The chosen one is sent through a
// hidden field, so filtering never drops it.
const normalise = (text: string) =>
    text
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase();

const visibleRecipes = computed(() => {
    const term = normalise(recipeSearch.value.trim());

    return term === ''
        ? props.recipes
        : props.recipes.filter((recipe) =>
              normalise(recipe.name).includes(term),
          );
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger v-if="$slots.trigger" as-child>
            <slot name="trigger" />
        </DialogTrigger>
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>
                    {{
                        dish
                            ? 'Cambia este plato; los demás de la comida no se tocan.'
                            : 'Se añade al final de esta comida, junto a los platos que ya tenga.'
                    }}
                    Planificar no reserva ni descuenta nada del inventario.
                </DialogDescription>
            </DialogHeader>

            <div class="flex gap-2" role="tablist" aria-label="Tipo de plato">
                <Button
                    type="button"
                    role="tab"
                    :aria-selected="type === 'recipe'"
                    :variant="type === 'recipe' ? 'default' : 'outline'"
                    size="sm"
                    @click="type = 'recipe'"
                >
                    Receta guardada
                </Button>
                <Button
                    type="button"
                    role="tab"
                    :aria-selected="type === 'adhoc'"
                    :variant="type === 'adhoc' ? 'default' : 'outline'"
                    size="sm"
                    @click="type = 'adhoc'"
                >
                    Plato sencillo
                </Button>
            </div>

            <Form
                v-bind="action"
                :error-bag="`dish${idPrefix}`"
                :options="{ preserveScroll: true }"
                v-slot="{ errors, processing }"
                class="grid gap-4"
                @success="open = false"
            >
                <input type="hidden" name="type" :value="type" />

                <template v-if="type === 'recipe'">
                    <p
                        v-if="recipes.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No tienes recetas activas. Crea una en Recetas o usa un
                        plato sencillo.
                    </p>
                    <div v-else class="grid gap-4 sm:grid-cols-[1fr_7rem]">
                        <fieldset class="grid min-w-0 gap-2">
                            <legend class="mb-2 text-sm font-medium">
                                Receta
                            </legend>
                            <input
                                type="hidden"
                                name="recipe_id"
                                :value="recipeId"
                            />
                            <Label
                                :for="`${idPrefix}-recipe-search`"
                                class="sr-only"
                                >Buscar receta</Label
                            >
                            <Input
                                :id="`${idPrefix}-recipe-search`"
                                v-model="recipeSearch"
                                type="search"
                                placeholder="Buscar receta"
                                autocomplete="off"
                            />
                            <div
                                role="radiogroup"
                                :aria-label="`Receta para ${title}`"
                                class="grid max-h-56 divide-y overflow-y-auto rounded-md border bg-card"
                            >
                                <label
                                    v-for="recipe in visibleRecipes"
                                    :key="recipe.id"
                                    class="flex min-h-11 cursor-pointer items-center gap-3 px-3 py-2 text-sm has-[:checked]:bg-accent has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring has-[:focus-visible]:ring-inset sm:min-h-10"
                                >
                                    <input
                                        v-model="recipeId"
                                        type="radio"
                                        :name="`${idPrefix}-recipe-choice`"
                                        :value="String(recipe.id)"
                                        class="size-4 shrink-0 accent-primary"
                                    />
                                    <span class="min-w-0 flex-1 break-words">{{
                                        recipe.name
                                    }}</span>
                                    <span
                                        class="shrink-0 text-xs text-muted-foreground"
                                        >{{ recipe.base_servings }} rac.</span
                                    >
                                </label>
                                <p
                                    v-if="visibleRecipes.length === 0"
                                    class="p-3 text-sm text-muted-foreground"
                                >
                                    Ninguna receta coincide con «{{
                                        recipeSearch
                                    }}».
                                </p>
                            </div>
                            <InputError :message="errors.recipe_id" />
                        </fieldset>
                        <div class="grid gap-2">
                            <Label :for="`${idPrefix}-servings-r`"
                                >Raciones</Label
                            >
                            <Input
                                :id="`${idPrefix}-servings-r`"
                                name="servings"
                                type="number"
                                min="1"
                                max="100"
                                :default-value="String(dish?.servings ?? 2)"
                            />
                            <InputError :message="errors.servings" />
                        </div>
                    </div>
                </template>

                <template v-else>
                    <div class="grid gap-4 sm:grid-cols-[1fr_7rem]">
                        <div class="grid gap-2">
                            <Label :for="`${idPrefix}-name`"
                                >Nombre del plato</Label
                            >
                            <Input
                                :id="`${idPrefix}-name`"
                                name="name"
                                :default-value="
                                    dish?.type === 'adhoc' ? dish.name : ''
                                "
                                placeholder="Por ejemplo: fruta o sobras de lentejas"
                            />
                            <InputError :message="errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label :for="`${idPrefix}-servings-a`"
                                >Raciones</Label
                            >
                            <Input
                                :id="`${idPrefix}-servings-a`"
                                name="servings"
                                type="number"
                                min="1"
                                max="100"
                                :default-value="String(dish?.servings ?? 2)"
                            />
                            <InputError :message="errors.servings" />
                        </div>
                    </div>
                    <section class="grid gap-2">
                        <h3 class="text-sm font-medium">
                            Ingredientes (puedes completarlos más tarde)
                        </h3>
                        <IngredientRowsEditor
                            :id-prefix="idPrefix"
                            :initial-rows="
                                dish?.type === 'adhoc' ? dish.ingredients : []
                            "
                            :units="units"
                            :suggestions="suggestions"
                            :errors="errors"
                        />
                    </section>
                </template>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="outline"
                            >Cancelar</Button
                        >
                    </DialogClose>
                    <Button
                        type="submit"
                        :disabled="
                            processing ||
                            (type === 'recipe' &&
                                (recipes.length === 0 || recipeId === ''))
                        "
                    >
                        <Spinner v-if="processing" />
                        {{ dish ? 'Guardar cambios' : 'Añadir a esta comida' }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
