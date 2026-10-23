<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    BookOpen,
    CalendarDays,
    ChevronRight,
    CircleAlert,
    Moon,
    Refrigerator,
    ShoppingCart,
    Sun,
} from '@lucide/vue';
import { computed } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as inventory } from '@/routes/inventory';
import { show as showMenu } from '@/routes/menu';
import { index as recipes, show as showRecipe } from '@/routes/recipes';
import { period as shoppingPeriod } from '@/routes/shopping-list';

type DishSummary = {
    name: string;
    servings: number;
    type: 'recipe' | 'adhoc';
    recipe_id: number | null;
    not_eaten: boolean;
    complete: boolean;
};

type HomeDay = {
    day: number;
    date: string;
    label: string;
    is_today: boolean;
    lunch: DishSummary[];
    dinner: DishSummary[];
};

const props = defineProps<{
    week: { key: string; label: string };
    today: HomeDay;
    days: HomeDay[];
    menu: { planned: number; slots: number } | null;
    list: {
        to_buy: number;
        to_review: number;
        items: {
            id: number;
            name: string;
            quantity: string | null;
            needs_review: boolean;
        }[];
    };
    stockCount: number;
    recipeCount: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Inicio', href: dashboard() }],
    },
});

const plural = (count: number, one: string, many: string): string =>
    `${count} ${count === 1 ? one : many}`;

const meals = computed(() => [
    { key: 'lunch', label: 'Comida', icon: Sun, dishes: props.today.lunch },
    { key: 'dinner', label: 'Cena', icon: Moon, dishes: props.today.dinner },
]);

const shortDay = (label: string): string => label.slice(0, 3);

const servings = (dish: DishSummary): string =>
    plural(dish.servings, 'ración', 'raciones');

const remaining = computed(() => props.list.to_buy - props.list.items.length);
</script>

