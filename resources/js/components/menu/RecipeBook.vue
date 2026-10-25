<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { CalendarPlus, CircleAlert, GripVertical, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RECIPE_MIME } from '@/lib/menuDrag';
import type { SlotOption } from '@/lib/menuDrag';
import { create as createRecipe } from '@/routes/recipes';
import { store } from '@/routes/menu/dishes';
import type { RecipeOption } from '@/types/pantry';

/**
 * Recipe book next to the calendar: search, drag a recipe onto a slot, or use «Añadir al
 * menú» (keyboard and touch) to choose day, meal and servings.
 */
const props = defineProps<{
    idPrefix: string;
    week: string;
    recipes: RecipeOption[];
    slotOptions: SlotOption[];
    editable: boolean;
}>();

const emit = defineEmits<{ planned: [] }>();

const search = ref('');

const normalize = (text: string): string =>
    text
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase()
        .trim();

const visible = computed(() => {
    const term = normalize(search.value);

    return props.recipes.filter(
        (recipe) => term === '' || normalize(recipe.name).includes(term),
    );
});

function onDragStart(event: DragEvent, recipe: RecipeOption): void {
    event.dataTransfer?.setData(RECIPE_MIME, String(recipe.id));

    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'copy';
    }
}

// «Añadir al menú»: the first empty slot from today on is suggested; a slot with dishes
// gets one more (RN-16).
const planning = ref<RecipeOption | null>(null);
const planOpen = ref(false);
const target = ref('');
const servings = ref(1);
const processing = ref(false);
const targetDish = computed(
    () =>
        props.slotOptions.find((option) => option.key === target.value)?.dish ??
        null,
);

function startPlanning(recipe: RecipeOption): void {
    planning.value = recipe;
    servings.value = recipe.base_servings;
    target.value =
        props.slotOptions.find((option) => option.dish === null && !option.past)
            ?.key ?? '';
    planOpen.value = true;
}

function plan(): void {
    const option = props.slotOptions.find((o) => o.key === target.value);

    if (planning.value === null || option === undefined) {
        return;
    }

    router.post(
        store.url({ week: props.week, day: option.day, meal: option.meal }),
        {
            type: 'recipe',
            recipe_id: planning.value.id,
            servings: servings.value,
        },
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
            onSuccess: () => {
                planOpen.value = false;
                emit('planned');
            },
        },
    );
}
</script>

<template>
    <div class="grid content-start gap-3">
        <div class="relative">
            <Label :for="`${idPrefix}-search`" class="sr-only"
                >Buscar en el recetario</Label
            >
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <Input
                :id="`${idPrefix}-search`"
                v-model="search"
                type="search"
                placeholder="Buscar receta"
                autocomplete="off"
                class="h-10 pl-9"
            />
        </div>

        <p
            v-if="editable && recipes.length > 0"
            class="hidden text-xs text-muted-foreground lg:block"
        >
            Arrastra una receta a una comida o cena, o usa «Añadir al menú».
        </p>

        <p
            v-if="recipes.length === 0"
            class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
        >
            Aún no tienes recetas activas.
            <Link :href="createRecipe()" class="text-primary underline"
                >Crea la primera</Link
            >
            o planifica un plato sencillo desde una celda.
        </p>
        <p
            v-else-if="visible.length === 0"
            class="p-2 text-sm text-muted-foreground"
        >
            Ninguna receta coincide con «{{ search }}».
        </p>

        <ul v-else class="grid divide-y border-y" aria-label="Recetas">
            <li
                v-for="recipe in visible"
                :key="recipe.id"
                :draggable="editable"
                :class="[
                    'flex items-center gap-2 py-2 pl-1 text-sm pointer-coarse:pl-0',
                    editable && 'cursor-grab active:cursor-grabbing',
                ]"
                @dragstart="onDragStart($event, recipe)"
            >
                <GripVertical
                    v-if="editable"
                    class="size-4 shrink-0 text-muted-foreground pointer-coarse:hidden"
                    aria-hidden="true"
                />
                <span class="grid min-w-0 flex-1">
                    <span class="font-medium break-words">{{
                        recipe.name
                    }}</span>
                    <span
                        class="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground"
                    >
                        {{ recipe.base_servings }}
                        {{ recipe.base_servings === 1 ? 'ración' : 'raciones' }}
                        <span
                            v-if="!recipe.complete"
                            class="flex items-center gap-1 text-warning"
                        >
                            <CircleAlert class="size-3" aria-hidden="true" />
                            Sin ingredientes
                        </span>
                    </span>
                </span>
                <Button
                    v-if="editable"
                    variant="ghost"
                    size="icon"
                    class="size-11 shrink-0 sm:size-9"
                    :aria-label="`Añadir ${recipe.name} al menú`"
                    @click="startPlanning(recipe)"
                >
                    <CalendarPlus />
                </Button>
            </li>
        </ul>

        <Dialog v-model:open="planOpen">
            <DialogContent v-if="planning">
                <DialogHeader>
                    <DialogTitle
                        >Añadir «{{ planning.name }}» al menú</DialogTitle
                    >
                    <DialogDescription>
                        Planificar no reserva ni descuenta nada del inventario.
                    </DialogDescription>
                </DialogHeader>
                <form class="grid gap-4" @submit.prevent="plan">
                    <div class="grid gap-4 sm:grid-cols-[1fr_7rem]">
                        <div class="grid gap-2">
                            <Label :for="`${idPrefix}-target`"
                                >Día y comida</Label
                            >
                            <select
                                :id="`${idPrefix}-target`"
                                v-model="target"
                                required
                                class="h-10 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <option value="" disabled>
                                    Elige día y comida
                                </option>
                                <option
                                    v-for="option in slotOptions"
                                    :key="option.key"
                                    :value="option.key"
                                >
                                    {{ option.label
                                    }}{{
                                        option.dish ? ` (${option.dish})` : ''
                                    }}
                                </option>
                            </select>
                        </div>
                        <div class="grid gap-2">
                            <Label :for="`${idPrefix}-servings`"
                                >Raciones</Label
                            >
                            <Input
                                :id="`${idPrefix}-servings`"
                                v-model.number="servings"
                                type="number"
                                min="1"
                                max="100"
                                required
                            />
                        </div>
                    </div>
                    <p
                        v-if="targetDish"
                        class="rounded-md border p-2 text-sm text-muted-foreground"
                        aria-live="polite"
                    >
                        Se añadirá junto a «{{ targetDish }}»: cada comida
                        admite varios platos.
                    </p>
                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="outline"
                                >Cancelar</Button
                            >
                        </DialogClose>
                        <Button type="submit" :disabled="!target || processing">
                            Añadir al menú
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
