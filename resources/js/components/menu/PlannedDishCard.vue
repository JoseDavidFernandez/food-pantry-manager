<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowLeftRight,
    ArrowUp,
    CircleAlert,
    CircleSlash,
    Ellipsis,
    GripVertical,
    Pencil,
    RotateCcw,
    Trash2,
} from '@lucide/vue';
import { ref } from 'vue';
import DishAlternatives from '@/components/menu/DishAlternatives.vue';
import DishDialog from '@/components/menu/DishDialog.vue';
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
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { DISH_MIME } from '@/lib/menuDrag';
import type { SlotOption } from '@/lib/menuDrag';
import { cn } from '@/lib/utils';
import { destroy, eaten, move, position } from '@/routes/menu/dishes';
import type { PlannedDish, RecipeOption, UnitOption } from '@/types/pantry';

/**
 * One dish of a lunch or dinner (RN-16), as a row of its tile: change, move to another slot,
 * reorder within its slot, «No comido» (RN-37) and remove, all from one actions menu with text
 * labels. Dragging it onto another slot moves it there (pointer devices).
 */
const props = defineProps<{
    dish: PlannedDish;
    week: string;
    day: number;
    meal: string;
    slotTitle: string;
    slotKey: string;
    index: number;
    count: number;
    slotOptions: SlotOption[];
    recipes: RecipeOption[];
    units: UnitOption[];
    suggestions: string[];
    editable: boolean;
}>();

const changeOpen = ref(false);
const moveOpen = ref(false);
const removeOpen = ref(false);
const moveTarget = ref('');
const busy = ref(false);

const visit = {
    preserveScroll: true,
    onStart: () => (busy.value = true),
    onFinish: () => (busy.value = false),
};

function onDragStart(event: DragEvent): void {
    event.dataTransfer?.setData(DISH_MIME, String(props.dish.id));

    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
    }
}

function setEaten(value: boolean): void {
    router.patch(eaten.url(props.dish.id), { eaten: value ? 1 : 0 }, visit);
}

function reorder(direction: 'up' | 'down'): void {
    router.patch(position.url(props.dish.id), { direction }, visit);
}

function moveTo(): void {
    const option = props.slotOptions.find((o) => o.key === moveTarget.value);

    if (option !== undefined) {
        router.patch(
            move.url(props.dish.id),
            { to_day: option.day, to_meal: option.meal },
            { ...visit, onSuccess: () => (moveOpen.value = false) },
        );
    }
}

// A dialog opened from a menu item waits for the menu to close and hand focus back first.
const dialogs = { change: changeOpen, move: moveOpen, remove: removeOpen };

function openAfterMenu(dialog: keyof typeof dialogs): void {
    if (dialog === 'move') {
        moveTarget.value = '';
    }

    window.setTimeout(() => (dialogs[dialog].value = true));
}

const label = `${props.dish.name}, ${props.dish.servings} ${props.dish.servings === 1 ? 'ración' : 'raciones'}${props.dish.not_eaten ? ', no comido' : ''}`;
</script>

