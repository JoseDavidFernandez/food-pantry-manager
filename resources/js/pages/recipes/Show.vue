<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import {
    Archive,
    ArchiveRestore,
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

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 sm:p-6">
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

        <section class="grid gap-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-medium">Ingredientes</h2>
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
                            :href="servingsHref(Math.max(1, servings - 1))"
                            preserve-scroll
                            aria-label="Una ración menos"
                        >
                            <Minus />
                        </Link>
                    </Button>
                    <span class="w-20 text-center font-semibold tabular-nums">
                        {{ servings }}
                        {{ servings === 1 ? 'ración' : 'raciones' }}
                    </span>
                    <Button variant="outline" size="icon-sm" as-child>
                        <Link
                            :href="servingsHref(Math.min(100, servings + 1))"
                            preserve-scroll
                            aria-label="Una ración más"
                        >
                            <Plus />
                        </Link>
                    </Button>
                </div>
            </div>

            <p
                v-if="ingredients.length === 0 && components.length === 0"
                class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                Esta receta todavía no tiene ingredientes. Añádelos antes de
                cerrar un menú que la use.
            </p>
            <ul
                v-else-if="ingredients.length > 0"
                class="divide-y rounded-xl border"
            >
                <li
                    v-for="(line, lineIndex) in ingredients"
                    :key="line.ingredient + line.quantity"
                    :class="[
                        'flex items-baseline justify-between gap-3 px-4 py-2',
                        isAlternative(lineIndex) &&
                            'border-l-4 border-l-primary bg-accent/40',
                    ]"
                >
                    <span>
                        <span
                            v-if="isAlternative(lineIndex)"
                            class="mr-1 text-xs font-medium text-primary uppercase"
                            >o bien</span
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
            v-if="ingredients.some((line) => line.alternative_group !== null)"
            class="-mt-3 text-sm text-muted-foreground"
        >
            Las líneas con «o bien» son alternativas: se usa solo una. Al
            planificar se propone la que tengas en casa y puedes cambiarla en
            cada plato.
        </p>

        <section v-if="components.length > 0" class="grid gap-3">
            <h2 class="font-medium">Preparaciones incluidas</h2>
            <ul class="divide-y rounded-xl border">
                <li
                    v-for="component in components"
                    :key="component.id"
                    class="flex items-baseline justify-between gap-3 px-4 py-2"
                >
                    <Link
                        :href="show(component.id)"
                        class="font-medium underline-offset-4 hover:underline"
                    >
                        {{ component.name }}
                    </Link>
                    <span class="flex items-center gap-2">
                        <Badge v-if="component.archived" variant="secondary"
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
                <h3 class="text-sm font-medium">
                    Todo lo que necesitas para {{ servings }}
                    {{ servings === 1 ? 'ración' : 'raciones' }}
                </h3>
                <ul class="divide-y rounded-xl border text-sm">
                    <li
                        v-for="total in totals"
                        :key="total.ingredient"
                        class="flex items-baseline justify-between gap-3 px-4 py-2"
                    >
                        <span>{{ total.ingredient }}</span>
                        <span class="font-semibold tabular-nums">{{
                            total.quantity
                        }}</span>
                    </li>
                </ul>
            </div>
        </section>

        <p v-if="usedIn.length > 0" class="text-sm text-muted-foreground">
            Se usa como preparación en:
            <template v-for="(use, useIndex) in usedIn" :key="use.id">
                <Link
                    :href="show(use.id)"
                    class="underline underline-offset-4"
                    >{{ use.name }}</Link
                >{{ useIndex < usedIn.length - 1 ? ', ' : '.' }}
            </template>
        </p>

        <section v-if="recipe.instructions" class="grid gap-2">
            <h2 class="font-medium">Preparación</h2>
            <p class="text-sm whitespace-pre-line">{{ recipe.instructions }}</p>
        </section>
    </div>
</template>
