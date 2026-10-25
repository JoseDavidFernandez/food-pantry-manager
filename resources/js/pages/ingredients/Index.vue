<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Carrot, Search, SearchX } from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import ImagePicker from '@/components/ingredients/ImagePicker.vue';
import IngredientRow from '@/components/ingredients/IngredientRow.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { UNCATEGORIZED } from '@/lib/visuals';
import { index, store } from '@/routes/ingredients';
import { store as storeAlias } from '@/routes/ingredients/aliases';
import { index as inventoryIndex } from '@/routes/inventory';
import type {
    Ingredient,
    Option,
    SimilarIngredient,
    UnitOption,
} from '@/types/pantry';

const props = defineProps<{
    ingredients: Ingredient[];
    units: UnitOption[];
    categories: Option[];
    imageSearch: boolean;
    similar: SimilarIngredient[];
    typed: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Ingredientes', href: index() }],
    },
});

// A new picker after each creation, so a chosen image is never reused by mistake.
const pickerKey = ref(0);

const search = ref('');
const category = ref('');

const normalize = (text: string): string =>
    text
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase()
        .trim();

const visible = computed(() => {
    const term = normalize(search.value);

    return props.ingredients.filter(
        (ingredient) =>
            (term === '' ||
                normalize(ingredient.name).includes(term) ||
                ingredient.aliases.some((alias) =>
                    normalize(alias.alias).includes(term),
                )) &&
            (category.value === '' ||
                (category.value === UNCATEGORIZED
                    ? ingredient.category === null
                    : ingredient.category === category.value)),
    );
});

const uncategorized = computed(
    () => props.ingredients.filter((i) => i.category === null).length,
);
</script>