<template>
    <li
        :draggable="editable"
        :data-dish-id="dish.id"
        :aria-label="label"
        :class="
            cn(
                'grid min-w-0 gap-1 py-2 first:pt-0 last:pb-0',
                editable && 'cursor-grab active:cursor-grabbing',
            )
        "
        @dragstart="onDragStart"
    >
        <div class="flex items-start gap-1">
            <!-- The handle lets the pointer through: a drag starting on an SVG never starts. -->
            <GripVertical
                v-if="editable"
                class="pointer-events-none mt-0.5 size-3.5 shrink-0 text-foreground/60 pointer-coarse:hidden"
                aria-hidden="true"
            />
            <p
                :class="
                    cn(
                        'min-w-0 flex-1 leading-snug font-semibold break-words',
                        dish.not_eaten && 'text-foreground/70 line-through',
                    )
                "
            >
                {{ dish.name }}
            </p>

            <DropdownMenu v-if="editable" :modal="false">
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        class="-my-1 -mr-1 shrink-0 hover:bg-foreground/10"
                        :disabled="busy"
                        :aria-label="`Acciones de ${dish.name}: ${slotTitle}`"
                    >
                        <Ellipsis />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="min-w-48">
                    <DropdownMenuItem
                        :aria-label="`Cambiar ${dish.name}: ${slotTitle}`"
                        @select="openAfterMenu('change')"
                    >
                        <Pencil />
                        Cambiar
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        :aria-label="`Mover ${dish.name}: ${slotTitle}`"
                        @select="openAfterMenu('move')"
                    >
                        <ArrowLeftRight />
                        Mover
                    </DropdownMenuItem>
                    <template v-if="count > 1">
                        <DropdownMenuItem
                            :disabled="index === 0"
                            :aria-label="`Subir ${dish.name}`"
                            @select="reorder('up')"
                        >
                            <ArrowUp />
                            Subir
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            :disabled="index === count - 1"
                            :aria-label="`Bajar ${dish.name}`"
                            @select="reorder('down')"
                        >
                            <ArrowDown />
                            Bajar
                        </DropdownMenuItem>
                    </template>
                    <DropdownMenuItem
                        :aria-label="`${dish.not_eaten ? 'Volver a pendiente' : 'Marcar como no comido'}: ${dish.name}`"
                        @select="setEaten(dish.not_eaten)"
                    >
                        <component
                            :is="dish.not_eaten ? RotateCcw : CircleSlash"
                        />
                        {{
                            dish.not_eaten
                                ? 'Volver a pendiente'
                                : 'Marcar como no comido'
                        }}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        :aria-label="`Retirar ${dish.name}: ${slotTitle}`"
                        @select="openAfterMenu('remove')"
                    >
                        <Trash2 />
                        Retirar
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
        <p class="flex flex-wrap items-center gap-1 text-xs text-foreground/75">
            {{ dish.servings }}
            {{ dish.servings === 1 ? 'ración' : 'raciones' }} ·
            {{ dish.type === 'recipe' ? 'Receta' : 'Plato sencillo' }}
            <Badge v-if="dish.recipe_archived" variant="secondary"
                >Archivada</Badge
            >
        </p>
        <p
            v-if="!dish.complete"
            class="flex items-center gap-1 text-xs text-warning"
        >
            <CircleAlert class="size-3.5" aria-hidden="true" />
            Sin ingredientes
        </p>
        <DishAlternatives
            v-for="alternative in dish.alternatives ?? []"
            :key="`${alternative.recipe_id}-${alternative.group}`"
            :dish-id="dish.id"
            :dish-name="dish.name"
            :alternative="alternative"
            :editable="editable"
        />
        <Badge v-if="dish.not_eaten" variant="warning" class="self-start">
            <CircleSlash aria-hidden="true" />
            No comido
        </Badge>

        <template v-if="editable">
            <DishDialog
                v-model:open="changeOpen"
                :week="week"
                :day="day"
                :meal="meal"
                :title="`Cambiar «${dish.name}» · ${slotTitle}`"
                :dish="dish"
                :recipes="recipes"
                :units="units"
                :suggestions="suggestions"
                :id-prefix="`dish-${dish.id}`"
            />

            <Dialog v-model:open="moveOpen">
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Mover «{{ dish.name }}»</DialogTitle>
                        <DialogDescription>
                            Ahora está en {{ slotTitle.toLowerCase() }}. Se
                            añadirá al final de la comida que elijas; no se
                            sustituye nada.
                        </DialogDescription>
                    </DialogHeader>
                    <form class="grid gap-4" @submit.prevent="moveTo">
                        <div class="grid gap-2">
                            <Label :for="`dish-${dish.id}-move`">Mover a</Label>
                            <select
                                :id="`dish-${dish.id}-move`"
                                v-model="moveTarget"
                                required
                                class="h-10 w-full rounded-md border border-input bg-card px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <option value="" disabled>
                                    Elige día y comida
                                </option>
                                <option
                                    v-for="option in slotOptions.filter(
                                        (o) => o.key !== slotKey,
                                    )"
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
                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button type="button" variant="outline"
                                    >Cancelar</Button
                                >
                            </DialogClose>
                            <Button
                                type="submit"
                                :disabled="!moveTarget || busy"
                            >
                                Mover
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog v-model:open="removeOpen">
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>¿Retirar «{{ dish.name }}»?</DialogTitle>
                        <DialogDescription>
                            Se quita de {{ slotTitle.toLowerCase() }}; los demás
                            platos se quedan. La receta sigue en tu recetario.
                        </DialogDescription>
                    </DialogHeader>
                    <Form
                        v-bind="destroy.form(dish.id)"
                        :options="{ preserveScroll: true }"
                        v-slot="{ processing }"
                        @success="removeOpen = false"
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
                                :disabled="processing"
                            >
                                <Spinner v-if="processing" />
                                Retirar plato
                            </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </template>
    </li>
</template>
