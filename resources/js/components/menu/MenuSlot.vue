<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import DishDialog from '@/components/menu/DishDialog.vue';
import PlannedDishCard from '@/components/menu/PlannedDishCard.vue';
import { Button } from '@/components/ui/button';
import { DISH_MIME, RECIPE_MIME } from '@/lib/menuDrag';
import type { SlotOption } from '@/lib/menuDrag';
import { cn } from '@/lib/utils';
import { move, store } from '@/routes/menu/dishes';
import type { PlannedDish, RecipeOption, UnitOption } from '@/types/pantry';

/**
 * A lunch or dinner of the week (RN-15, RN-16) as a floor tile: tinted with its meal's pigment
 * (lunch ochre, dinner cobalt) when it has dishes, plain cement when empty. A recipe dropped here
 * is added with its base servings; a dish dropped here moves to the end. Nothing is ever replaced.
 */
const props = defineProps<{
    week: string;
    day: number;
    dayLabel: string;
    meal: string;
    mealLabel: string;
    dishes: PlannedDish[];
    recipes: RecipeOption[];
    units: UnitOption[];
    suggestions: string[];
    editable: boolean;
    slotOptions: SlotOption[];
    isPast?: boolean;
    isToday?: boolean;
}>();

const lunch = computed(() => props.meal === 'lunch');

const slotTitle = computed(() => `${props.dayLabel} · ${props.mealLabel}`);
const slotKey = computed(() => `${props.day}-${props.meal}`);
const accessibleName = computed(
    () =>
        `${props.dayLabel}, ${props.mealLabel.toLowerCase()}: ${
            props.dishes.length === 0
                ? 'vacío'
                : props.dishes.map((dish) => dish.name).join(', ')
        }`,
);

const dropActive = ref(false);

function accepts(event: DragEvent): boolean {
    const types = event.dataTransfer?.types ?? [];

    return (
        props.editable &&
        (types.includes(RECIPE_MIME) || types.includes(DISH_MIME))
    );
}

function onDragOver(event: DragEvent): void {
    if (accepts(event)) {
        event.preventDefault();
        dropActive.value = true;
    }
}

function onDrop(event: DragEvent): void {
    dropActive.value = false;

    if (!accepts(event)) {
        return;
    }

    event.preventDefault();
    const dishId = Number(event.dataTransfer?.getData(DISH_MIME));

    if (dishId > 0) {
        if (!props.dishes.some((dish) => dish.id === dishId)) {
            router.patch(
                move.url(dishId),
                { to_day: props.day, to_meal: props.meal },
                { preserveScroll: true },
            );
        }

        return;
    }

    const recipe = props.recipes.find(
        (candidate) =>
            String(candidate.id) === event.dataTransfer?.getData(RECIPE_MIME),
    );

    if (recipe !== undefined) {
        router.post(
            store.url({ week: props.week, day: props.day, meal: props.meal }),
            {
                type: 'recipe',
                recipe_id: recipe.id,
                servings: recipe.base_servings,
            },
            { preserveScroll: true },
        );
    }
}
</script>

<template>
    <div
        role="group"
        :aria-label="accessibleName"
        :data-slot-key="slotKey"
        :class="
            cn(
                'relative grid min-h-24 min-w-0 content-start gap-1 overflow-hidden rounded-sm p-2.5 text-sm transition-colors sm:p-3',
                dishes.length === 0
                    ? 'bg-muted'
                    : lunch
                      ? 'bg-tile-lunch'
                      : 'bg-tile-dinner',
                isPast && dishes.length > 0 && 'saturate-[.35]',
                isToday && 'ring-2 ring-foreground ring-inset',
                dropActive && 'bg-accent ring-2 ring-primary ring-inset',
            )
        "
        @dragover="onDragOver"
        @dragleave="dropActive = false"
        @drop="onDrop"
    >
        <!-- The tile's corner: a quarter disc of the meal's pigment, as on a hydraulic tile. -->
        <span
            v-if="dishes.length > 0"
            aria-hidden="true"
            :class="
                cn(
                    'pointer-events-none absolute right-0 bottom-0 size-4 rounded-tl-full',
                    lunch ? 'bg-pigment-ocre' : 'bg-pigment-cobalto',
                )
            "
        />
        <span
            :class="
                cn(
                    'text-xs font-semibold tracking-wide uppercase sm:sr-only',
                    dishes.length > 0
                        ? 'text-foreground/75'
                        : 'text-muted-foreground',
                )
            "
        >
            {{ mealLabel }}
        </span>

        <ul v-if="dishes.length > 0" class="grid divide-y divide-foreground/10">
            <PlannedDishCard
                v-for="(dish, index) in dishes"
                :key="dish.id"
                :dish="dish"
                :week="week"
                :day="day"
                :meal="meal"
                :slot-title="slotTitle"
                :slot-key="slotKey"
                :index="index"
                :count="dishes.length"
                :slot-options="slotOptions"
                :recipes="recipes"
                :units="units"
                :suggestions="suggestions"
                :editable="editable"
            />
        </ul>
        <p v-else-if="!editable" class="text-muted-foreground">Vacío</p>

        <DishDialog
            v-if="editable"
            :week="week"
            :day="day"
            :meal="meal"
            :title="`Añadir plato · ${slotTitle}`"
            :dish="null"
            :recipes="recipes"
            :units="units"
            :suggestions="suggestions"
            :id-prefix="`slot-${day}-${meal}`"
        >
            <template #trigger>
                <Button
                    variant="ghost"
                    size="sm"
                    :class="
                        cn(
                            'mt-auto justify-start gap-1.5 px-2 hover:bg-foreground/10 hover:text-foreground',
                            dishes.length > 0
                                ? 'text-foreground/75'
                                : 'text-muted-foreground',
                            dishes.length === 0 &&
                                'min-h-11 justify-center border border-dashed border-foreground/25 sm:min-h-14',
                        )
                    "
                    :aria-label="`Añadir plato: ${slotTitle}`"
                >
                    <Plus />
                    Añadir
                </Button>
            </template>
        </DishDialog>
    </div>
</template>
