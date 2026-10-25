<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    BookOpen,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    CircleAlert,
    ShoppingCart,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import MenuSlot from '@/components/menu/MenuSlot.vue';
import RecipeBook from '@/components/menu/RecipeBook.vue';
import { useHouseholdAccess } from '@/composables/useHouseholdAccess';
import { Badge } from '@/components/ui/badge';
import type { BadgeVariants } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import type { SlotOption } from '@/lib/menuDrag';
import { show } from '@/routes/menu';
import { period as shoppingPeriod } from '@/routes/shopping-list';
import type {
    IncompleteDish,
    MenuDay,
    Option,
    PlannedDish,
    RecipeOption,
    RequirementLine,
    UnitOption,
} from '@/types/pantry';

const props = defineProps<{
    week: {
        key: string;
        label: string;
        previous: string;
        next: string;
        current: string;
        is_current: boolean;
    };
    days: MenuDay[];
    meals: Option[];
    slots: Record<string, PlannedDish[]>;
    planned: number;
    filledSlots: number;
    slotsPerWeek: number;
    recipes: RecipeOption[];
    units: UnitOption[];
    suggestions: string[];
    requirements: { lines: RequirementLine[]; incomplete: IncompleteDish[] };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Menú', href: show() }],
    },
});

// The 14 slots of the week, as targets of «Mover a…» and «Añadir al menú».
const slotOptions = computed<SlotOption[]>(() =>
    props.days.flatMap((day) =>
        props.meals.map((meal) => ({
            key: `${day.day}-${meal.value}`,
            day: day.day,
            meal: meal.value,
            label: `${day.label} · ${meal.label}${day.is_past ? ' (pasado)' : ''}`,
            past: day.is_past === true,
            dish:
                props.slots[`${day.day}-${meal.value}`]
                    ?.map((dish) => dish.name)
                    .join(', ') || null,
        })),
    ),
);

const bookOpen = ref(false);
const { canWrite } = useHouseholdAccess();

const statusLabel: Record<RequirementLine['status'], string> = {
    enough: 'Suficiente',
    short: 'Falta',
    missing: 'Sin existencias',
    review: 'Revisar unidades',
};

const statusVariant: Record<
    RequirementLine['status'],
    NonNullable<BadgeVariants['variant']>
> = {
    enough: 'success',
    short: 'warning',
    missing: 'warning',
    review: 'info',
};

// Ingredients the pending dishes of this week still lack: the count on the list action.
const missingCount = computed(
    () =>
        props.requirements.lines.filter(
            (line) => line.status === 'short' || line.status === 'missing',
        ).length,
);

// Today in Europe/Madrid, the zone the server uses for the week (only to mark it on the board).
const todayDate = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Europe/Madrid',
}).format(new Date());

// On mobile the week is a strip of day tiles and one open day: today when it is in this week.
const selectedDay = ref(
    props.days.find((day) => day.date === todayDate)?.day ?? props.days[0].day,
);

const plannedMeals = (day: MenuDay) =>
    props.meals.filter(
        (meal) => (props.slots[`${day.day}-${meal.value}`] ?? []).length > 0,
    );

const shortDay = (day: MenuDay) => day.label.split(' ')[0].slice(0, 3);
const dayNumber = (day: MenuDay) => day.label.split(' ').at(-1);
</script>

