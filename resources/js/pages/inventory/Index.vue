<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    CircleOff,
    LayoutGrid,
    Plus,
    Refrigerator,
    Search,
    SearchX,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import FilterTabs from '@/components/FilterTabs.vue';
import IngredientThumb from '@/components/IngredientThumb.vue';
import AddStockDialog from '@/components/inventory/AddStockDialog.vue';
import ClassifyDialog from '@/components/inventory/ClassifyDialog.vue';
import FoodDialog from '@/components/inventory/FoodDialog.vue';
import InventoryItemRow from '@/components/inventory/InventoryItemRow.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useHouseholdAccess } from '@/composables/useHouseholdAccess';
import { locationIcon, UNCATEGORIZED } from '@/lib/visuals';
import { index } from '@/routes/inventory';
import type {
    Ingredient,
    InventoryItem,
    Option,
    SimilarIngredient,
    StorageLocation,
    UnitOption,
} from '@/types/pantry';

/** Filter value of the «Sin existencias» tab (InventoryController::UNSTOCKED). */
const UNSTOCKED = 'none';

type Filter = StorageLocation | typeof UNSTOCKED;

const props = defineProps<{
    items: InventoryItem[];
    foods: Ingredient[];
    filter: Filter | null;
    counts: Record<'all' | Filter, number>;
    suggestions: string[];
    similar: SimilarIngredient[];
    typed: string | null;
    units: UnitOption[];
    locations: Option[];
    categories: Option[];
    imageSearch: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Inventario', href: index() }],
    },
});

const tabs = computed(() => [
    {
        key: null,
        label: 'Todo',
        icon: LayoutGrid,
        count: props.counts.all,
        href: index(),
    },
    ...props.locations.map((location) => ({
        key: location.value,
        label: location.label,
        icon: locationIcon(location.value),
        count: props.counts[location.value as StorageLocation],
        href: index({ query: { location: location.value } }),
    })),
    {
        key: UNSTOCKED,
        label: 'Sin existencias',
        icon: CircleOff,
        count: props.counts[UNSTOCKED],
        href: index({ query: { location: UNSTOCKED } }),
    },
]);

const unstockedTab = computed(() => props.filter === UNSTOCKED);

// One dialog for adding stock and one for the food sheet, shared by every card.
const addOpen = ref(props.typed !== null);
const addCreate = ref(false);
const prefill = ref<string | null>(null);
const foodId = ref<number | null>(null);
const foodOpen = ref(false);
const { canWrite } = useHouseholdAccess();
const classifyOpen = ref(false);
const food = computed(
    () =>
        props.foods.find((candidate) => candidate.id === foodId.value) ?? null,
);
const foodsById = computed(
    () => new Map(props.foods.map((candidate) => [candidate.id, candidate])),
);

function openFood(id: number): void {
    foodId.value = id;
    foodOpen.value = true;
}

function addStockOf(name: string): void {
    prefill.value = name;
    addCreate.value = false;
    addOpen.value = true;
}

// Search and category filter are optional and instant (no request): the location tabs are
// the main navigation. The search also finds foods by their aliases.
const search = ref('');
const category = ref('');

const normalize = (text: string): string =>
    text
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase()
        .trim();

function matches(
    name: string,
    foodCategory: string | null,
    id: number,
): boolean {
    const term = normalize(search.value);
    const aliases = foodsById.value.get(id)?.aliases ?? [];

    return (
        (term === '' ||
            normalize(name).includes(term) ||
            aliases.some((alias) => normalize(alias.alias).includes(term))) &&
        (category.value === '' ||
            (category.value === UNCATEGORIZED
                ? foodCategory === null
                : foodCategory === category.value))
    );
}

const visible = computed(() =>
    props.items.filter((item) =>
        matches(item.ingredient, item.category, item.ingredient_id),
    ),
);

const unstocked = computed(() =>
    props.foods.filter((f) => f.stock_count === 0),
);
const visibleUnstocked = computed(() =>
    unstocked.value.filter((f) => matches(f.name, f.category, f.id)),
);

