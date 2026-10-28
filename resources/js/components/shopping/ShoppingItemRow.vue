<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Check, ChevronDown, CircleAlert, Pencil, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import {
    destroy,
    purchased,
    reviewed,
    update,
} from '@/routes/shopping-lists/items';
import type { ShoppingItem, UnitOption } from '@/types/pantry';

const props = defineProps<{
    listId: number;
    item: ShoppingItem;
    units: UnitOption[];
    /** History lists are read only (RN-36). */
    readonly: boolean;
}>();

const editOpen = ref(false);
// In the cart (RN-46): marked as bought, not at home yet.
const inCart = computed(
    () => props.item.is_purchased && !props.item.is_received,
);
const deleteOpen = ref(false);

// M4: the main figure is what is missing, not a purchase: «Faltan 300 g» (or «Falta 1 bote»).
// When the food is bought in packs, what to buy goes below: «Para comprar: 1 bolsa».
const missing = computed(
    () => props.item.detail?.missing ?? props.item.display,
);
const missingVerb = computed(() =>
    /^1(\s|$)/.test(missing.value ?? '') ? 'Falta' : 'Faltan',
);
const args = computed(() => ({ list: props.listId, item: props.item.id }));

// Ingredient lines keep their compatible unit group; free-text lines may use any unit.
const unitOptions = computed(() =>
    props.item.has_ingredient
        ? props.units.filter((unit) => unit.group === props.item.unit_group)
        : props.units,
);

const reviewMessage = computed(() => {
    const calculated = props.item.calculated ?? '—';

    switch (props.item.review_reason) {
        case 'incompatible_inventory':
            return 'Tienes este ingrediente en otra unidad que no se puede convertir: revisa si te basta.';
        case 'calculation_changed':
            return `El menú ha cambiado: el nuevo cálculo es ${calculated}.`;
        case 'no_longer_needed':
            return 'El menú ya no lo necesita.';
        case 'quantity_increased':
            return `Ahora hace falta más (${calculated}): se ha quitado la marca de comprado.`;
        case 'manual_with_calculation':
            return `El menú también lo necesita: cálculo ${calculated}.`;
        default:
            return 'Revisa esta línea.';
    }
});
</script>

