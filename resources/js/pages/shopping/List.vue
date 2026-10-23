<script setup lang="ts">
import { Form, Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    CalendarDays,
    ChevronDown,
    History,
    PackageCheck,
    Plus,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import PageHeader from '@/components/PageHeader.vue';
import CoverageNote from '@/components/shopping/CoverageNote.vue';
import ReceiveCheckedSheet from '@/components/shopping/ReceiveCheckedSheet.vue';
import ShoppingItemRow from '@/components/shopping/ShoppingItemRow.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useHouseholdAccess } from '@/composables/useHouseholdAccess';
import { cn } from '@/lib/utils';
import { show as showMenu } from '@/routes/menu';
import { period as periodRoute } from '@/routes/shopping-list';
import { index as history } from '@/routes/shopping-lists';
import { store } from '@/routes/shopping-lists/items';
import { create as newReception } from '@/routes/shopping-lists/receptions';
import type {
    Option,
    ReceptionSummary,
    ShoppingItem,
    SimilarIngredient,
    UnitOption,
} from '@/types/pantry';

/**
 * Shopping list of a purchase period (RN-36): persistent, reconciled with the menu, the
 * recipes and the stock every time it is opened. Lists of past periods and the old weekly
 * lists are shown read only.
 */
const props = defineProps<{
    list: {
        id: number;
        label: string;
        active: boolean;
        weekly: boolean;
        start: string;
        end: string;
        days: number | null;
        past: boolean;
        regenerated_at: string | null;
        dismissed_count: number;
    };
    quickDays: number[];
    /** What the period counts (null for history lists): RN-36, point 2. */
    coverage: {
        dishes: number;
        past_this_week: number;
        /** RN-42: dishes that use a proposed (not confirmed) alternative. */
        proposals?: number;
    } | null;
    items: ShoppingItem[];
    receptions: ReceptionSummary[];
    historyCount: number;
    units: UnitOption[];
    suggestions: string[];
    similar: SimilarIngredient[];
    typed: string | null;
    /** RN-46: one «Guardar lo marcado» per token, and where things can be kept. */
    checkedToken: string;
    locations: Option[];
    errors?: Record<string, string>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Lista de la compra', href: periodRoute() }],
    },
});

const pending = computed(
    () =>
        props.items.filter((item) => !item.is_purchased && !item.is_received)
            .length,
);
// In the cart (RN-46): marked as bought and not received yet.
const inCart = computed(() =>
    props.items.filter((item) => item.is_purchased && !item.is_received),
);
const toReview = computed(
    () => props.items.filter((item) => item.needs_review).length,
);

const { canWrite } = useHouseholdAccess();
const start = ref(props.list.start);
const end = ref(props.list.end);

// B2 (2026-10-22): on a phone the products come first; the period and adding an item open
// in dialogs. A failed add reopens its dialog so the error and the suggestions are visible.
const periodOpen = ref(false);
const page = usePage();
const addOpen = ref(
    props.similar.length > 0 ||
        Object.keys(
            (page.props.errors as Record<string, unknown>).addItem ?? {},
        ).length > 0,
);
const periodLabel = computed(() =>
    props.list.days !== null
        ? `Próximos ${props.list.days} días`
        : 'Fechas propias',
);
// «22 – 28 oct 2026»: the dates of the period in one short line.
const range = computed(() => {
    const date = (value: string) => new Date(`${value}T12:00:00`);
    const day = new Intl.DateTimeFormat('es-ES', {
        day: 'numeric',
        month: 'short',
    });
    const year = date(props.list.end).getFullYear();

    return `${day.format(date(props.list.start))} – ${day.format(date(props.list.end))} ${year}`;
});
const notices = computed(
    () =>
        ((props.coverage?.past_this_week ?? 0) > 0 ? 1 : 0) +
        ((props.coverage?.proposals ?? 0) > 0 ? 1 : 0),
);

function applyCustom(): void {
    router.get(
        periodRoute.url({ query: { start: start.value, end: end.value } }),
        {},
        { preserveScroll: true },
    );
}

function useSuggestion(name: string): void {
    const input = document.getElementById('item');

    if (input instanceof HTMLInputElement) {
        input.value = name;
        document.getElementById('item-quantity')?.focus();
    }
}
</script>

