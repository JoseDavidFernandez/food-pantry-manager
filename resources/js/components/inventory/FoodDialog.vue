<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Plus, Trash2, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ImagePicker from '@/components/ingredients/ImagePicker.vue';
import FoodRestockSection from '@/components/inventory/FoodRestockSection.vue';
import FoodUnitsSection from '@/components/inventory/FoodUnitsSection.vue';
import IngredientThumb from '@/components/IngredientThumb.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { destroy, update } from '@/routes/ingredients';
import {
    destroy as destroyAlias,
    store as storeAlias,
} from '@/routes/ingredients/aliases';
import type { Ingredient, Option, UnitOption } from '@/types/pantry';

/**
 * The catalog definition of a food (name, usual unit, category, image, aliases, units), edited from
 * Inventory. Its stock (quantity per location) is edited on each stock card instead.
 */
const props = defineProps<{
    food: Ingredient | null;
    units: UnitOption[];
    categories: Option[];
    imageSearch: boolean;
    /** Viewers (RN-38) see the sheet without its forms. */
    readonly?: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });
const confirmingDelete = ref(false);

watch(open, (value) => {
    if (!value) {
        confirmingDelete.value = false;
    }
});

// RN-14 / DATA-MODEL §7: foods in use cannot be deleted.
const inUse = computed(
    () =>
        props.food !== null &&
        (props.food.stock_count > 0 ||
            props.food.recipe_count > 0 ||
            props.food.in_menus),
);