<template>
    <li
        :class="
            cn('grid grid-cols-1 gap-2 bg-card px-4 py-3', inCart && 'bg-muted')
        "
        :data-in-cart="inCart ? '' : undefined"
    >
        <div class="flex items-start gap-3">
            <!-- The box looks like 28 px; the button around it is the 44 px touch area. -->
            <span
                v-if="readonly"
                class="-m-2 grid size-11 shrink-0 place-items-center"
                :aria-label="item.is_purchased ? 'En el carro' : 'Pendiente'"
                role="img"
            >
                <span
                    :class="
                        cn(
                            'flex size-7 items-center justify-center rounded-[3px] border-2',
                            item.is_purchased
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-input',
                        )
                    "
                >
                    <Check v-if="item.is_purchased" class="size-4" />
                </span>
            </span>
            <Form
                v-else
                v-bind="purchased.form(args)"
                :options="{ preserveScroll: true }"
                v-slot="{ processing }"
                class="-m-2 shrink-0"
            >
                <button
                    type="submit"
                    role="checkbox"
                    :aria-checked="item.is_purchased"
                    :aria-label="`${item.is_purchased ? 'Desmarcar' : 'Marcar'} ${item.name} como comprado`"
                    :disabled="processing"
                    class="group grid size-11 place-items-center rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <span
                        :class="
                            cn(
                                'flex size-7 items-center justify-center rounded-[3px] border-2 transition-colors',
                                item.is_purchased
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'border-input group-hover:border-primary',
                            )
                        "
                    >
                        <Check v-if="item.is_purchased" class="size-4" />
                    </span>
                </button>
            </Form>

            <div class="grid min-w-0 flex-1 gap-0.5">
                <p
                    :class="
                        cn(
                            'font-semibold break-words',
                            item.is_received &&
                                'text-muted-foreground line-through',
                            inCart && 'text-muted-foreground',
                        )
                    "
                >
                    {{ item.name }}
                </p>
                <p
                    v-if="item.quantity"
                    class="text-sm tabular-nums"
                    data-quantity
                >
                    {{ missingVerb }}
                    <strong>{{ missing }}</strong>
                </p>
                <p
                    v-if="item.detail?.pack"
                    class="text-xs text-muted-foreground tabular-nums"
                    data-pack
                >
                    Para comprar:
                    <strong class="text-foreground">{{ item.display }}</strong>
                    ({{ item.detail.pack }})
                </p>
                <details
                    v-if="item.detail"
                    class="group text-xs text-muted-foreground"
                    data-calculation
                >
                    <summary
                        class="-ml-1 flex min-h-11 w-fit cursor-pointer items-center rounded px-1 outline-none focus-visible:ring-2 focus-visible:ring-ring sm:min-h-6"
                    >
                        Ver cálculo
                        <ChevronDown
                            class="ml-1 size-3.5 transition-transform group-open:rotate-180"
                            aria-hidden="true"
                        />
                    </summary>
                    <ul class="grid gap-0.5 pb-1 tabular-nums">
                        <li v-if="item.detail.recipes ?? !item.detail.restock">
                            Para las comidas de este periodo:
                            {{ item.detail.recipes ?? item.detail.need }}
                        </li>
                        <li v-if="item.detail.restock">
                            {{
                                item.detail.restock_mode === 'minimum'
                                    ? 'Mínimo en casa'
                                    : 'Para reponer (se acabó)'
                            }}: {{ item.detail.restock }}
                        </li>
                        <li v-if="item.detail.before">
                            Antes se usan, en comidas más próximas:
                            {{ item.detail.before }}
                        </li>
                        <li>
                            En casa:
                            <template v-if="item.detail.registered"
                                >≈ {{ item.detail.have ?? 'nada' }}
                                previsto (registrado
                                {{ item.detail.registered }})</template
                            ><template v-else>{{
                                item.detail.have ?? 'nada'
                            }}</template>
                        </li>
                        <li v-if="item.detail.pack">
                            Se compra en envases enteros:
                            {{ item.display }}.
                        </li>
                    </ul>
                </details>
            </div>

            <Dialog v-if="!readonly" v-model:open="editOpen">
                <DialogTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-11 text-muted-foreground hover:text-foreground sm:size-9"
                        :aria-label="`Editar ${item.name}`"
                    >
                        <Pencil />
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Editar {{ item.name }}</DialogTitle>
                        <DialogDescription>
                            Tu cantidad se mantiene. Si el menú cambia y hace
                            falta otra, te lo avisamos en esta línea.
                        </DialogDescription>
                    </DialogHeader>
                    <Form
                        v-bind="update.form(args)"
                        :error-bag="`editItem${item.id}`"
                        :options="{ preserveScroll: true }"
                        v-slot="{ errors, processing }"
                        class="grid gap-4"
                        @success="editOpen = false"
                    >
                        <div class="grid grid-cols-2 gap-3">
                            <div class="grid gap-2">
                                <Label :for="`qty-${item.id}`">Cantidad</Label>
                                <Input
                                    class="h-11 sm:h-9"
                                    :id="`qty-${item.id}`"
                                    name="quantity"
                                    inputmode="decimal"
                                    :default-value="item.quantity ?? ''"
                                    :required="item.has_ingredient"
                                />
                                <InputError :message="errors.quantity" />
                            </div>
                            <div class="grid gap-2">
                                <Label :for="`unit-${item.id}`">Unidad</Label>
                                <NativeSelect
                                    class="h-11 sm:h-9"
                                    :id="`unit-${item.id}`"
                                    name="unit"
                                    :options="unitOptions"
                                    :default-value="item.unit"
                                    :placeholder="
                                        item.has_ingredient
                                            ? undefined
                                            : 'Sin unidad'
                                    "
                                />
                                <InputError :message="errors.unit" />
                            </div>
                        </div>
                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button
                                    class="min-h-11 sm:min-h-0"
                                    type="button"
                                    variant="outline"
                                    >Cancelar</Button
                                >
                            </DialogClose>
                            <Button
                                class="min-h-11 sm:min-h-0"
                                type="submit"
                                :disabled="processing"
                            >
                                <Spinner v-if="processing" />
                                Guardar
                            </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>

            <Dialog v-if="!readonly" v-model:open="deleteOpen">
                <DialogTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-11 text-muted-foreground hover:text-foreground sm:size-9"
                        :aria-label="`Quitar ${item.name} de la lista`"
                    >
                        <Trash2 />
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle
                            >¿Quitar «{{ item.name }}» de la lista?</DialogTitle
                        >
                        <DialogDescription v-if="item.is_generated">
                            No volverá a aparecer al recalcular, salvo que el
                            menú cambie lo que necesitas de este ingrediente.
                        </DialogDescription>
                        <DialogDescription v-else>
                            Se eliminará esta línea que añadiste.
                        </DialogDescription>
                    </DialogHeader>
                    <Form
                        v-bind="destroy.form(args)"
                        :options="{ preserveScroll: true }"
                        v-slot="{ processing }"
                        @success="deleteOpen = false"
                    >
                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button
                                    class="min-h-11 sm:min-h-0"
                                    type="button"
                                    variant="outline"
                                    >Cancelar</Button
                                >
                            </DialogClose>
                            <Button
                                class="min-h-11 sm:min-h-0"
                                type="submit"
                                variant="destructive"
                                :disabled="processing"
                            >
                                <Spinner v-if="processing" />
                                Quitar de la lista
                            </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>

        <div
            v-if="
                inCart ||
                item.detail?.restock_mode ||
                !item.is_generated ||
                item.is_user_modified ||
                !item.has_ingredient ||
                item.is_received ||
                item.received
            "
            class="grid gap-1 pl-10"
        >
            <p class="flex flex-wrap gap-1">
                <Badge
                    v-if="item.detail?.restock_mode === 'minimum'"
                    variant="outline"
                    >Mínimo en casa</Badge
                >
                <Badge
                    v-else-if="item.detail?.restock_mode === 'once'"
                    variant="outline"
                    >Se acabó</Badge
                >
                <Badge v-if="!item.is_generated" variant="outline"
                    >Añadida por ti</Badge
                >
                <Badge v-else-if="item.is_user_modified" variant="outline"
                    >Modificada</Badge
                >
                <Badge v-if="!item.has_ingredient" variant="secondary"
                    >Texto libre</Badge
                >
                <Badge v-if="item.is_received" variant="success"
                    >Recibido</Badge
                >
            </p>
            <p
                v-if="item.received && !item.is_received"
                class="text-xs text-muted-foreground"
            >
                Recibido {{ item.received
                }}<template v-if="item.remaining">
                    · falta {{ item.remaining }}</template
                ><template v-if="item.other_units">
                    (parte en otra unidad)</template
                >
            </p>
        </div>

        <div
            v-if="item.needs_review"
            class="flex flex-wrap items-center gap-2 rounded-md border border-warning-border bg-warning-soft px-3 py-2 text-sm"
        >
            <CircleAlert class="size-4 shrink-0" />
            <span class="flex-1">{{ reviewMessage }}</span>
            <Form
                v-if="!readonly"
                v-bind="reviewed.form(args)"
                :options="{ preserveScroll: true }"
                v-slot="{ processing }"
            >
                <Button
                    type="submit"
                    variant="outline"
                    size="sm"
                    class="min-h-11 sm:min-h-8"
                    :disabled="processing"
                >
                    Revisado
                </Button>
            </Form>
        </div>
    </li>
</template>
