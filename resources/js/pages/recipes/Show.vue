<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import {
    Archive,
    ArchiveRestore,
    ChevronRight,
    Minus,
    Pencil,
    Plus,
    Trash2,
} from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
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
import { Spinner } from '@/components/ui/spinner';
import { useHouseholdAccess } from '@/composables/useHouseholdAccess';
import { archive, destroy, edit, index, restore, show } from '@/routes/recipes';

const props = defineProps<{
    recipe: {
        id: number;
        name: string;
        base_servings: number;
        instructions: string | null;
        archived: boolean;
        referenced: boolean;
    };
    servings: number;
    ingredients: {
        ingredient: string;
        quantity: string;
        note: string | null;
        /** RN-42: lines of the same group are «one of them». */
        alternative_group: number | null;
    }[];
    /** RN-43: preparations included, with the servings used for these servings. */
    components: {
        id: number;
        name: string;
        servings: string;
        archived: boolean;
    }[];
    totals: { ingredient: string; quantity: string }[];
    usedIn: { id: number; name: string }[];
}>();

// «O bien» before a line that is an alternative to the one above it.
const isAlternative = (index: number): boolean =>
    index > 0 &&
    props.ingredients[index].alternative_group !== null &&
    props.ingredients[index].alternative_group ===
        props.ingredients[index - 1].alternative_group;

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Recetas', href: index() }],
    },
});

const deleteOpen = ref(false);
const { canWrite } = useHouseholdAccess();

const servingsHref = (servings: number) =>
    show(props.recipe.id, { query: { servings } });
</script>