const filtering = computed(() => search.value !== '' || category.value !== '');
const uncategorized = computed(
    () => props.foods.filter((f) => f.category === null).length,
);

const sections = computed(() =>
    props.locations
        .filter(
            (location) =>
                props.filter === null || props.filter === location.value,
        )
        .map((location) => ({
            ...location,
            icon: locationIcon(location.value),
            items: visible.value.filter(
                (item) => item.location === location.value,
            ),
        }))
        .filter((section) => section.items.length > 0),
);

const hasContent = computed(() =>
    unstockedTab.value ? unstocked.value.length > 0 : props.items.length > 0,
);

const emptyMessage = computed(() => {
    if (unstockedTab.value) {
        return 'Todos los alimentos de tu catálogo tienen existencias.';
    }

    const names: Record<string, string> = {
        fridge: 'el frigorífico',
        freezer: 'el congelador',
        pantry: 'la despensa',
    };

    return props.filter !== null && names[props.filter]
        ? `No hay nada registrado en ${names[props.filter]}.`
        : 'Tu inventario está vacío.';
});

function usage(f: Ingredient): string {
    const parts: string[] = [];

    if (f.recipe_count > 0) {
        parts.push(
            `En ${f.recipe_count} ${f.recipe_count === 1 ? 'receta' : 'recetas'}`,
        );
    }

    if (f.in_menus) {
        parts.push('en menús');
    }

    return parts.length > 0 ? parts.join(' · ') : 'Sin usar en recetas';
}

function clearFilters(): void {
    search.value = '';
    category.value = '';
}
</script>

