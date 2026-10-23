<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import IngredientRowsEditor from '@/components/IngredientRowsEditor.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
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
 * The trigger is given by the parent through the `trigger` slot.
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

const open = ref(false);
const type = ref<'recipe' | 'adhoc'>('recipe');

watch(open, (value) => {
    if (value) {
        type.value =
            props.dish?.type ?? (props.recipes.length > 0 ? 'recipe' : 'adhoc');
    }
});

const action = computed(() =>
    props.dish === null
        ? store.form({ week: props.week, day: props.day, meal: props.meal })
        : update.form(props.dish.id),
);

const recipeOptions = computed(() =>
    props.recipes.map((recipe) => ({
        value: String(recipe.id),
        label: `${recipe.name} (${recipe.base_servings} rac.)`,
    })),
);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
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
                        <div class="grid gap-2">
                            <Label :for="`${idPrefix}-recipe`">Receta</Label>
                            <NativeSelect
                                :id="`${idPrefix}-recipe`"
                                name="recipe_id"
                                :options="recipeOptions"
                                :default-value="
                                    dish?.type === 'recipe'
                                        ? String(dish.recipe_id)
                                        : null
                                "
                                placeholder="Elige una receta"
                            />
                            <InputError :message="errors.recipe_id" />
                        </div>
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
                            (type === 'recipe' && recipes.length === 0)
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