<template>
    <Head :title="recipe.name" />

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="grid gap-1">
                <Heading
                    :title="recipe.name"
                    :description="`Receta para ${recipe.base_servings} ${recipe.base_servings === 1 ? 'ración' : 'raciones'}.`"
                />
                <Badge
                    v-if="recipe.archived"
                    variant="secondary"
                    class="-mt-6 justify-self-start"
                >
                    Archivada: no se ofrece al planificar
                </Badge>
            </div>
            <div v-if="canWrite" class="flex flex-wrap gap-2">
                <Button variant="outline" as-child>
                    <Link :href="edit(recipe.id)">
                        <Pencil />
                        Editar
                    </Link>
                </Button>
                <Form
                    v-if="!recipe.archived"
                    v-bind="archive.form(recipe.id)"
                    v-slot="{ processing }"
                >
                    <Button
                        type="submit"
                        variant="outline"
                        :disabled="processing"
                    >
                        <Archive />
                        Archivar
                    </Button>
                </Form>
                <Form
                    v-else
                    v-bind="restore.form(recipe.id)"
                    v-slot="{ processing }"
                >
                    <Button
                        type="submit"
                        variant="outline"
                        :disabled="processing"
                    >
                        <ArchiveRestore />
                        Restaurar
                    </Button>
                </Form>
                <Dialog v-model:open="deleteOpen">
                    <DialogTrigger as-child>
                        <Button
                            variant="outline"
                            :aria-label="`Eliminar ${recipe.name}`"
                        >
                            <Trash2 />
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle
                                >¿Eliminar «{{ recipe.name }}»?</DialogTitle
                            >
                            <DialogDescription v-if="recipe.referenced">
                                Esta receta está en algún menú o es una
                                preparación de otra receta, así que no se puede
                                eliminar. Archívala para que deje de ofrecerse
                                al planificar sin perder lo que la usa.
                            </DialogDescription>
                            <DialogDescription v-else>
                                Se eliminará la receta y sus ingredientes. Los
                                ingredientes seguirán en tu catálogo.
                            </DialogDescription>
                        </DialogHeader>
                        <Form
                            v-bind="destroy.form(recipe.id)"
                            v-slot="{ processing }"
                            @success="deleteOpen = false"
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
                                    :disabled="processing || recipe.referenced"
                                >
                                    <Spinner v-if="processing" />
                                    Eliminar receta
                                </Button>
                            </DialogFooter>
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </div>

        <!-- R5: what to buy and cook with on the left; how to cook it on the right. -->
        <div
            class="grid items-start gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]"
        >
            <div class="grid gap-6">
                <section class="grid gap-3">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <h2 class="text-sm font-bold tracking-wide uppercase">
                            Ingredientes
                        </h2>
                        <div
                            class="flex items-center gap-2 text-sm"
                            aria-label="Raciones"
                        >
                            <span class="text-muted-foreground">Ver para</span>
                            <Button
                                variant="outline"
                                size="icon-sm"
                                as-child
                                :disabled="servings <= 1"
                            >
                                <Link
                                    :href="
                                        servingsHref(Math.max(1, servings - 1))
                                    "
                                    preserve-scroll
                                    aria-label="Una ración menos"
                                >
                                    <Minus />
                                </Link>
                            </Button>
                            <span
                                class="w-20 text-center font-semibold tabular-nums"
                            >
                                {{ servings }}
                                {{ servings === 1 ? 'ración' : 'raciones' }}
                            </span>
                            <Button variant="outline" size="icon-sm" as-child>
                                <Link
                                    :href="
                                        servingsHref(
                                            Math.min(100, servings + 1),
                                        )
                                    "
                                    preserve-scroll
                                    aria-label="Una ración más"
                                >
                                    <Plus />
                                </Link>
                            </Button>
                        </div>
                    </div>

                    <p
                        v-if="
                            ingredients.length === 0 && components.length === 0
                        "
                        class="rounded-md bg-muted p-6 text-center text-sm text-muted-foreground"
                    >
                        Esta receta todavía no tiene ingredientes. Añádelos
                        antes de cerrar un menú que la use.
                    </p>
                    <ul
                        v-else-if="ingredients.length > 0"
                        class="grid gap-px overflow-hidden rounded-md bg-border"
                    >
                        <li
                            v-for="(line, lineIndex) in ingredients"
                            :key="line.ingredient + line.quantity"
                            :class="[
                                'flex items-baseline justify-between gap-3 px-4 py-2.5',
                                isAlternative(lineIndex)
                                    ? 'bg-muted'
                                    : 'bg-card',
                            ]"
                        >
                            <span>
                                <Badge
                                    v-if="isAlternative(lineIndex)"
                                    variant="outline"
                                    class="mr-1.5 align-middle"
                                    >o bien</Badge
                                >
                                {{ line.ingredient }}
                                <span
                                    v-if="line.note"
                                    class="text-sm text-muted-foreground"
                                >
                                    ({{ line.note }})
                                </span>
                            </span>
                            <span class="font-semibold tabular-nums">{{
                                line.quantity
                            }}</span>
                        </li>
                    </ul>
                </section>

                <p
                    v-if="
                        ingredients.some(
                            (line) => line.alternative_group !== null,
                        )
                    "
                    class="-mt-3 text-sm text-muted-foreground"
                >
                    Las líneas con «o bien» son alternativas: se usa solo una.
                    Al planificar se propone la que tengas en casa y puedes
                    cambiarla en cada plato.
                </p>

                <section v-if="components.length > 0" class="grid gap-3">
                    <h2 class="text-sm font-bold tracking-wide uppercase">
                        Preparaciones incluidas
                    </h2>
                    <ul
                        class="grid gap-px overflow-hidden rounded-md bg-border"
                    >
                        <li
                            v-for="component in components"
                            :key="component.id"
                            class="flex items-center justify-between gap-3 bg-card px-4 py-1"
                        >
                            <Link
                                :href="show(component.id)"
                                class="tap-target font-medium underline-offset-4 hover:underline"
                            >
                                {{ component.name }}
                            </Link>
                            <span class="flex items-center gap-2">
                                <Badge
                                    v-if="component.archived"
                                    variant="secondary"
                                    >Archivada</Badge
                                >
                                <span class="font-semibold tabular-nums"
                                    >{{ component.servings }}
                                    {{
                                        component.servings === '1'
                                            ? 'ración'
                                            : 'raciones'
                                    }}</span
                                >
                            </span>
                        </li>
                    </ul>
                    <div v-if="totals.length > 0" class="grid gap-2">
                        <h3 class="text-sm font-semibold">
                            Todo lo que necesitas para {{ servings }}
                            {{ servings === 1 ? 'ración' : 'raciones' }}
                        </h3>
                        <ul
                            class="grid gap-px overflow-hidden rounded-md bg-border text-sm"
                        >
                            <li
                                v-for="total in totals"
                                :key="total.ingredient"
                                class="flex items-baseline justify-between gap-3 bg-card px-4 py-2"
                            >
                                <span>{{ total.ingredient }}</span>
                                <span class="font-semibold tabular-nums">{{
                                    total.quantity
                                }}</span>
                            </li>
                        </ul>
                    </div>
                </section>
            </div>

            <aside class="grid gap-6 lg:sticky lg:top-4">
                <section class="grid gap-2">
                    <h2 class="text-sm font-bold tracking-wide uppercase">
                        Pasos
                    </h2>
                    <p
                        v-if="recipe.instructions"
                        class="max-w-prose text-sm leading-relaxed whitespace-pre-line"
                    >
                        {{ recipe.instructions }}
                    </p>
                    <p v-else class="text-sm text-pretty text-muted-foreground">
                        Esta receta no tiene pasos escritos.
                        <template v-if="canWrite"
                            >Puedes añadirlos al editarla.</template
                        >
                    </p>
                </section>

                <section v-if="usedIn.length > 0" class="grid gap-2">
                    <h2 class="text-sm font-bold tracking-wide uppercase">
                        Se usa como preparación en
                    </h2>
                    <ul
                        class="grid gap-px overflow-hidden rounded-md bg-border"
                    >
                        <li v-for="use in usedIn" :key="use.id">
                            <Link
                                :href="show(use.id)"
                                class="flex min-h-11 items-center justify-between gap-3 bg-card px-4 py-2 text-sm font-medium hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
                            >
                                {{ use.name }}
                                <ChevronRight
                                    class="size-4 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                            </Link>
                        </li>
                    </ul>
                </section>
            </aside>
        </div>
    </div>
</template>
