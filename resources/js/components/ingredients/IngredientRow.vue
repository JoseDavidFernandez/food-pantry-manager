<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import ImagePicker from '@/components/ingredients/ImagePicker.vue';
import IngredientThumb from '@/components/IngredientThumb.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Badge } from '@/components/ui/badge';
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
import { destroy, update } from '@/routes/ingredients';
import {
    destroy as destroyAlias,
    store as storeAlias,
} from '@/routes/ingredients/aliases';
import type { Ingredient, Option, UnitOption } from '@/types/pantry';

const props = defineProps<{
    ingredient: Ingredient;
    units: UnitOption[];
    categories: Option[];
    imageSearch: boolean;
}>();

const editOpen = ref(false);
const deleteOpen = ref(false);

// RN-14 / DATA-MODEL §7: ingredients in use cannot be deleted.
const inUse = computed(
    () =>
        props.ingredient.stock_count > 0 ||
        props.ingredient.recipe_count > 0 ||
        props.ingredient.in_menus,
);

const defaultUnitLabel = computed(
    () =>
        props.units.find((unit) => unit.value === props.ingredient.default_unit)
            ?.label,
);
</script>

<template>
    <li class="grid gap-3 px-4 py-4">
        <div class="flex items-center gap-3">
            <IngredientThumb
                :image-url="ingredient.image_url"
                :category="ingredient.category"
            />
            <div class="min-w-0 flex-1">
                <p class="truncate font-medium">{{ ingredient.name }}</p>
                <p
                    :class="
                        ingredient.category
                            ? 'text-sm text-muted-foreground'
                            : 'text-sm text-warning'
                    "
                >
                    {{ ingredient.category_label }}
                </p>
                <p class="text-xs text-muted-foreground">
                    <span v-if="defaultUnitLabel"
                        >Unidad habitual: {{ defaultUnitLabel }}</span
                    >
                    <span v-if="defaultUnitLabel"> · </span>
                    <span v-if="ingredient.stock_count">
                        En inventario ({{ ingredient.stock_count }}
                        {{ ingredient.stock_count === 1 ? 'fila' : 'filas' }})
                    </span>
                    <span v-else>Sin existencias</span>
                    <span v-if="ingredient.recipe_count">
                        · En {{ ingredient.recipe_count }}
                        {{
                            ingredient.recipe_count === 1 ? 'receta' : 'recetas'
                        }}
                    </span>
                    <span v-if="ingredient.in_menus"> · En menús</span>
                </p>
            </div>

            <Dialog v-model:open="editOpen">
                <DialogTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        :aria-label="`Editar ${ingredient.name}`"
                    >
                        <Pencil />
                    </Button>
                </DialogTrigger>
                <DialogContent
                    class="max-h-[90svh] overflow-y-auto sm:max-w-lg"
                >
                    <DialogHeader>
                        <DialogTitle>Editar ingrediente</DialogTitle>
                        <DialogDescription>
                            El nombre debe ser único en tu catálogo y no puede
                            coincidir con ningún alias.
                        </DialogDescription>
                    </DialogHeader>
                    <Form
                        v-bind="update.form(ingredient.id)"
                        :error-bag="`editIngredient${ingredient.id}`"
                        :options="{ preserveScroll: true }"
                        v-slot="{ errors, processing }"
                        class="grid gap-4"
                        @success="editOpen = false"
                    >
                        <div class="grid gap-2">
                            <Label :for="`name-${ingredient.id}`">Nombre</Label>
                            <Input
                                :id="`name-${ingredient.id}`"
                                name="name"
                                :default-value="ingredient.name"
                                required
                            />
                            <InputError :message="errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label :for="`default-unit-${ingredient.id}`"
                                >Unidad habitual (opcional)</Label
                            >
                            <NativeSelect
                                :id="`default-unit-${ingredient.id}`"
                                name="default_unit"
                                :options="units"
                                :default-value="ingredient.default_unit"
                                placeholder="Sin unidad habitual"
                            />
                            <InputError :message="errors.default_unit" />
                        </div>
                        <div class="grid gap-2">
                            <Label :for="`category-${ingredient.id}`"
                                >Categoría</Label
                            >
                            <NativeSelect
                                :id="`category-${ingredient.id}`"
                                name="category"
                                :options="categories"
                                :default-value="ingredient.category"
                                placeholder="Sin clasificar"
                            />
                            <InputError :message="errors.category" />
                        </div>
                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button type="button" variant="outline"
                                    >Cancelar</Button
                                >
                            </DialogClose>
                            <Button type="submit" :disabled="processing">
                                <Spinner v-if="processing" />
                                Guardar cambios
                            </Button>
                        </DialogFooter>
                    </Form>
                    <ImagePicker
                        v-if="imageSearch"
                        :id-prefix="`ingredient-${ingredient.id}`"
                        :ingredient-id="ingredient.id"
                        :initial-query="ingredient.name"
                        :category="ingredient.category"
                        :image-url="ingredient.image_url"
                        :credit="ingredient.image_credit"
                    />
                </DialogContent>
            </Dialog>

            <Dialog v-model:open="deleteOpen">
                <DialogTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        :aria-label="`Eliminar ${ingredient.name}`"
                    >
                        <Trash2 />
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle
                            >¿Eliminar «{{ ingredient.name }}»?</DialogTitle
                        >
                        <DialogDescription v-if="inUse">
                            No se puede eliminar porque se usa en el inventario,
                            en recetas o en menús. Quítalo primero de ahí.
                        </DialogDescription>
                        <DialogDescription v-else>
                            Se eliminarán también sus alias. Esta acción no se
                            puede deshacer.
                        </DialogDescription>
                    </DialogHeader>
                    <Form
                        v-bind="destroy.form(ingredient.id)"
                        :options="{ preserveScroll: true }"
                        v-slot="{ processing }"
                        @success="deleteOpen = false"
                    >
                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button type="button" variant="outline"
                                    >Cancelar</Button
                                >
                            </DialogClose>
                            <Button
                                type="submit"
                                variant="destructive"
                                :disabled="processing || inUse"
                            >
                                <Spinner v-if="processing" />
                                Eliminar ingrediente
                            </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm text-muted-foreground">Alias:</span>
            <Badge
                v-for="alias in ingredient.aliases"
                :key="alias.id"
                variant="secondary"
                class="gap-1 pr-1"
            >
                {{ alias.alias }}
                <Form
                    v-bind="
                        destroyAlias.form({
                            ingredient: ingredient.id,
                            alias: alias.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    class="inline-flex"
                >
                    <button
                        type="submit"
                        class="rounded-full p-0.5 hover:bg-muted-foreground/20"
                        :aria-label="`Quitar el alias ${alias.alias}`"
                    >
                        <X class="size-3" />
                    </button>
                </Form>
            </Badge>
            <span
                v-if="ingredient.aliases.length === 0"
                class="text-sm text-muted-foreground"
                >ninguno</span
            >
        </div>

        <Form
            v-bind="storeAlias.form(ingredient.id)"
            :error-bag="`addAlias${ingredient.id}`"
            reset-on-success
            :options="{ preserveScroll: true }"
            v-slot="{ errors, processing }"
            class="grid gap-1"
        >
            <div class="flex gap-2">
                <Label :for="`alias-${ingredient.id}`" class="sr-only"
                    >Nuevo alias de {{ ingredient.name }}</Label
                >
                <Input
                    :id="`alias-${ingredient.id}`"
                    name="alias"
                    placeholder="Añadir alias (plural o sinónimo)"
                    class="h-8"
                    required
                />
                <Button
                    type="submit"
                    variant="outline"
                    size="sm"
                    :disabled="processing"
                >
                    <Plus />
                    Añadir
                </Button>
            </div>
            <InputError :message="errors.alias" />
        </Form>
    </li>
</template>