<template>
    <Head title="Inicio" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 sm:p-6">
        <PageHeader title="Inicio" :description="week.label" />

        <!-- 1. Today: the most important answer, in the biggest block. -->
        <section
            aria-labelledby="today-title"
            class="grid gap-4 rounded-2xl border border-primary/25 bg-accent/50 p-4 sm:p-6"
        >
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="today-title" class="text-lg font-semibold">
                    Hoy, {{ today.label.toLowerCase() }}
                </h2>
                <Link
                    :href="showMenu(week.key)"
                    class="text-sm font-medium text-primary underline-offset-4 hover:underline"
                    >Cambiar en el menú</Link
                >
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <article
                    v-for="meal in meals"
                    :key="meal.key"
                    class="grid content-start gap-2 rounded-xl border bg-card p-4 shadow-xs sm:p-5"
                    :aria-label="`${meal.label} de hoy`"
                >
                    <p
                        class="flex items-center gap-2 text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        <component
                            :is="meal.icon"
                            class="size-4"
                            aria-hidden="true"
                        />
                        {{ meal.label }}
                    </p>
                    <ul v-if="meal.dishes.length > 0" class="grid gap-3">
                        <li
                            v-for="(dish, index) in meal.dishes"
                            :key="index"
                            class="grid gap-1"
                        >
                            <p
                                :class="
                                    cn(
                                        'leading-tight font-semibold break-words',
                                        index === 0
                                            ? 'text-xl sm:text-2xl'
                                            : 'text-lg',
                                    )
                                "
                            >
                                {{ dish.name }}
                            </p>
                            <p
                                class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground"
                            >
                                {{ servings(dish) }}
                                <span
                                    v-if="dish.not_eaten"
                                    class="font-medium text-warning"
                                    >No comido</span
                                >
                                <span
                                    v-if="!dish.complete"
                                    class="flex items-center gap-1 text-warning"
                                >
                                    <CircleAlert
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    Sin ingredientes
                                </span>
                                <Link
                                    v-if="dish.recipe_id"
                                    :href="showRecipe(dish.recipe_id)"
                                    class="font-medium text-primary underline-offset-4 hover:underline"
                                    >Ver receta</Link
                                >
                            </p>
                        </li>
                    </ul>
                    <template v-else>
                        <p class="text-lg text-muted-foreground">
                            Sin planificar
                        </p>
                        <Link
                            :href="showMenu(week.key)"
                            class="text-sm font-medium text-primary underline-offset-4 hover:underline"
                            >Planificar {{ meal.label.toLowerCase() }}</Link
                        >
                    </template>
                </article>
            </div>
        </section>

        <div
            class="grid items-start gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]"
        >
            <!-- 2. The week, compact. -->
            <section
                aria-labelledby="week-title"
                class="grid gap-3 rounded-xl border bg-card p-4 shadow-xs sm:p-5"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2
                        id="week-title"
                        class="flex flex-wrap items-center gap-2 font-semibold"
                    >
                        Menú de la semana
                    </h2>
                    <span class="text-sm text-muted-foreground tabular-nums">
                        {{
                            menu === null
                                ? 'Sin planificar'
                                : `${menu.planned} de ${menu.slots}`
                        }}
                    </span>
                </div>

                <table class="w-full table-fixed text-sm">
                    <caption class="sr-only">
                        Comida y cena de cada día de la semana
                    </caption>
                    <thead>
                        <tr
                            class="text-left text-xs text-muted-foreground uppercase"
                        >
                            <th
                                scope="col"
                                class="w-12 pb-1 font-medium sm:w-24"
                            >
                                <span class="sr-only">Día</span>
                            </th>
                            <th scope="col" class="pb-1 font-medium">Comida</th>
                            <th scope="col" class="pb-1 pl-2 font-medium">
                                Cena
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="day in days"
                            :key="day.day"
                            :class="
                                cn(
                                    'border-t align-top',
                                    day.is_today && 'bg-accent/60 font-medium',
                                )
                            "
                            :aria-current="day.is_today ? 'date' : undefined"
                        >
                            <th
                                scope="row"
                                class="py-2 pl-1 text-left font-semibold"
                            >
                                <span class="sm:hidden" aria-hidden="true">{{
                                    shortDay(day.label)
                                }}</span>
                                <span class="sr-only sm:not-sr-only">{{
                                    day.label
                                }}</span>
                            </th>
                            <td
                                v-for="(dishes, index) in [
                                    day.lunch,
                                    day.dinner,
                                ]"
                                :key="index"
                                :class="
                                    cn(
                                        'py-2 pr-1 break-words',
                                        index === 1 && 'pl-2',
                                    )
                                "
                            >
                                <span v-if="dishes.length > 0">{{
                                    dishes.map((dish) => dish.name).join(', ')
                                }}</span>
                                <span v-else class="text-muted-foreground"
                                    >—<span class="sr-only">Vacío</span></span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>

                <Button variant="outline" class="justify-self-start" as-child>
                    <Link :href="showMenu(week.key)">
                        <CalendarDays />
                        Abrir el menú
                    </Link>
                </Button>
            </section>

            <!-- 3. What is left to buy. -->
            <section
                aria-labelledby="list-title"
                class="grid gap-3 rounded-xl border bg-card p-4 shadow-xs sm:p-5"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2
                        id="list-title"
                        class="flex flex-wrap items-center gap-2 font-semibold"
                    >
                        Lista de la compra
                        <Badge v-if="list.to_review > 0" variant="outline">
                            {{ list.to_review }} para revisar
                        </Badge>
                    </h2>
                    <span
                        v-if="list.to_buy > 0"
                        class="text-sm text-muted-foreground tabular-nums"
                        >{{
                            plural(list.to_buy, 'pendiente', 'pendientes')
                        }}</span
                    >
                </div>

                <p class="text-xs text-muted-foreground">Próximos 7 días</p>
                <p
                    v-if="list.to_buy === 0"
                    class="text-sm text-muted-foreground"
                >
                    No te falta nada para las recetas de los próximos 7 días.
                </p>
                <ul v-else class="divide-y">
                    <li
                        v-for="item in list.items"
                        :key="item.id"
                        class="flex items-baseline justify-between gap-3 py-2 text-sm"
                    >
                        <span class="min-w-0 break-words">
                            {{ item.name }}
                            <CircleAlert
                                v-if="item.needs_review"
                                class="ml-1 inline size-3.5 text-warning"
                                aria-label="Para revisar"
                            />
                        </span>
                        <span
                            v-if="item.quantity"
                            class="shrink-0 font-medium tabular-nums"
                            >{{ item.quantity }}</span
                        >
                    </li>
                    <li
                        v-if="remaining > 0"
                        class="py-2 text-sm text-muted-foreground"
                    >
                        y {{ plural(remaining, 'producto', 'productos') }}
                        más
                    </li>
                </ul>

                <Button variant="outline" class="justify-self-start" as-child>
                    <Link :href="shoppingPeriod()">
                        <ShoppingCart />
                        Ver la lista completa
                    </Link>
                </Button>
            </section>
        </div>

        <!-- 4. Secondary shortcuts. -->
        <nav aria-label="Accesos" class="grid gap-3 sm:grid-cols-3">
            <Link
                v-for="shortcut in [
                    {
                        href: inventory(),
                        icon: Refrigerator,
                        title: 'Inventario',
                        text:
                            stockCount === 0
                                ? 'Aún no has registrado alimentos.'
                                : plural(
                                      stockCount,
                                      'existencia',
                                      'existencias',
                                  ),
                    },
                    {
                        href: recipes(),
                        icon: BookOpen,
                        title: 'Recetas',
                        text:
                            recipeCount === 0
                                ? 'Crea tu primera receta.'
                                : plural(recipeCount, 'receta', 'recetas'),
                    },
                    {
                        href: showMenu(),
                        icon: CalendarDays,
                        title: 'Planificar',
                        text: 'Comidas y cenas de la semana.',
                    },
                ]"
                :key="shortcut.title"
                :href="shortcut.href"
                class="flex items-center gap-3 rounded-xl border bg-card p-4 shadow-xs transition-colors hover:border-primary/40 hover:bg-accent/40 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
            >
                <span
                    class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-accent text-accent-foreground"
                    aria-hidden="true"
                    ><component :is="shortcut.icon" class="size-5"
                /></span>
                <span class="grid min-w-0 flex-1">
                    <span class="font-medium">{{ shortcut.title }}</span>
                    <span class="text-sm text-muted-foreground">{{
                        shortcut.text
                    }}</span>
                </span>
                <ChevronRight class="size-4 text-muted-foreground" />
            </Link>
        </nav>
    </div>
</template>
