<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    BookOpen,
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
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    enough: 'secondary',
    short: 'destructive',
    missing: 'destructive',
    review: 'outline',
};
</script>

<template>
    <Head title="Menú semanal" />

    <div class="flex w-full flex-col gap-6 p-4 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="week.label"
                description="Comida y cena de lunes a domingo. Cada cambio se guarda al momento."
            />
            <nav class="flex items-center gap-2" aria-label="Cambiar de semana">
                <Button
                    variant="outline"
                    size="icon"
                    class="size-11 sm:size-9"
                    as-child
                >
                    <Link
                        :href="show(week.previous)"
                        aria-label="Semana anterior"
                    >
                        <ChevronLeft />
                    </Link>
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    class="min-h-11 sm:min-h-8"
                    as-child
                    :disabled="week.is_current"
                >
                    <Link :href="show(week.current)">Esta semana</Link>
                </Button>
                <Button
                    variant="outline"
                    size="icon"
                    class="size-11 sm:size-9"
                    as-child
                >
                    <Link :href="show(week.next)" aria-label="Semana siguiente">
                        <ChevronRight />
                    </Link>
                </Button>
            </nav>
        </div>

        <!-- RN-36: no closing step; the shopping list of the period follows the menu. -->
        <div
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card px-4 py-3"
        >
            <p class="text-sm text-muted-foreground">
                {{ filledSlots }} de {{ slotsPerWeek }} comidas y cenas con
                platos ({{ planned }} {{ planned === 1 ? 'plato' : 'platos' }}).
                Cada comida admite varios platos y cada cambio se refleja en la
                lista de la compra.
                <template v-if="days.some((day) => day.is_past)">
                    Los días pasados se conservan como historial y no generan
                    compras.
                </template>
            </p>
            <Button
                variant="outline"
                size="sm"
                class="min-h-11 sm:min-h-8"
                as-child
            >
                <Link
                    :href="
                        shoppingPeriod({
                            query: {
                                start: days[0].date,
                                end: days[days.length - 1].date,
                            },
                        })
                    "
                >
                    <ShoppingCart />
                    Lista de la compra de esta semana
                </Link>
            </Button>
        </div>

        <div
            :class="[
                'grid items-start gap-6',
                canWrite && 'lg:grid-cols-[minmax(0,1fr)_18rem]',
            ]"
        >
            <section class="grid gap-3" aria-labelledby="week-grid-title">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="week-grid-title" class="text-sm font-semibold">
                        Comidas y cenas
                    </h2>
                    <Sheet v-if="canWrite" v-model:open="bookOpen">
                        <SheetTrigger as-child>
                            <Button
                                variant="outline"
                                size="sm"
                                class="min-h-11 sm:min-h-8 lg:hidden"
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

                <div
                    class="grid gap-x-3 gap-y-2 sm:grid-cols-[7rem_minmax(0,1fr)_minmax(0,1fr)]"
                    data-testid="week-grid"
                >
                    <!-- Column headings for sighted users; each cell has its full accessible name. -->
                    <span class="hidden sm:block" aria-hidden="true"></span>
                    <span
                        v-for="meal in meals"
                        :key="meal.value"
                        class="hidden px-1 text-xs font-medium tracking-wide text-muted-foreground uppercase sm:block"
                        aria-hidden="true"
                        >{{ meal.label }}</span
                    >
                    <section
                        v-for="day in days"
                        :key="day.day"
                        :aria-labelledby="`day-${day.day}`"
                        class="grid grid-cols-2 gap-2 border-t pt-2 sm:col-span-3 sm:grid-cols-subgrid sm:border-0 sm:pt-0"
                    >
                        <h3
                            :id="`day-${day.day}`"
                            :class="[
                                'col-span-2 flex flex-wrap items-center gap-x-2 text-sm font-semibold sm:col-span-1 sm:self-center',
                                day.is_past && 'text-muted-foreground',
                            ]"
                        >
                            {{ day.label }}
                            <span
                                v-if="day.is_past"
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
                        />
                    </section>
                </div>
            </section>

            <aside
                v-if="canWrite"
                class="sticky top-4 hidden max-h-[calc(100svh-2rem)] gap-3 overflow-y-auto rounded-xl border bg-card p-4 lg:grid"
                aria-labelledby="recipe-book-title"
            >
                <h2
                    id="recipe-book-title"
                    class="flex items-center gap-2 text-sm font-semibold"
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

        <section class="mx-auto grid w-full max-w-3xl gap-3">
            <Heading
                variant="small"
                title="Ingredientes necesarios"
                description="Lo que necesitan los platos de esta semana que aún no han pasado, comparado con lo que tienes previsto en casa (frigorífico, congelador y despensa). Planificar no reserva ni descuenta nada."
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
                class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                No hay platos pendientes con ingredientes esta semana. Los que
                ya han pasado cuentan en lo que tienes previsto en casa.
            </p>

            <ul v-else class="divide-y rounded-xl border">
                <li
                    v-for="line in requirements.lines"
                    :key="line.ingredient + line.need"
                    class="grid gap-1 px-4 py-3 sm:grid-cols-[minmax(8rem,1fr)_minmax(0,2fr)] sm:items-center"
                >
                    <div class="min-w-0">
                        <p class="font-medium">{{ line.ingredient }}</p>
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