<template>
    <Head title="Inventario" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 sm:p-6">
        <PageHeader
            title="Inventario"
            description="Tus alimentos y lo que tienes de cada uno en el frigorífico, el congelador y la despensa."
        >
            <template #actions>
                <AddStockDialog
                    v-if="canWrite"
                    v-model:open="addOpen"
                    v-model:create="addCreate"
                    :prefill="prefill"
                    :suggestions="suggestions"
                    :similar="similar"
                    :typed="typed"
                    :units="units"
                    :locations="locations"
                    :categories="categories"
                    :default-location="
                        filter === UNSTOCKED
                            ? null
                            : (filter as StorageLocation | null)
                    "
                    @update:open="
                        (value: boolean) => !value && (prefill = null)
                    "
                />
            </template>
        </PageHeader>

        <FilterTabs
            label="Filtrar por ubicación"
            preserve-scroll
            :tabs="tabs.map((tab) => ({ ...tab, active: tab.key === filter }))"
        />

        <div
            v-if="hasContent"
            class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_16rem]"
            role="search"
        >
            <div class="relative">
                <Label for="inventory-search" class="sr-only"
                    >Buscar en el inventario</Label
                >
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    id="inventory-search"
                    v-model="search"
                    type="search"
                    placeholder="Buscar un alimento"
                    autocomplete="off"
                    class="h-11 bg-card pl-9 sm:h-10"
                />
            </div>
            <div>
                <Label for="inventory-category" class="sr-only"
                    >Filtrar por categoría</Label
                >
                <select
                    id="inventory-category"
                    v-model="category"
                    class="h-11 w-full rounded-md border border-input bg-card px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring sm:h-10"
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
            v-if="hasContent && uncategorized > 0 && category !== UNCATEGORIZED"
            class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg border border-warning-border bg-warning-soft px-3 py-2 text-sm"
        >
            {{
                uncategorized === 1
                    ? '1 alimento de tu catálogo está sin clasificar.'
                    : `${uncategorized} alimentos de tu catálogo están sin clasificar.`
            }}
            <Button
                v-if="canWrite"
                variant="link"
                size="sm"
                class="h-auto min-h-11 p-0 sm:min-h-0"
                @click="classifyOpen = true"
            >
                Ver cuáles y clasificar
            </Button>
        </p>

        <EmptyState
            v-if="!hasContent"
            :icon="Refrigerator"
            :title="emptyMessage"
            :description="
                unstockedTab
                    ? 'Aquí aparecen los alimentos de tu catálogo (por ejemplo, los de tus recetas) que no tienes en casa.'
                    : 'Añade lo que tienes con «Añadir al inventario»: se suma a lo que ya hay si la unidad es compatible.'
            "
        />

        <template v-else-if="unstockedTab">
            <EmptyState
                v-if="visibleUnstocked.length === 0"
                :icon="SearchX"
                title="Ningún alimento coincide con la búsqueda."
                description="Prueba con otro nombre o quita el filtro de categoría."
            >
                <Button
                    v-if="filtering"
                    variant="outline"
                    @click="clearFilters"
                >
                    Quitar filtros
                </Button>
            </EmptyState>
            <ul v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <li
                    v-for="f in visibleUnstocked"
                    :key="f.id"
                    class="flex flex-col gap-3 rounded-sm bg-card p-3"
                >
                    <button
                        type="button"
                        class="-m-1 flex min-w-0 items-start gap-3 rounded-sm p-1 text-left hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        :aria-label="`Ficha de ${f.name}`"
                        @click="openFood(f.id)"
                    >
                        <IngredientThumb
                            :image-url="f.image_url"
                            :category="f.category"
                        />
                        <span class="grid min-w-0 flex-1">
                            <span class="truncate font-semibold">{{
                                f.name
                            }}</span>
                            <span
                                :class="
                                    f.category
                                        ? 'text-xs text-muted-foreground'
                                        : 'text-xs text-warning'
                                "
                                >{{ f.category_label }}</span
                            >
                            <span class="text-xs text-muted-foreground">{{
                                usage(f)
                            }}</span>
                        </span>
                    </button>
                    <div v-if="canWrite" class="flex justify-end border-t pt-3">
                        <Button
                            variant="outline"
                            size="sm"
                            class="min-h-11 sm:min-h-8"
                            :aria-label="`Añadir ${f.name} al inventario`"
                            @click="addStockOf(f.name)"
                        >
                            <Plus />
                            Añadir al inventario
                        </Button>
                    </div>
                </li>
            </ul>
        </template>

        <template v-else>
            <EmptyState
                v-if="sections.length === 0"
                :icon="SearchX"
                title="Ningún alimento coincide con la búsqueda."
                description="Prueba con otro nombre o quita el filtro de categoría."
            >
                <Button
                    v-if="filtering"
                    variant="outline"
                    @click="clearFilters"
                >
                    Quitar filtros
                </Button>
            </EmptyState>

            <section
                v-for="section in sections"
                :key="section.value"
                class="grid gap-3"
                :aria-labelledby="`location-${section.value}`"
            >
                <h2
                    :id="`location-${section.value}`"
                    class="flex items-center gap-2 text-sm font-semibold"
                >
                    <span
                        class="flex size-7 items-center justify-center rounded-lg bg-accent text-accent-foreground"
                        aria-hidden="true"
                    >
                        <component :is="section.icon" class="size-4" />
                    </span>
                    {{ section.label }}
                    <span
                        class="font-normal text-muted-foreground tabular-nums"
                        >{{ section.items.length }}</span
                    >
                </h2>
                <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <InventoryItemRow
                        v-for="item in section.items"
                        :key="item.id"
                        :item="item"
                        :units="units"
                        :locations="locations"
                        :show-location="false"
                        :readonly="!canWrite"
                        @food="openFood"
                    />
                </ul>
            </section>
        </template>

        <ClassifyDialog
            v-model:open="classifyOpen"
            :foods="foods"
            :categories="categories"
        />

        <FoodDialog
            v-model:open="foodOpen"
            :readonly="!canWrite"
            :food="food"
            :units="units"
            :categories="categories"
            :image-search="imageSearch"
        />
    </div>
</template>