<template>
    <Head title="Lista de la compra" />

    <div
        class="mx-auto flex w-full max-w-3xl flex-col gap-4 p-4 sm:gap-6 sm:p-6"
    >
        <PageHeader title="Lista de la compra" :description="range" />

        <p
            v-if="!list.active"
            class="rounded-xl border border-warning-border bg-warning-soft p-4 text-sm"
        >
            Esta lista es del historial ({{
                list.weekly ? 'lista semanal anterior' : 'periodo terminado'
            }}): se conserva tal como quedó y no se puede modificar.
            <Link :href="periodRoute()" class="font-medium underline"
                >Ir a la lista actual</Link
            >.
        </p>

        <div class="grid gap-2">
            <div class="flex items-center justify-between gap-2">
                <Dialog v-if="list.active" v-model:open="periodOpen">
                    <DialogTrigger as-child>
                        <Button
                            variant="outline"
                            class="min-h-11 sm:min-h-9"
                            :aria-label="`Periodo de compra: ${periodLabel}. Cambiar`"
                        >
                            <CalendarDays />
                            {{ periodLabel }}
                            <ChevronDown />
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Periodo de compra</DialogTitle>
                            <DialogDescription>
                                Cada periodo guarda su lista: tus cambios y lo
                                que está en el carro se conservan al volver a
                                él. Se actualiza sola con el menú, las recetas y
                                lo que tienes en casa.
                            </DialogDescription>
                        </DialogHeader>
                        <nav
                            class="flex flex-wrap gap-2"
                            aria-label="Próximos días"
                        >
                            <Link
                                v-for="days in quickDays"
                                :key="days"
                                :href="periodRoute({ query: { days } })"
                                preserve-scroll
                                :aria-current="
                                    list.days === days ? 'page' : undefined
                                "
                                :class="
                                    cn(
                                        'flex min-h-11 items-center rounded-full border px-4 text-sm font-medium transition-colors',
                                        list.days === days
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'bg-card hover:bg-accent',
                                    )
                                "
                                @click="periodOpen = false"
                            >
                                Próximos {{ days }} días
                            </Link>
                        </nav>
                        <form class="grid gap-3" @submit.prevent="applyCustom">
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label for="period-start">Desde</Label>
                                    <Input
                                        id="period-start"
                                        v-model="start"
                                        type="date"
                                        class="h-11 sm:h-9"
                                        required
                                    />
                                    <InputError :message="errors?.start" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="period-end">Hasta</Label>
                                    <Input
                                        id="period-end"
                                        v-model="end"
                                        type="date"
                                        class="h-11 sm:h-9"
                                        :min="start"
                                        required
                                    />
                                    <InputError :message="errors?.end" />
                                </div>
                            </div>
                            <Button
                                type="submit"
                                variant="outline"
                                class="min-h-11 sm:min-h-9"
                                >Ver estas fechas</Button
                            >
                        </form>
                    </DialogContent>
                </Dialog>

                <Dialog v-if="list.active && canWrite" v-model:open="addOpen">
                    <DialogTrigger as-child>
                        <Button
                            variant="outline"
                            class="ml-auto min-h-11 sm:min-h-9"
                        >
                            <Plus />
                            Añadir
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Añadir a la lista</DialogTitle>
                            <DialogDescription>
                                Un alimento del inventario o cualquier otra cosa
                                (por ejemplo, papel de cocina).
                            </DialogDescription>
                        </DialogHeader>
                        <Form
                            v-bind="store.form(list.id)"
                            error-bag="addItem"
                            :reset-on-success="[
                                'item',
                                'quantity',
                                'free_text',
                            ]"
                            :options="{ preserveScroll: true }"
                            v-slot="{ errors: addErrors, processing }"
                            class="grid gap-4"
                            @success="addOpen = false"
                        >
                            <div class="grid gap-2">
                                <Label for="item">Artículo</Label>
                                <Input
                                    id="item"
                                    name="item"
                                    list="shopping-suggestions"
                                    autocomplete="off"
                                    :default-value="typed ?? ''"
                                    placeholder="Un ingrediente o lo que quieras"
                                    class="h-11 sm:h-9"
                                    required
                                />
                                <datalist id="shopping-suggestions">
                                    <option
                                        v-for="name in suggestions"
                                        :key="name"
                                        :value="name"
                                    />
                                </datalist>
                                <InputError :message="addErrors.item" />
                                <div
                                    v-if="similar.length > 0"
                                    class="flex flex-wrap items-center gap-2 text-sm"
                                >
                                    <span>¿Querías decir…?</span>
                                    <Button
                                        v-for="match in similar"
                                        :key="match.id"
                                        type="button"
                                        variant="secondary"
                                        size="sm"
                                        class="min-h-11 sm:min-h-8"
                                        @click="useSuggestion(match.name)"
                                    >
                                        {{ match.name }}
                                    </Button>
                                </div>
                                <Label
                                    for="free_text"
                                    class="flex min-h-11 items-center gap-2 text-sm font-normal sm:min-h-0"
                                >
                                    <Checkbox
                                        id="free_text"
                                        name="free_text"
                                        value="1"
                                    />
                                    Añadir como texto libre (no es un alimento
                                    del inventario)
                                </Label>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div class="grid gap-2">
                                    <Label for="item-quantity">Cantidad</Label>
                                    <Input
                                        id="item-quantity"
                                        name="quantity"
                                        inputmode="decimal"
                                        placeholder="Opcional en texto libre"
                                        class="h-11 sm:h-9"
                                    />
                                    <InputError :message="addErrors.quantity" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="item-unit">Unidad</Label>
                                    <NativeSelect
                                        id="item-unit"
                                        name="unit"
                                        :options="units"
                                        placeholder="Sin unidad"
                                        class="h-11 sm:h-9"
                                    />
                                    <InputError :message="addErrors.unit" />
                                </div>
                            </div>
                            <Button
                                type="submit"
                                class="min-h-11 w-full sm:min-h-9 sm:w-auto sm:justify-self-end"
                                :disabled="processing"
                            >
                                <Spinner v-if="processing" />
                                Añadir a la lista
                            </Button>
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
            <p class="flex flex-wrap gap-x-3 text-sm" aria-live="polite">
                <span
                    ><strong class="tabular-nums">{{ pending }}</strong> por
                    comprar</span
                >
                <span v-if="inCart.length > 0"
                    ><strong class="tabular-nums">{{ inCart.length }}</strong>
                    en el carro</span
                >
                <span v-if="toReview > 0" class="text-warning">
                    <strong class="tabular-nums">{{ toReview }}</strong> para
                    revisar
                </span>
            </p>
        </div>

        <CoverageNote
            v-if="coverage && notices > 0"
            :coverage="coverage"
            :notices="notices"
        />

        <p
            v-if="items.length === 0"
            class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
        >
            No necesitas comprar nada en este periodo.
        </p>

        <ul v-else class="divide-y rounded-xl border">
            <ShoppingItemRow
                v-for="item in items"
                :key="item.id"
                :list-id="list.id"
                :item="item"
                :units="units"
                :readonly="!list.active || !canWrite"
            />
        </ul>

        <p
            v-if="list.dismissed_count > 0"
            class="text-xs text-muted-foreground"
        >
            {{ list.dismissed_count }}
            {{
                list.dismissed_count === 1 ? 'línea quitada' : 'líneas quitadas'
            }}
            por ti; reaparecerán solo si cambia lo que necesitas.
        </p>

        <CoverageNote
            v-if="coverage && notices === 0"
            :coverage="coverage"
            :notices="0"
        />

        <details
            v-if="receptions.length > 0"
            class="group rounded-xl border px-4"
            data-receptions
        >
            <summary
                class="flex min-h-11 cursor-pointer items-center text-sm font-semibold outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
            >
                Compras registradas ({{ receptions.length }})
                <ChevronDown
                    class="ml-1 size-4 transition-transform group-open:rotate-180"
                    aria-hidden="true"
                />
            </summary>
            <ul class="grid gap-3 pb-4">
                <li
                    v-for="reception in receptions"
                    :key="reception.id"
                    class="rounded-lg border p-3 text-sm"
                >
                    <p class="font-medium">{{ reception.confirmed_at }}</p>
                    <ul class="mt-1 grid gap-0.5">
                        <li v-for="line in reception.items" :key="line.id">
                            <template v-if="line.received">
                                {{ line.name }}:
                                <strong>{{ line.display }}</strong> en
                                {{ line.location }}
                                <span
                                    v-if="line.unforeseen"
                                    class="text-muted-foreground"
                                    >(no previsto)</span
                                >
                            </template>
                            <span v-else class="text-muted-foreground"
                                >{{ line.name }}: no comprado</span
                            >
                        </li>
                    </ul>
                </li>
            </ul>
        </details>

        <div class="grid gap-3 border-t pt-4 text-sm text-muted-foreground">
            <p v-if="list.active && canWrite">
                Para lo que no estaba en la lista o para decidir línea a línea,
                usa «Registrar compra».
            </p>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="list.active && canWrite"
                    variant="outline"
                    class="min-h-11 sm:min-h-9"
                    as-child
                >
                    <Link :href="newReception(list.id)">
                        <PackageCheck />
                        Registrar compra
                    </Link>
                </Button>
                <Button variant="outline" class="min-h-11 sm:min-h-9" as-child>
                    <Link :href="showMenu()">
                        <CalendarDays />
                        Ver menú
                    </Link>
                </Button>
                <Button
                    v-if="historyCount > 0 || !list.active"
                    variant="outline"
                    class="min-h-11 sm:min-h-9"
                    as-child
                >
                    <Link :href="history()">
                        <History />
                        Historial
                    </Link>
                </Button>
            </div>
        </div>

        <ReceiveCheckedSheet
            v-if="list.active && canWrite && inCart.length > 0"
            :list-id="list.id"
            :token="checkedToken"
            :items="inCart"
            :units="units"
            :locations="locations"
        />
    </div>
</template>