<template>
    <Head title="Ingredientes" />

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Ingredientes"
            description="Tu catálogo: cada ingrediente con su categoría, una imagen opcional y alias para plurales y sinónimos."
        >
            <template #meta>
                <p class="text-sm text-muted-foreground">
                    Las cantidades se gestionan en
                    <Link :href="inventoryIndex()" class="underline"
                        >Inventario</Link
                    >.
                </p>
            </template>
        </PageHeader>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Nuevo ingrediente</CardTitle>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="store.form()"
                    error-bag="createIngredient"
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="grid gap-4"
                    @success="pickerKey++"
                >
                    <input type="hidden" name="intent" value="create" />
                    <div class="grid gap-4 sm:grid-cols-[1fr_11rem_14rem]">
                        <div class="grid content-start gap-2">
                            <Label for="name">Nombre</Label>
                            <Input
                                id="name"
                                name="name"
                                placeholder="Por ejemplo: tomate frito"
                                required
                            />
                            <InputError :message="errors.name" />
                            <div
                                v-if="errors.name && similar.length > 0"
                                class="grid gap-2 rounded-lg border border-dashed p-3 text-sm"
                            >
                                <Label
                                    for="confirm_new"
                                    class="flex items-center gap-2 font-normal"
                                >
                                    <Checkbox
                                        id="confirm_new"
                                        name="confirm_new"
                                        value="1"
                                    />
                                    «{{ typed }}» es otro ingrediente: crearlo
                                    igualmente
                                </Label>
                            </div>
                        </div>
                        <div class="grid content-start gap-2">
                            <Label for="default_unit">Unidad habitual</Label>
                            <NativeSelect
                                id="default_unit"
                                name="default_unit"
                                :options="units"
                                placeholder="Sin unidad habitual"
                            />
                            <InputError :message="errors.default_unit" />
                        </div>
                        <div class="grid content-start gap-2">
                            <Label for="category">Categoría</Label>
                            <NativeSelect
                                id="category"
                                name="category"
                                :options="categories"
                                placeholder="Sin clasificar"
                            />
                            <InputError :message="errors.category" />
                        </div>
                    </div>

                    <details v-if="imageSearch" class="group">
                        <summary
                            class="cursor-pointer text-sm font-medium text-primary underline-offset-4 hover:underline"
                        >
                            Añadir una imagen (opcional)
                        </summary>
                        <ImagePicker
                            :key="pickerKey"
                            id-prefix="new-ingredient"
                            initial-query=""
                            :category="null"
                            class="mt-3"
                        />
                    </details>

                    <Button
                        type="submit"
                        class="w-full sm:w-auto sm:justify-self-end"
                        :disabled="processing"
                    >
                        <Spinner v-if="processing" />
                        Crear ingrediente
                    </Button>
                </Form>
            </CardContent>
        </Card>

        <div
            v-if="typed && similar.length > 0"
            class="grid gap-2 rounded-xl border bg-card p-4 text-sm"
        >
            <p class="font-medium">
                «{{ typed }}» se parece a ingredientes que ya tienes
            </p>
            <p class="text-muted-foreground">
                Si es un plural o un sinónimo, añádelo como alias y se
                reconocerá al escribirlo en el inventario.
            </p>
            <div class="flex flex-wrap gap-2">
                <Form
                    v-for="match in similar"
                    :key="match.id"
                    v-bind="storeAlias.form(match.id)"
                    :error-bag="`addAlias${match.id}`"
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                >
                    <input type="hidden" name="alias" :value="typed" />
                    <Button
                        type="submit"
                        variant="secondary"
                        size="sm"
                        :disabled="processing"
                    >
                        Añadir «{{ typed }}» como alias de «{{ match.name }}»
                    </Button>
                </Form>
            </div>
        </div>

        <EmptyState
            v-if="ingredients.length === 0"
            :icon="Carrot"
            title="Aún no hay ingredientes."
            description="Crea el primero aquí o directamente al añadir existencias al inventario."
        />

        <template v-else>
            <div
                class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_16rem]"
                role="search"
            >
                <div class="relative">
                    <Label for="ingredient-search" class="sr-only"
                        >Buscar ingredientes</Label
                    >
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        id="ingredient-search"
                        v-model="search"
                        type="search"
                        placeholder="Buscar por nombre o alias"
                        autocomplete="off"
                        class="h-10 bg-card pl-9"
                    />
                </div>
                <div>
                    <Label for="ingredient-category" class="sr-only"
                        >Filtrar por categoría</Label
                    >
                    <select
                        id="ingredient-category"
                        v-model="category"
                        class="h-10 w-full rounded-md border border-input bg-card px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <option value="">Todas las categorías</option>
                        <option
                            v-for="option in categories"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                        <option :value="UNCATEGORIZED">Sin clasificar</option>
                    </select>
                </div>
            </div>

            <p
                v-if="uncategorized > 0 && category !== UNCATEGORIZED"
                class="flex flex-wrap items-center gap-2 rounded-lg border border-warning-border bg-warning-soft px-3 py-2 text-sm"
            >
                {{
                    uncategorized === 1
                        ? '1 ingrediente está sin clasificar.'
                        : `${uncategorized} ingredientes están sin clasificar.`
                }}
                <Button
                    variant="link"
                    size="sm"
                    class="h-auto p-0"
                    @click="category = UNCATEGORIZED"
                >
                    Ver cuáles
                </Button>
            </p>

            <EmptyState
                v-if="visible.length === 0"
                :icon="SearchX"
                title="Ningún ingrediente coincide con la búsqueda."
            >
                <Button
                    variant="outline"
                    @click="
                        search = '';
                        category = '';
                    "
                >
                    Quitar filtros
                </Button>
            </EmptyState>

            <ul v-else class="divide-y rounded-xl border bg-card">
                <IngredientRow
                    v-for="ingredient in visible"
                    :key="ingredient.id"
                    :ingredient="ingredient"
                    :units="units"
                    :categories="categories"
                    :image-search="imageSearch"
                />
            </ul>
        </template>
    </div>
</template>
