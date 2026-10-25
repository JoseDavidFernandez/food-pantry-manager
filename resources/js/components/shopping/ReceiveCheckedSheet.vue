<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { PackageCheck } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
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
import { Spinner } from '@/components/ui/spinner';
import { checked } from '@/routes/shopping-lists/receptions';
import type { Option, ShoppingItem, UnitOption } from '@/types/pantry';

/**
 * «Guardar lo marcado» (RN-46): what is in the cart goes to the stock in one operation, with
 * the suggested quantity and location of each line, both editable. The rest of the list stays
 * pending; nothing has to be decided about it. Free text stays marked, outside the stock.
 */
const props = defineProps<{
    listId: number;
    token: string;
    items: ShoppingItem[];
    units: UnitOption[];
    locations: Option[];
}>();

const open = ref(false);
const processing = ref(false);
const errors = ref<Record<string, string>>({});

const toStock = computed(() => props.items.filter((item) => item.suggested));
const freeText = computed(() =>
    props.items.filter((item) => !item.has_ingredient),
);

type Row = {
    item_id: number;
    quantity: string;
    unit: string;
    location: string;
};
const rows = reactive<Row[]>([]);

watch(open, (value) => {
    if (value) {
        errors.value = {};
        rows.splice(
            0,
            rows.length,
            ...toStock.value.map((item) => ({
                item_id: item.id,
                quantity: item.suggested!.quantity,
                unit: item.suggested!.unit,
                location: item.suggested!.location,
            })),
        );
    }
});

function save(): void {
    router.post(
        checked.url(props.listId),
        { token: props.token, lines: rows },
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
            onSuccess: () => (open.value = false),
            onError: (bag) => (errors.value = bag),
        },
    );
}

const itemName = (id: number): string =>
    props.items.find((item) => item.id === id)?.name ?? '';
</script>

<template>
    <div
        class="sticky bottom-0 z-10 -mx-4 flex flex-wrap items-center justify-between gap-2 border-t bg-background/95 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6"
        data-checked-bar
    >
        <p class="text-sm">
            <strong class="tabular-nums">{{ items.length }}</strong>
            en el carro
        </p>
        <div class="flex items-center gap-2">
            <!-- R3: the page adds its own bottom-bar actions here (e.g. «Añadir» on mobile). -->
            <slot name="actions" />
            <Button
                class="min-h-11 sm:min-h-9"
                :disabled="toStock.length === 0"
                @click="open = true"
            >
                <PackageCheck />
                Guardar {{ toStock.length }} en casa
            </Button>
        </div>
    </div>

    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>Guardar en casa</DialogTitle>
                <DialogDescription>
                    Se suma al inventario lo que has marcado, con estas
                    cantidades. Lo demás de la lista sigue pendiente.
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="save">
                <p
                    v-if="errors.lines || errors.token"
                    class="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive"
                    role="alert"
                >
                    {{ errors.lines ?? errors.token }}
                </p>
                <ul class="grid gap-3">
                    <li
                        v-for="(row, index) in rows"
                        :key="row.item_id"
                        class="grid gap-2 rounded-lg border p-3"
                    >
                        <p class="font-medium">{{ itemName(row.item_id) }}</p>
                        <div
                            class="grid grid-cols-[5.5rem_1fr] gap-2 sm:grid-cols-[6rem_1fr_1fr]"
                        >
                            <div class="grid gap-1">
                                <Label
                                    :for="`checked-quantity-${row.item_id}`"
                                    class="text-xs"
                                    >Cantidad</Label
                                >
                                <Input
                                    :id="`checked-quantity-${row.item_id}`"
                                    v-model="row.quantity"
                                    inputmode="decimal"
                                    class="h-11 tabular-nums sm:h-9"
                                    required
                                />
                            </div>
                            <div class="grid gap-1">
                                <Label
                                    :for="`checked-unit-${row.item_id}`"
                                    class="text-xs"
                                    >Unidad</Label
                                >
                                <select
                                    :id="`checked-unit-${row.item_id}`"
                                    v-model="row.unit"
                                    class="h-11 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs sm:h-9"
                                >
                                    <option
                                        v-for="unit in units"
                                        :key="unit.value"
                                        :value="unit.value"
                                    >
                                        {{ unit.label }}
                                    </option>
                                </select>
                            </div>
                            <div class="col-span-2 grid gap-1 sm:col-span-1">
                                <Label
                                    :for="`checked-location-${row.item_id}`"
                                    class="text-xs"
                                    >Dónde lo guardas</Label
                                >
                                <select
                                    :id="`checked-location-${row.item_id}`"
                                    v-model="row.location"
                                    class="h-11 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs sm:h-9"
                                >
                                    <option
                                        v-for="location in locations"
                                        :key="location.value"
                                        :value="location.value"
                                    >
                                        {{ location.label }}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <InputError
                            :message="
                                errors[`lines.${index}.quantity`] ??
                                errors[`lines.${index}.item_id`]
                            "
                        />
                    </li>
                </ul>
                <p
                    v-if="freeText.length > 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ freeText.map((item) => `«${item.name}»`).join(', ') }}
                    no
                    {{
                        freeText.length === 1
                            ? 'es un alimento'
                            : 'son alimentos'
                    }}
                    del inventario:
                    {{
                        freeText.length === 1
                            ? 'queda marcado'
                            : 'quedan marcados'
                    }}
                    como comprado.
                </p>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11 sm:min-h-9"
                            >Cancelar</Button
                        >
                    </DialogClose>
                    <Button
                        type="submit"
                        class="min-h-11 sm:min-h-9"
                        :disabled="processing || rows.length === 0"
                    >
                        <Spinner v-if="processing" />
                        Guardar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
