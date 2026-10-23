<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Shuffle } from '@lucide/vue';
import { computed, ref } from 'vue';
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
import { cn } from '@/lib/utils';
import { choice } from '@/routes/menu/dishes';
import type { DishAlternative } from '@/types/pantry';

/**
 * A group of alternative ingredients of a planned dish (RN-42): the option in use, marked as a
 * proposal until the user confirms or changes it. The choice is kept for this dish.
 */
const props = defineProps<{
    dishId: number;
    dishName: string;
    alternative: DishAlternative;
    editable: boolean;
}>();

const open = ref(false);
const selected = ref(String(props.alternative.chosen));
const busy = ref(false);

const chosen = computed(() =>
    props.alternative.options.find(
        (option) => option.ingredient_id === props.alternative.chosen,
    ),
);
const names = computed(() =>
    props.alternative.options.map((option) => option.name).join(' o '),
);

function save(): void {
    router.patch(
        choice.url(props.dishId),
        {
            recipe_id: props.alternative.recipe_id,
            group: props.alternative.group,
            ingredient_id: Number(selected.value),
        },
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
            onSuccess: () => (open.value = false),
        },
    );
}
</script>

<template>
    <div
        :class="
            cn(
                'flex flex-wrap items-center gap-1 text-xs',
                alternative.confirmed
                    ? 'text-muted-foreground'
                    : 'text-warning',
            )
        "
        data-alternative
    >
        <Shuffle class="size-3.5 shrink-0" aria-hidden="true" />
        <span v-if="alternative.confirmed">Con {{ chosen?.name }}</span>
        <span v-else-if="alternative.stale"
            >Elegiste {{ alternative.stale }}, que ya no está en la receta.
            Propuesta: {{ chosen?.name }}</span
        >
        <span v-else
            >Propuesta: {{ chosen?.name
            }}{{ chosen?.in_stock ? ' (la tienes)' : '' }}</span
        >

        <Dialog v-if="editable" v-model:open="open">
            <DialogTrigger as-child>
                <Button
                    variant="link"
                    size="sm"
                    class="relative h-auto p-0 text-xs after:absolute after:-inset-x-2 after:-inset-y-3.5 after:content-[''] sm:after:-inset-y-1"
                    :aria-label="`${alternative.confirmed ? 'Cambiar' : 'Elegir'} entre ${names}: ${dishName}`"
                    @click="selected = String(alternative.chosen)"
                >
                    {{ alternative.confirmed ? 'Cambiar' : 'Elegir' }}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle
                        >¿Con qué preparas «{{ dishName }}»?</DialogTitle
                    >
                    <DialogDescription>
                        {{
                            alternative.recipe === dishName
                                ? 'La receta'
                                : `La preparación «${alternative.recipe}»`
                        }}
                        admite {{ names }}; se usa solo una. Tu elección se
                        guarda para este plato y la lista de la compra la sigue.
                    </DialogDescription>
                </DialogHeader>
                <form class="grid gap-4" @submit.prevent="save">
                    <fieldset class="grid gap-2">
                        <legend class="sr-only">Opciones</legend>
                        <label
                            v-for="option in alternative.options"
                            :key="option.ingredient_id"
                            class="flex min-h-11 cursor-pointer items-center gap-3 rounded-md border p-3 has-checked:border-primary has-checked:bg-accent"
                        >
                            <input
                                v-model="selected"
                                type="radio"
                                name="ingredient_id"
                                :value="String(option.ingredient_id)"
                                class="size-4 accent-primary"
                            />
                            <span class="flex-1">
                                {{ option.name }}
                                <span
                                    class="block text-xs text-muted-foreground tabular-nums"
                                    >{{ option.quantity }}</span
                                >
                            </span>
                            <Badge v-if="option.in_stock" variant="secondary"
                                >En casa</Badge
                            >
                        </label>
                    </fieldset>
                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="outline"
                                >Cancelar</Button
                            >
                        </DialogClose>
                        <Button type="submit" :disabled="busy">
                            {{
                                !alternative.confirmed &&
                                selected === String(alternative.chosen)
                                    ? 'Confirmar'
                                    : 'Usar esta'
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
