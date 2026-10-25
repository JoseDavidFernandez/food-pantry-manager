<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, PackagePlus, Trash2 } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { period as showList } from '@/routes/shopping-list';
import { store } from '@/routes/shopping-lists/receptions';
import type { Option, ReceptionLine, UnitOption } from '@/types/pantry';

type Outcome = 'received' | 'not_purchased' | null;

type Entry = {
    ingredient: string;
    quantity: string;
    unit: string;
    location: string;
};

const props = defineProps<{
    list: { id: number; label: string; query: Record<string, string | number> };
    token: string;
    lines: ReceptionLine[];
    units: UnitOption[];
    locations: Option[];
    suggestions: string[];
    receptionCount: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Lista de la compra', href: showList() }],
    },
});

// Every line starts undecided (RN-29); the suggestion is only a starting value.
const decisions = reactive<Outcome[]>(props.lines.map(() => null));
const values = reactive<Entry[]>(
    props.lines.map((line) => ({
        ingredient: line.name,
        quantity: line.quantity ?? '',
        unit: line.unit ?? '',
        location: line.location ?? '',
    })),
);

let nextExtra = 0;
const extras = ref<(Entry & { key: number })[]>([]);

function addExtra(): void {
    extras.value.push({
        key: nextExtra++,
        ingredient: '',
        quantity: '',
        unit: 'g',
        location: '',
    });
}

function removeExtra(key: number): void {
    extras.value = extras.value.filter((extra) => extra.key !== key);
}

const undecided = computed(() => decisions.filter((d) => d === null).length);
const dirty = computed(
    () => decisions.some((d) => d !== null) || extras.value.length > 0,
);

const locationLabel = (value: string): string =>
    props.locations.find((option) => option.value === value)?.label ?? '—';
const unitLabel = (value: string): string =>
    props.units.find((option) => option.value === value)?.label ?? value;

function stockIn(line: ReceptionLine, location: string): string | null {
    return line.stock.find((row) => row.location === location)?.display ?? null;
}

const summary = computed(() => ({
    received: props.lines
        .map((line, index) => ({ line, index }))
        .filter(({ index }) => decisions[index] === 'received')
        .map(({ line, index }) => ({
            name: line.has_ingredient
                ? line.name
                : values[index].ingredient || line.name,
            amount: `${values[index].quantity} ${unitLabel(values[index].unit)}`,
            location: locationLabel(values[index].location),
            current: stockIn(line, values[index].location),
        })),
    extras: extras.value
        .filter((extra) => extra.ingredient.trim() !== '')
        .map((extra) => ({
            name: extra.ingredient,
            amount: `${extra.quantity} ${unitLabel(extra.unit)}`,
            location: locationLabel(extra.location),
        })),
    notPurchased: props.lines
        .filter((_, index) => decisions[index] === 'not_purchased')
        .map((line) => line.name),
}));

const confirmOpen = ref(false);
let submitting = false;

function setSubmitting(value: boolean): void {
    submitting = value;
}

// No drafts (RN-29): warn before leaving with decisions that would be lost.
function beforeUnload(event: BeforeUnloadEvent): void {
    if (dirty.value && !submitting) {
        event.preventDefault();
    }
}

let removeRouterGuard: (() => void) | null = null;

onMounted(() => {
    window.addEventListener('beforeunload', beforeUnload);
    removeRouterGuard = router.on('before', (event) => {
        const visit = event.detail.visit;

        if (visit.method !== 'get' || submitting || !dirty.value) {
            return;
        }

        return window.confirm(
            'Si sales ahora, se perderá lo que has marcado en esta compra. ¿Salir igualmente?',
        );
    });
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', beforeUnload);
    removeRouterGuard?.();
});
</script>

