<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    CalendarDays,
    CircleAlert,
    Moon,
    ShoppingCart,
    Sun,
} from '@lucide/vue';
import { computed } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { show as showMenu } from '@/routes/menu';
import { show as showRecipe } from '@/routes/recipes';
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

// R6: with nothing planned this week, Inicio leads with one action instead of empty tables.
const weekEmpty = computed(
    () => props.menu === null || props.menu.planned === 0,
);

// R7: on the last day of the week there is nothing left to plan in it, so the call to action
// points to next week (the key is the Monday, YYYY-MM-DD; noon UTC avoids DST edges).
const lastDay = computed(() => props.today.day === 7);
const nextWeekKey = computed(() => {
    const monday = new Date(`${props.week.key}T12:00:00Z`);
    monday.setUTCDate(monday.getUTCDate() + 7);

    return monday.toISOString().slice(0, 10);
});
</script>

<template>
    <Head title="Inicio" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 sm:p-6">
        <PageHeader title="Inicio" :description="week.label" />

        <!-- An empty week: one clear next step. -->
        <!-- Carbón in both themes (the sidebar's tone), so it never turns into a light slab in
             dark mode. -->
        <section
            v-if="weekEmpty"
            class="flex flex-wrap items-center justify-between gap-3 rounded-md bg-sidebar p-4 text-sidebar-foreground ring-1 ring-sidebar-border sm:p-5"
            :aria-label="
                lastDay
                    ? 'Planificar la semana que viene'
                    : 'Planificar esta semana'
            "
        >
            <p class="max-w-prose text-pretty">
                <span class="block text-xl font-display">{{
                    lastDay
                        ? 'Hoy termina la semana'
                        : 'Esta semana aún no tiene comidas'
                }}</span>
                <span class="text-sm text-sidebar-foreground/80"
                    >Elige qué vais a comer y la lista de la compra se hará
                    sola.</span
                >
            </p>
            <Button as-child>
                <Link :href="showMenu(lastDay ? nextWeekKey : week.key)">
                    <CalendarDays />
                    {{
                        lastDay
                            ? 'Planificar la semana que viene'
                            : 'Planificar esta semana'
                    }}
                </Link>
            </Button>
        </section>

        <!-- 1. Today: lunch and dinner as the two big tiles of the day. -->
        <section aria-labelledby="today-title" class="grid gap-3">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2
                    id="today-title"
                    class="text-sm font-bold tracking-wide uppercase"
                >
                    Hoy, {{ today.label.toLowerCase() }}
                </h2>
                <Link
                    v-if="!weekEmpty"
                    :href="showMenu(week.key)"
                    class="tap-target text-sm font-semibold text-primary underline decoration-primary/40 decoration-2 underline-offset-4 hover:decoration-primary"
                    >Cambiar en el menú</Link
                >
            </div>
            <div class="grid gap-1.5 rounded-md bg-border p-1.5 sm:grid-cols-2">
                <article
                    v-for="meal in meals"
                    :key="meal.key"
                    :class="
                        cn(
                            'relative grid min-h-32 content-start gap-2 overflow-hidden rounded-sm p-4 sm:p-5',
                            meal.dishes.length === 0
                                ? 'bg-muted'
                                : meal.key === 'lunch'
                                  ? 'bg-tile-lunch'
                                  : 'bg-tile-dinner',
                        )
                    "
                    :aria-label="`${meal.label} de hoy`"
                >
                    <span
                        v-if="meal.dishes.length > 0"
                        aria-hidden="true"
                        :class="
                            cn(
                                'pointer-events-none absolute right-0 bottom-0 size-6 rounded-tl-full',
                                meal.key === 'lunch'
                                    ? 'bg-pigment-ocre'
                                    : 'bg-pigment-cobalto',
                            )
                        "
                    />
                    <p
                        :class="
                            cn(
                                'flex items-center gap-2 text-xs font-bold tracking-wide uppercase',
                                meal.dishes.length > 0
                                    ? 'text-foreground/75'
                                    : 'text-muted-foreground',
                            )
                        "
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
                                        'leading-tight font-bold break-words',
                                        index === 0
                                            ? 'text-xl sm:text-2xl'
                                            : 'text-lg',
                                    )
                                "
                            >
                                {{ dish.name }}
                            </p>
                            <p
                                class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-foreground/75"
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
                                    class="tap-target font-semibold text-foreground underline decoration-primary/40 decoration-2 underline-offset-4 hover:decoration-primary"
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
                            class="tap-target w-fit text-sm font-semibold text-primary underline decoration-primary/40 decoration-2 underline-offset-4 hover:decoration-primary"
                            >Planificar {{ meal.label.toLowerCase() }}</Link
                        >
                    </template>
                </article>
            </div>
        </section>

        <div
            class="grid items-start gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]"
        >
            <!-- 2. The week as a small floor of tiles (still a table for assistive tech). -->
            <section aria-labelledby="week-title" class="grid gap-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2
                        id="week-title"
                        class="text-sm font-bold tracking-wide uppercase"
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

                <div class="rounded-md bg-border p-1">
                    <table
                        class="w-full table-fixed border-separate border-spacing-1 text-sm"
                    >
                        <caption class="sr-only">
                            Comida y cena de cada día de la semana
                        </caption>
                        <thead>
                            <tr class="text-left text-xs font-bold uppercase">
                                <th scope="col" class="w-14 px-2 sm:w-28">
                                    <span class="sr-only">Día</span>
                                </th>
                                <th scope="col" class="px-2">
                                    <span class="flex items-center gap-1.5"
                                        ><span
                                            class="size-2 rounded-full bg-pigment-ocre"
                                            aria-hidden="true"
                                        />Comida</span
                                    >
                                </th>
                                <th scope="col" class="px-2">
                                    <span class="flex items-center gap-1.5"
                                        ><span
                                            class="size-2 rounded-full bg-pigment-cobalto"
                                            aria-hidden="true"
                                        />Cena</span
                                    >
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="day in days"
                                :key="day.day"
                                class="align-top"
                                :aria-current="
                                    day.is_today ? 'date' : undefined
                                "
                            >
                                <th
                                    scope="row"
                                    :class="
                                        cn(
                                            'rounded-sm px-2 py-1.5 text-left font-semibold',
                                            day.is_today
                                                ? 'bg-foreground text-background'
                                                : 'bg-card',
                                        )
                                    "
                                >
                                    <span
                                        class="sm:hidden"
                                        aria-hidden="true"
                                        >{{ shortDay(day.label) }}</span
                                    >
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
                                            'rounded-sm px-2 py-1.5 break-words',
                                            dishes.length === 0
                                                ? 'bg-muted text-muted-foreground'
                                                : index === 0
                                                  ? 'bg-tile-lunch'
                                                  : 'bg-tile-dinner',
                                        )
                                    "
                                >
                                    <span v-if="dishes.length > 0">{{
                                        dishes
                                            .map((dish) => dish.name)
                                            .join(', ')
                                    }}</span>
                                    <span v-else
                                        >—<span class="sr-only"
                                            >Vacío</span
                                        ></span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Button variant="outline" class="justify-self-start" as-child>
                    <Link :href="showMenu(week.key)">
                        <CalendarDays />
                        Abrir el menú
                    </Link>
                </Button>
            </section>

            <!-- 3. What is left to buy. -->
            <section aria-labelledby="list-title" class="grid gap-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2
                        id="list-title"
                        class="flex flex-wrap items-center gap-2 text-sm font-bold tracking-wide uppercase"
                    >
                        Lista de la compra
                        <Badge v-if="list.to_review > 0" variant="warning">
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

                <p class="-mt-2 text-xs text-muted-foreground">
                    Próximos 7 días
                </p>
                <p
                    v-if="list.to_buy === 0"
                    class="rounded-md bg-muted p-4 text-sm text-muted-foreground"
                >
                    No te falta nada para las recetas de los próximos 7 días.
                </p>
                <ul
                    v-else
                    class="grid gap-px overflow-hidden rounded-md bg-border"
                >
                    <li
                        v-for="item in list.items"
                        :key="item.id"
                        class="flex items-baseline justify-between gap-3 bg-card px-4 py-2.5 text-sm"
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
                            class="shrink-0 font-semibold tabular-nums"
                            >{{ item.quantity }}</span
                        >
                    </li>
                    <li
                        v-if="remaining > 0"
                        class="bg-card px-4 py-2.5 text-sm text-muted-foreground"
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
    </div>
</template>