const usage = computed(() => {
    if (props.food === null) {
        return '';
    }

    const parts = [
        props.food.stock_count > 0
            ? `En el inventario (${props.food.stock_count} ${props.food.stock_count === 1 ? 'fila' : 'filas'})`
            : 'Sin existencias',
    ];

    if (props.food.recipe_count > 0) {
        parts.push(
            `en ${props.food.recipe_count} ${props.food.recipe_count === 1 ? 'receta' : 'recetas'}`,
        );
    }

    if (props.food.in_menus) {
        parts.push('en menús');
    }

    return parts.join(' · ');
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            v-if="food"
            class="max-h-[90svh] overflow-y-auto sm:max-w-lg"
        >
            <DialogHeader>
                <div class="flex items-center gap-3">
                    <IngredientThumb
                        :image-url="food.image_url"
                        :category="food.category"
                    />
                    <div class="grid gap-1 text-left">
                        <DialogTitle>{{ food.name }}</DialogTitle>
                        <DialogDescription>{{ usage }}</DialogDescription>
                    </div>
                </div>
            </DialogHeader>

            <dl v-if="readonly" class="grid gap-2 text-sm">
                <div class="flex gap-2">
                    <dt class="text-muted-foreground">Categoría:</dt>
                    <dd>{{ food.category_label }}</dd>
                </div>
                <div class="flex gap-2">
                    <dt class="text-muted-foreground">Unidad habitual:</dt>
                    <dd>
                        {{
                            units.find((u) => u.value === food?.default_unit)
                                ?.label ?? 'Sin unidad habitual'
                        }}
                    </dd>
                </div>
            </dl>

            <Form
                v-else
                :key="food.id"
                v-bind="update.form(food.id)"
                :error-bag="`editIngredient${food.id}`"
                :options="{ preserveScroll: true }"
                v-slot="{ errors, processing }"
                class="grid gap-4"
            >
                <div class="grid gap-2">
                    <Label :for="`food-name-${food.id}`">Nombre</Label>
                    <Input
                        class="h-11 sm:h-9"
                        :id="`food-name-${food.id}`"
                        name="name"
                        :default-value="food.name"
                        required
                    />
                    <p class="text-xs text-muted-foreground">
                        Único en tu catálogo; no puede coincidir con ningún
                        alias.
                    </p>
                    <InputError :message="errors.name" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label :for="`food-category-${food.id}`"
                            >Categoría</Label
                        >
                        <NativeSelect
                            class="h-11 sm:h-9"
                            :id="`food-category-${food.id}`"
                            name="category"
                            :options="categories"
                            :default-value="food.category"
                            placeholder="Sin clasificar"
                        />
                        <InputError :message="errors.category" />
                    </div>
                    <div class="grid gap-2">
                        <Label :for="`food-unit-${food.id}`"
                            >Unidad habitual (opcional)</Label
                        >
                        <NativeSelect
                            class="h-11 sm:h-9"
                            :id="`food-unit-${food.id}`"
                            name="default_unit"
                            :options="units"
                            :default-value="food.default_unit"
                            placeholder="Sin unidad habitual"
                        />
                        <InputError :message="errors.default_unit" />
                    </div>
                </div>
                <Button
                    type="submit"
                    class="min-h-11 w-full sm:min-h-0 sm:w-auto sm:justify-self-end"
                    :disabled="processing"
                >
                    <Spinner v-if="processing" />
                    Guardar cambios
                </Button>
            </Form>

            <section
                class="grid gap-2 border-t pt-4"
                :aria-labelledby="`food-aliases-${food.id}`"
            >
                <h3 :id="`food-aliases-${food.id}`" class="text-sm font-medium">
                    Otros nombres (alias)
                </h3>
                <p class="text-xs text-muted-foreground">
                    Plurales o sinónimos que se reconocen como este alimento al
                    escribirlos en el inventario, las recetas o la compra.
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <Badge
                        v-for="alias in food.aliases"
                        :key="alias.id"
                        variant="secondary"
                        class="gap-1 overflow-visible pr-1"
                    >
                        {{ alias.alias }}
                        <Form
                            v-if="!readonly"
                            v-bind="
                                destroyAlias.form({
                                    ingredient: food.id,
                                    alias: alias.id,
                                })
                            "
                            :options="{ preserveScroll: true }"
                            class="inline-flex"
                        >
                            <button
                                type="submit"
                                class="relative rounded-full p-0.5 after:absolute after:-inset-3.5 after:content-[''] hover:bg-muted-foreground/20 sm:after:-inset-1"
                                :aria-label="`Quitar el alias ${alias.alias}`"
                            >
                                <X class="size-3" />
                            </button>
                        </Form>
                    </Badge>
                    <span
                        v-if="food.aliases.length === 0"
                        class="text-sm text-muted-foreground"
                        >Ninguno</span
                    >
                </div>
                <Form
                    v-if="!readonly"
                    v-bind="storeAlias.form(food.id)"
                    :error-bag="`addAlias${food.id}`"
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="grid gap-1"
                >
                    <div class="flex gap-2">
                        <Label :for="`food-alias-${food.id}`" class="sr-only"
                            >Nuevo alias de {{ food.name }}</Label
                        >
                        <Input
                            class="h-11 sm:h-9"
                            :id="`food-alias-${food.id}`"
                            name="alias"
                            placeholder="Por ejemplo: tomates"
                            required
                        />
                        <Button
                            class="min-h-11 sm:min-h-0"
                            type="submit"
                            variant="outline"
                            :disabled="processing"
                        >
                            <Plus />
                            Añadir
                        </Button>
                    </div>
                    <InputError :message="errors.alias" />
                </Form>
            </section>

            <FoodUnitsSection
                :food="food"
                :units="units"
                :readonly="readonly"
            />

            <FoodRestockSection
                :food="food"
                :units="units"
                :readonly="readonly"
            />

            <section v-if="imageSearch && !readonly" class="border-t pt-4">
                <ImagePicker
                    :key="food.id"
                    :id-prefix="`food-${food.id}`"
                    :ingredient-id="food.id"
                    :initial-query="food.name"
                    :category="food.category"
                    :image-url="food.image_url"
                    :credit="food.image_credit"
                />
            </section>

            <section v-if="!readonly" class="grid gap-2 border-t pt-4">
                <template v-if="inUse">
                    <p class="text-sm text-muted-foreground">
                        No se puede eliminar porque está en el inventario, en
                        recetas o en menús. Quítalo primero de ahí.
                    </p>
                </template>
                <Button
                    v-else-if="!confirmingDelete"
                    type="button"
                    variant="ghost"
                    class="min-h-11 justify-self-start text-destructive sm:min-h-0"
                    @click="confirmingDelete = true"
                >
                    <Trash2 />
                    Eliminar del catálogo
                </Button>
                <Form
                    v-else
                    v-bind="destroy.form(food.id)"
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                    class="grid gap-3 rounded-lg border border-destructive/40 p-3"
                    @success="open = false"
                >
                    <p class="text-sm">
                        Se eliminará «{{ food.name }}» y sus alias. Esta acción
                        no se puede deshacer.
                    </p>
                    <DialogFooter class="gap-2">
                        <Button
                            class="min-h-11 sm:min-h-0"
                            type="button"
                            variant="outline"
                            @click="confirmingDelete = false"
                            >Cancelar</Button
                        >
                        <Button
                            class="min-h-11 sm:min-h-0"
                            type="submit"
                            variant="destructive"
                            :disabled="processing"
                        >
                            <Spinner v-if="processing" />
                            Eliminar alimento
                        </Button>
                    </DialogFooter>
                </Form>
            </section>
        </DialogContent>
    </Dialog>
</template>