<template>
    <Head title="Registrar compra" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 pb-28 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Registrar compra"
                :description="`Lista de la compra · ${list.label}`"
            />
            <Button variant="outline" size="sm" as-child>
                <Link :href="showList({ query: list.query })">
                    <ArrowLeft />
                    Volver a la lista
                </Link>
            </Button>
        </div>

        <p class="text-sm text-muted-foreground">
            Indica qué has traído realmente. Solo lo recibido se suma al
            inventario; lo que no compres sigue pendiente para otra compra.
            <template v-if="receptionCount > 0">
                Ya has registrado {{ receptionCount }}
                {{ receptionCount === 1 ? 'compra' : 'compras' }} de esta lista:
                aquí solo aparece lo que falta.
            </template>
        </p>

        <Form
            id="reception-form"
            v-bind="store.form(list.id)"
            v-slot="{ errors, processing }"
            class="grid gap-6"
            @start="setSubmitting(true)"
            @finish="setSubmitting(false)"
            @success="confirmOpen = false"
            @error="confirmOpen = false"
        >
            <input type="hidden" name="token" :value="token" />

            <p
                v-if="errors.lines || errors.token"
                class="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive"
                role="alert"
            >
                {{ errors.lines ?? errors.token }}
            </p>

            <section class="grid gap-3" aria-labelledby="pending-title">
                <h2 id="pending-title" class="text-sm font-semibold">
                    Líneas de la lista
                </h2>

                <p
                    v-if="lines.length === 0"
                    class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
                >
                    Todo lo de la lista ya está recibido. Puedes registrar
                    productos no previstos.
                </p>

                <ul v-else class="grid gap-3">
                    <li
                        v-for="(line, index) in lines"
                        :key="line.id"
                        :class="
                            cn(
                                'grid gap-3 rounded-xl border p-4',
                                decisions[index] === null && 'border-dashed',
                            )
                        "
                    >
                        <input
                            type="hidden"
                            :name="`lines[${index}][item_id]`"
                            :value="line.id"
                        />

                        <div
                            class="flex flex-wrap items-start justify-between gap-2"
                        >
                            <div class="min-w-0">
                                <p class="font-medium">{{ line.name }}</p>
                                <p class="text-sm text-muted-foreground">
                                    <template v-if="line.display"
                                        >Pendiente: {{ line.display }}</template
                                    >
                                    <template v-else
                                        >Sin cantidad prevista</template
                                    >
                                    <template v-if="line.received">
                                        · ya recibido
                                        {{ line.received }}</template
                                    >
                                </p>
                                <p
                                    v-if="line.other_units"
                                    class="text-xs text-muted-foreground"
                                >
                                    Parte llegó en otra unidad que no se puede
                                    convertir, así que no se descuenta. Si ya
                                    tienes suficiente, marca «No comprado» o
                                    quítala de la lista.
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-1">
                                <Badge
                                    v-if="line.is_purchased"
                                    variant="secondary"
                                    >Marcado como comprado</Badge
                                >
                                <Badge
                                    v-if="!line.has_ingredient"
                                    variant="outline"
                                    >Texto libre</Badge
                                >
                            </div>
                        </div>

                        <fieldset class="grid grid-cols-2 gap-2">
                            <legend class="sr-only">
                                ¿Has recibido {{ line.name }}?
                            </legend>
                            <label
                                v-for="choice in [
                                    { value: 'received', label: 'Recibido' },
                                    {
                                        value: 'not_purchased',
                                        label: 'No comprado',
                                    },
                                ] as const"
                                :key="choice.value"
                                :class="
                                    cn(
                                        'flex min-h-11 cursor-pointer items-center justify-center rounded-md border px-3 text-sm font-medium transition-colors has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring',
                                        decisions[index] === choice.value
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'hover:bg-accent',
                                    )
                                "
                            >
                                <input
                                    v-model="decisions[index]"
                                    type="radio"
                                    class="sr-only"
                                    :name="`lines[${index}][outcome]`"
                                    :value="choice.value"
                                />
                                {{ choice.label }}
                            </label>
                        </fieldset>
                        <InputError
                            :message="errors[`lines.${index}.outcome`]"
                        />

                        <div
                            v-if="decisions[index] === 'received'"
                            class="grid gap-3"
                        >
                            <div v-if="!line.has_ingredient" class="grid gap-2">
                                <Label :for="`line-ingredient-${index}`"
                                    >Ingrediente del inventario</Label
                                >
                                <Input
                                    :id="`line-ingredient-${index}`"
                                    v-model="values[index].ingredient"
                                    :name="`lines[${index}][ingredient]`"
                                    list="reception-suggestions"
                                    autocomplete="off"
                                />
                                <InputError
                                    :message="
                                        errors[`lines.${index}.ingredient`]
                                    "
                                />
                                <Label
                                    :for="`line-create-${index}`"
                                    class="flex items-center gap-2 text-sm font-normal"
                                >
                                    <Checkbox
                                        :id="`line-create-${index}`"
                                        :name="`lines[${index}][create_ingredient]`"
                                        value="1"
                                    />
                                    Crear el ingrediente si no está en el
                                    catálogo
                                </Label>
                                <Label
                                    v-if="errors[`lines.${index}.ingredient`]"
                                    :for="`line-new-${index}`"
                                    class="flex items-center gap-2 text-sm font-normal"
                                >
                                    <Checkbox
                                        :id="`line-new-${index}`"
                                        :name="`lines[${index}][confirm_new]`"
                                        value="1"
                                    />
                                    Es un ingrediente nuevo, distinto de los
                                    sugeridos
                                </Label>
                            </div>

                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                <div class="grid gap-2">
                                    <Label :for="`line-quantity-${index}`"
                                        >Cantidad recibida</Label
                                    >
                                    <Input
                                        :id="`line-quantity-${index}`"
                                        v-model="values[index].quantity"
                                        :name="`lines[${index}][quantity]`"
                                        inputmode="decimal"
                                        required
                                    />
                                    <InputError
                                        :message="
                                            errors[`lines.${index}.quantity`]
                                        "
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label :for="`line-unit-${index}`"
                                        >Unidad</Label
                                    >
                                    <NativeSelect
                                        :id="`line-unit-${index}`"
                                        :name="`lines[${index}][unit]`"
                                        :options="units"
                                        :default-value="values[index].unit"
                                        placeholder="Elige"
                                        @change="
                                            values[index].unit = (
                                                $event.target as HTMLSelectElement
                                            ).value
                                        "
                                    />
                                    <InputError
                                        :message="errors[`lines.${index}.unit`]"
                                    />
                                </div>
                                <div
                                    class="col-span-2 grid gap-2 sm:col-span-1"
                                >
                                    <Label :for="`line-location-${index}`"
                                        >Dónde lo guardas</Label
                                    >
                                    <NativeSelect
                                        :id="`line-location-${index}`"
                                        :name="`lines[${index}][location]`"
                                        :options="locations"
                                        :default-value="values[index].location"
                                        placeholder="Elige"
                                        @change="
                                            values[index].location = (
                                                $event.target as HTMLSelectElement
                                            ).value
                                        "
                                    />
                                    <InputError
                                        :message="
                                            errors[`lines.${index}.location`]
                                        "
                                    />
                                </div>
                            </div>
                            <p
                                v-if="line.stock.length > 0"
                                class="text-xs text-muted-foreground"
                            >
                                Ahora tienes:
                                <template
                                    v-for="(row, i) in line.stock"
                                    :key="row.location"
                                >
                                    {{ i > 0 ? ' · ' : '' }}{{ row.display }} en
                                    {{ locationLabel(row.location) }}
                                </template>
                            </p>
                        </div>
                    </li>
                </ul>
            </section>

            <section class="grid gap-3" aria-labelledby="extras-title">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="extras-title" class="text-sm font-semibold">
                        Productos no previstos
                    </h2>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="addExtra"
                    >
                        <PackagePlus />
                        Añadir producto no previsto
                    </Button>
                </div>

                <ul v-if="extras.length > 0" class="grid gap-3">
                    <li
                        v-for="(extra, index) in extras"
                        :key="extra.key"
                        class="grid gap-3 rounded-xl border p-4"
                    >
                        <div class="flex items-end gap-2">
                            <div class="grid flex-1 gap-2">
                                <Label :for="`extra-ingredient-${extra.key}`"
                                    >Ingrediente</Label
                                >
                                <Input
                                    :id="`extra-ingredient-${extra.key}`"
                                    v-model="extra.ingredient"
                                    :name="`extras[${index}][ingredient]`"
                                    list="reception-suggestions"
                                    autocomplete="off"
                                    placeholder="Por ejemplo: huevos"
                                />
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label="Quitar este producto"
                                @click="removeExtra(extra.key)"
                            >
                                <Trash2 />
                            </Button>
                        </div>
                        <InputError
                            :message="errors[`extras.${index}.ingredient`]"
                        />
                        <Label
                            :for="`extra-create-${extra.key}`"
                            class="flex items-center gap-2 text-sm font-normal"
                        >
                            <Checkbox
                                :id="`extra-create-${extra.key}`"
                                :name="`extras[${index}][create_ingredient]`"
                                value="1"
                            />
                            Crear el ingrediente si no está en el catálogo
                        </Label>
                        <Label
                            v-if="errors[`extras.${index}.ingredient`]"
                            :for="`extra-new-${extra.key}`"
                            class="flex items-center gap-2 text-sm font-normal"
                        >
                            <Checkbox
                                :id="`extra-new-${extra.key}`"
                                :name="`extras[${index}][confirm_new]`"
                                value="1"
                            />
                            Es un ingrediente nuevo, distinto de los sugeridos
                        </Label>

                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            <div class="grid gap-2">
                                <Label :for="`extra-quantity-${extra.key}`"
                                    >Cantidad</Label
                                >
                                <Input
                                    :id="`extra-quantity-${extra.key}`"
                                    v-model="extra.quantity"
                                    :name="`extras[${index}][quantity]`"
                                    inputmode="decimal"
                                />
                                <InputError
                                    :message="
                                        errors[`extras.${index}.quantity`]
                                    "
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label :for="`extra-unit-${extra.key}`"
                                    >Unidad</Label
                                >
                                <NativeSelect
                                    :id="`extra-unit-${extra.key}`"
                                    :name="`extras[${index}][unit]`"
                                    :options="units"
                                    :default-value="extra.unit"
                                    @change="
                                        extra.unit = (
                                            $event.target as HTMLSelectElement
                                        ).value
                                    "
                                />
                                <InputError
                                    :message="errors[`extras.${index}.unit`]"
                                />
                            </div>
                            <div class="col-span-2 grid gap-2 sm:col-span-1">
                                <Label :for="`extra-location-${extra.key}`"
                                    >Dónde lo guardas</Label
                                >
                                <NativeSelect
                                    :id="`extra-location-${extra.key}`"
                                    :name="`extras[${index}][location]`"
                                    :options="locations"
                                    :default-value="extra.location"
                                    placeholder="Elige"
                                    @change="
                                        extra.location = (
                                            $event.target as HTMLSelectElement
                                        ).value
                                    "
                                />
                                <InputError
                                    :message="
                                        errors[`extras.${index}.location`]
                                    "
                                />
                            </div>
                        </div>
                    </li>
                </ul>
            </section>

            <datalist id="reception-suggestions">
                <option v-for="name in suggestions" :key="name" :value="name" />
            </datalist>

            <div
                class="fixed inset-x-0 bottom-0 z-10 border-t bg-background/95 p-3 backdrop-blur sm:static sm:border-0 sm:bg-transparent sm:p-0"
            >
                <div
                    class="mx-auto flex max-w-3xl items-center justify-between gap-3"
                >
                    <p class="text-sm" aria-live="polite">
                        <template v-if="undecided > 0">
                            Quedan
                            <strong class="tabular-nums">{{
                                undecided
                            }}</strong>
                            por decidir
                        </template>
                        <template v-else>Todo decidido</template>
                    </p>
                    <Button
                        type="button"
                        :disabled="undecided > 0 || processing"
                        @click="confirmOpen = true"
                    >
                        Confirmar compra
                    </Button>
                </div>
            </div>

            <Dialog v-model:open="confirmOpen">
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle
                            >¿Confirmar y actualizar el inventario?</DialogTitle
                        >
                        <DialogDescription>
                            Se sumará al inventario solo lo recibido. Esta
                            compra no se puede deshacer desde aquí.
                        </DialogDescription>
                    </DialogHeader>

                    <div
                        class="grid max-h-[50vh] gap-3 overflow-y-auto text-sm"
                    >
                        <div
                            v-if="
                                summary.received.length +
                                    summary.extras.length >
                                0
                            "
                        >
                            <p class="font-medium">Se suma al inventario</p>
                            <ul class="mt-1 grid gap-1">
                                <li
                                    v-for="row in summary.received"
                                    :key="row.name"
                                >
                                    {{ row.name }}:
                                    <strong>+{{ row.amount }}</strong> en
                                    {{ row.location }}
                                    <span
                                        v-if="row.current"
                                        class="text-muted-foreground"
                                        >(ahora tienes {{ row.current }})</span
                                    >
                                </li>
                                <li
                                    v-for="row in summary.extras"
                                    :key="`extra-${row.name}`"
                                >
                                    {{ row.name }}:
                                    <strong>+{{ row.amount }}</strong> en
                                    {{ row.location }}
                                    <span class="text-muted-foreground"
                                        >(no previsto)</span
                                    >
                                </li>
                            </ul>
                        </div>
                        <div v-if="summary.notPurchased.length > 0">
                            <p class="font-medium">
                                No comprado (sin cambios, sigue pendiente)
                            </p>
                            <p class="text-muted-foreground">
                                {{ summary.notPurchased.join(', ') }}
                            </p>
                        </div>
                    </div>

                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="outline"
                                >Revisar</Button
                            >
                        </DialogClose>
                        <Button
                            type="submit"
                            form="reception-form"
                            :disabled="processing"
                        >
                            <Spinner v-if="processing" />
                            Confirmar y actualizar inventario
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Form>
    </div>
</template>