<template>
    <Head title="Menú semanal" />

    <div class="flex w-full flex-col gap-5 p-4 sm:p-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <Heading
                :title="week.label"
                :description="`${filledSlots} de ${slotsPerWeek} comidas y cenas planificadas`"
            />
            <div class="flex flex-wrap items-center gap-2">
                <nav
                    class="flex items-center gap-1.5"
                    aria-label="Cambiar de semana"
                >
                    <Button variant="outline" size="icon" as-child>
                        <Link
                            :href="show(week.previous)"
                            aria-label="Semana anterior"
                        >
                            <ChevronLeft />
                        </Link>
                    </Button>
                    <!-- A link cannot be disabled: on the current week it is a disabled button. -->
                    <Button
                        v-if="week.is_current"
                        variant="outline"
                        disabled
                        aria-current="date"
                    >
                        Esta semana
                    </Button>
                    <Button v-else variant="outline" as-child>
                        <Link :href="show(week.current)">Esta semana</Link>
                    </Button>
                    <Button variant="outline" size="icon" as-child>
                        <Link
                            :href="show(week.next)"
                            aria-label="Semana siguiente"
                        >
                            <ChevronRight />
                        </Link>
                    </Button>
                </nav>
                <!-- RN-36: no closing step; the shopping list of the period follows the menu. -->
                <Button as-child>
                    <Link
                        :href="
                            shoppingPeriod({
                                query: {
                                    start: days[0].date,
                                    end: days[days.length - 1].date,
                                },
                            })
                        "
                        :aria-label="`Lista de la compra de esta semana${missingCount > 0 ? `: faltan ${missingCount} ingredientes para los platos de esta semana` : ''}`"
                    >
                        <ShoppingCart />
                        Lista de la compra
                        <span
                            v-if="missingCount > 0"
                            class="rounded-sm bg-primary-foreground/20 px-1.5 tabular-nums"
                            aria-hidden="true"
                            >{{ missingCount }}
                            {{ missingCount === 1 ? 'falta' : 'faltan' }}</span
                        >
                    </Link>
                </Button>
            </div>
        </div>

        <details class="group -mt-2 text-sm text-muted-foreground">
            <summary
                class="flex w-fit cursor-pointer list-none items-center gap-1 font-medium hover:text-foreground [&::-webkit-details-marker]:hidden"
            >
                ¿Cómo funciona?
                <ChevronDown
                    class="size-4 transition-transform group-open:rotate-180"
                    aria-hidden="true"
                />
            </summary>
            <p class="mt-2 max-w-prose">
                Cada comida y cena admite varios platos y cada cambio se guarda
                al momento y se refleja en la lista de la compra. Planificar no
                reserva ni descuenta nada del inventario.
                <template v-if="days.some((day) => day.is_past)">
                    Los días pasados se conservan como historial y no generan
                    compras.
                </template>
            </p>
        </details>

        <div
            :class="[
                'grid items-start gap-6',
                canWrite && 'lg:grid-cols-[minmax(0,1fr)_17rem]',
            ]"
        >
            <section class="grid gap-3" aria-labelledby="week-grid-title">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2
                        id="week-grid-title"
                        class="text-sm font-bold tracking-wide uppercase"
                    >
                        Comidas y cenas
                    </h2>
                    <Sheet v-if="canWrite" v-model:open="bookOpen">
                        <SheetTrigger as-child>
                            <Button
                                variant="outline"
                                size="sm"
                                class="lg:hidden"
                            >
                                <BookOpen />
                                Recetario
                            </Button>
                        </SheetTrigger>
                        <SheetContent
                            side="right"
                            class="w-full overflow-y-auto sm:max-w-sm"
                        >
                            <SheetHeader>
                                <SheetTitle>Recetario</SheetTitle>
                                <SheetDescription>
                                    Elige una receta y el día y la comida en los
                                    que quieres planificarla.
                                </SheetDescription>
                            </SheetHeader>
                            <RecipeBook
                                id-prefix="book-mobile"
                                class="px-4 pb-4"
                                :week="week.key"
                                :recipes="recipes"
                                :slot-options="slotOptions"
                                :editable="canWrite"
                                @planned="bookOpen = false"
                            />
                        </SheetContent>
                    </Sheet>
                </div>

                <!-- Mobile: a strip of day tiles; the chosen day opens below it. -->
                <nav
                    class="grid grid-cols-7 gap-0.5 rounded-md bg-border p-0.5 sm:hidden"
                    aria-label="Elegir día"
                >
                    <button
                        v-for="day in days"
                        :key="day.day"
                        type="button"
                        :aria-pressed="selectedDay === day.day"
                        :aria-controls="`day-${day.day}-panel`"
                        :aria-label="`${day.label}${day.date === todayDate ? ', hoy' : ''}: ${plannedMeals(day).length} de ${meals.length} comidas planificadas`"
                        :class="[
                            'grid min-h-16 min-w-0 content-center justify-items-center gap-0.5 rounded-sm px-0.5 py-1.5 transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            selectedDay === day.day
                                ? 'bg-foreground text-background'
                                : 'bg-card text-foreground',
                            day.date === todayDate &&
                                selectedDay !== day.day &&
                                'ring-2 ring-foreground ring-inset',
                            day.is_past &&
                                selectedDay !== day.day &&
                                'text-muted-foreground',
                        ]"
                        @click="selectedDay = day.day"
                    >
                        <span
                            class="text-[11px] leading-none font-semibold uppercase"
                            >{{ shortDay(day) }}</span
                        >
                        <span class="text-lg leading-none font-display">{{
                            dayNumber(day)
                        }}</span>
                        <span class="flex gap-0.5" aria-hidden="true">
                            <span
                                v-for="meal in meals"
                                :key="meal.value"
                                :class="[
                                    'size-1.5 rounded-full',
                                    plannedMeals(day).includes(meal)
                                        ? meal.value === 'lunch'
                                            ? 'bg-pigment-ocre'
                                            : 'bg-pigment-cobalto'
                                        : 'ring-1 ring-current/40 ring-inset',
                                ]"
                            />
                        </span>
                    </button>
                </nav>

                <!-- The week as a floor: tiles separated by joints (the board's ground). -->
                <div
                    class="grid gap-1.5 rounded-md bg-border p-1.5 sm:grid-cols-[6.5rem_minmax(0,1fr)_minmax(0,1fr)]"
                    data-testid="week-grid"
                >
                    <!-- Column headings for sighted users; each cell has its full accessible name. -->
                    <span class="hidden sm:block" aria-hidden="true"></span>
                    <span
                        v-for="meal in meals"
                        :key="meal.value"
                        class="hidden items-center gap-1.5 px-1 text-xs font-bold tracking-wide text-foreground uppercase sm:flex"
                        aria-hidden="true"
                        ><span
                            :class="[
                                'size-2.5 rounded-full',
                                meal.value === 'lunch'
                                    ? 'bg-pigment-ocre'
                                    : 'bg-pigment-cobalto',
                            ]"
                        />{{ meal.label }}</span
                    >
                    <section
                        v-for="day in days"
                        :id="`day-${day.day}-panel`"
                        :key="day.day"
                        :aria-labelledby="`day-${day.day}`"
                        :class="[
                            'grid grid-cols-1 gap-1.5 sm:col-span-3 sm:grid-cols-subgrid',
                            selectedDay !== day.day && 'max-sm:hidden',
                        ]"
                    >
                        <h3
                            :id="`day-${day.day}`"
                            :class="[
                                'flex items-baseline gap-2 rounded-sm px-3 py-2 sm:flex-col sm:justify-center sm:gap-0.5',
                                day.date === todayDate
                                    ? 'bg-foreground text-background'
                                    : 'bg-card',
                                day.is_past &&
                                    day.date !== todayDate &&
                                    'text-muted-foreground',
                            ]"
                        >
                            <span class="sr-only">{{ day.label }}</span>
                            <span
                                class="text-xs font-semibold uppercase"
                                aria-hidden="true"
                                >{{ day.label.split(' ')[0] }}</span
                            >
                            <span
                                class="text-2xl leading-none font-display"
                                aria-hidden="true"
                                >{{ dayNumber(day) }}</span
                            >
                            <span
                                v-if="day.date === todayDate"
                                class="text-xs font-semibold"
                                >Hoy</span
                            >
                            <span
                                v-else-if="day.is_past"
                                class="text-xs font-normal"
                                title="Se conserva como historial; no genera compras"
                                >Pasado</span
                            >
                        </h3>
                        <MenuSlot
                            v-for="meal in meals"
                            :key="`${day.day}-${meal.value}`"
                            :week="week.key"
                            :day="day.day"
                            :day-label="day.label"
                            :meal="meal.value"
                            :meal-label="meal.label"
                            :dishes="slots[`${day.day}-${meal.value}`] ?? []"
                            :recipes="recipes"
                            :units="units"
                            :suggestions="suggestions"
                            :editable="canWrite"
                            :slot-options="slotOptions"
                            :is-past="day.is_past"
                            :is-today="day.date === todayDate"
                        />
                    </section>
                </div>
            </section>

            <aside
                v-if="canWrite"
                class="sticky top-4 hidden max-h-[calc(100svh-2rem)] content-start gap-3 overflow-y-auto lg:grid"
                aria-labelledby="recipe-book-title"
            >
                <h2
                    id="recipe-book-title"
                    class="flex items-center gap-2 text-sm font-bold tracking-wide uppercase"
                >
                    <BookOpen class="size-4" aria-hidden="true" />
                    Recetario
                </h2>
                <RecipeBook
                    id-prefix="book"
                    :week="week.key"
                    :recipes="recipes"
                    :slot-options="slotOptions"
                    :editable="canWrite"
                />
            </aside>
        </div>

        <section class="grid w-full max-w-4xl gap-3">
            <Heading
                variant="small"
                title="Ingredientes necesarios"
                description="Lo que piden los platos pendientes de esta semana, frente a lo que tienes previsto en casa."
            />

            <div
                v-if="requirements.incomplete.length > 0"
                class="grid gap-1 rounded-md border border-warning-border bg-warning-soft p-3 text-sm"
            >
                <p class="flex items-center gap-1 font-medium">
                    <CircleAlert class="size-4" />
                    Platos sin ingredientes (no cuentan en el cálculo)
                </p>
                <ul class="list-disc pl-5">
                    <li
                        v-for="item in requirements.incomplete"
                        :key="item.slot"
                    >
                        {{ item.slot }}: {{ item.dish }}. {{ item.reason }}
                    </li>
                </ul>
            </div>

            <p
                v-if="requirements.lines.length === 0"
                class="rounded-md bg-muted p-6 text-center text-sm text-muted-foreground"
            >
                No hay platos pendientes con ingredientes esta semana. Los que
                ya han pasado cuentan en lo que tienes previsto en casa.
            </p>

            <ul v-else class="divide-y border-y">
                <li
                    v-for="line in requirements.lines"
                    :key="line.ingredient + line.need"
                    class="grid gap-1 py-3 sm:grid-cols-[minmax(8rem,1fr)_minmax(0,2fr)] sm:items-center"
                >
                    <div class="min-w-0">
                        <p class="font-semibold">{{ line.ingredient }}</p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ line.dishes.join(', ') }}
                        </p>
                    </div>
                    <div
                        class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm tabular-nums sm:justify-end"
                    >
                        <span
                            >Necesitas <strong>{{ line.need }}</strong></span
                        >
                        <span
                            v-if="line.registered"
                            class="text-muted-foreground"
                            >Tienes ≈ {{ line.have ?? 'nada' }} (previsto;
                            registrado {{ line.registered }})</span
                        >
                        <span v-else class="text-muted-foreground"
                            >Tienes {{ line.have ?? '—' }}</span
                        >
                        <span
                            v-if="
                                line.status === 'short' ||
                                line.status === 'missing'
                            "
                        >
                            Faltan <strong>{{ line.missing }}</strong>
                        </span>
                        <Badge :variant="statusVariant[line.status]">{{
                            statusLabel[line.status]
                        }}</Badge>
                    </div>
                    <p
                        v-if="line.status === 'review'"
                        class="text-xs text-muted-foreground sm:col-span-2"
                    >
                        Tienes este ingrediente en otra unidad que no se puede
                        convertir; revisa si te basta.
                    </p>
                </li>
            </ul>
        </section>
    </div>
</template>
