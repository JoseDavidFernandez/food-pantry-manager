<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Plus, TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
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
import { store as storeAlias } from '@/routes/ingredients/aliases';
import { store } from '@/routes/inventory';
import type {
    Ingredient,
    Option,
    SimilarIngredient,
    StorageLocation,
    UnitOption,
} from '@/types/pantry';

/**
 * Add a food to the fridge, freezer or pantry (USER-FLOWS §3) in a dialog: the inventory page
 * keeps one primary action and the list stays at the top. A food of the catalog without stock
 * opens it with its name already filled in. It stays open while there are validation errors.
 */
const props = defineProps<{
    foods: Ingredient[];
    suggestions: string[];
    similar: SimilarIngredient[];
    typed: string | null;
    units: UnitOption[];
    locations: Option[];
    categories: Option[];
    defaultLocation: StorageLocation | null;
    prefill: string | null;
}>();

const open = defineModel<boolean>('open', { required: true });
const createIngredient = defineModel<boolean>('create', { default: false });

// Fill the ingredient field with a suggestion; the user still submits the form.
function useSuggestion(name: string): void {
    const input = document.getElementById('ingredient');

    if (input instanceof HTMLInputElement) {
        input.value = name;
        typedName.value = name;
        document.getElementById('quantity')?.focus();
    }
}

// E1: the unit follows the food that the typed name or alias resolves to (as the server
// resolves it: exact name, without case, accents or extra spaces), so a food counted in cans
// is not added in grams by mistake. A new food starts in grams.
const normalize = (name: string): string =>
    name
        .trim()
        .replace(/\s+/g, ' ')
        .toLowerCase()
        .normalize('NFD')
        .replace(/\p{Mn}/gu, '')
        .normalize('NFC');

const foodsByName = computed(() => {
    const map = new Map<string, Ingredient>();

    for (const food of props.foods) {
        map.set(normalize(food.name), food);

        for (const alias of food.aliases) {
            map.set(normalize(alias.alias), food);
        }
    }

    return map;
});

const typedName = ref(props.prefill ?? '');
const unit = ref('g');
const food = computed(
    () => foodsByName.value.get(normalize(typedName.value)) ?? null,
);

watch(
    () => props.prefill,
    (value) => (typedName.value = value ?? ''),
);
watch(food, (value) => (unit.value = value?.default_unit ?? 'g'), {
    immediate: true,
});

const unitOf = (value: string | null | undefined): UnitOption | undefined =>
    props.units.find((candidate) => candidate.value === value);

// The units this food is counted in (its usual unit and those joined by its conversions) go
// first in the selector.
const preferred = computed(() => {
    const groups = food.value?.unit_groups ?? [];

    return props.units
        .filter((option) => groups.includes(option.group))
        .map((option) => option.value);
});

// M2: a pack (lata, bote, paquete…) may say what it holds, once per food. If the food already
// knows it, it is shown; otherwise the content is optional and saved as its conversion.
const isPack = computed(() => unitOf(unit.value)?.kind === 'pack');
const knownContent = computed(
    () => food.value?.pack_contents?.[unit.value] ?? null,
);
const contentQuantity = ref('');
const contentUnit = ref('g');
const CONTENT_PRESETS = [
    { quantity: '250', unit: 'g', label: '250 g' },
    { quantity: '500', unit: 'g', label: '500 g' },
    { quantity: '1', unit: 'kg', label: '1 kg' },
    { quantity: '1', unit: 'l', label: '1 l' },
];
const CONTENT_UNITS = props.units.filter((option) =>
    ['g', 'kg', 'ml', 'l'].includes(option.value),
);

watch([unit, food], () => {
    contentQuantity.value = '';
    contentUnit.value = 'g';
});

// The chosen unit cannot be compared with how this food is usually counted (RN-06, RN-40).
const incompatible = computed(() => {
    const groups = food.value?.unit_groups ?? [];
    const chosen = unitOf(unit.value);

    // A pack whose content is being given here becomes comparable once it is saved.
    const content = unitOf(contentQuantity.value ? contentUnit.value : null);

    return (
        food.value?.default_unit != null &&
        chosen !== undefined &&
        !groups.includes(chosen.group) &&
        !(isPack.value && content && groups.includes(content.group))
    );
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button class="min-h-11 sm:min-h-9">
                <Plus />
                Añadir al inventario
            </Button>
        </DialogTrigger>
        <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Añadir al inventario</DialogTitle>
                <DialogDescription>
                    Un alimento nuevo o más cantidad de uno que ya tienes. Si ya
                    está en la misma ubicación con una unidad compatible, se
                    suma a lo que hay.
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="typed && similar.length > 0"
                class="grid gap-2 rounded-lg border bg-muted/40 p-3 text-sm"
            >
                <p>
                    Si «{{ typed }}» es un plural o un sinónimo de un alimento
                    que ya tienes, guárdalo como alias para que se reconozca
                    siempre:
                </p>
                <div class="flex flex-wrap gap-2">
                    <Form
                        v-for="match in similar"
                        :key="match.id"
                        v-bind="storeAlias.form(match.id)"
                        :error-bag="`addAlias${match.id}`"
                        :options="{ preserveScroll: true }"
                        v-slot="{ processing: saving }"
                    >
                        <input type="hidden" name="alias" :value="typed" />
                        <Button
                            class="min-h-11 sm:min-h-0"
                            type="submit"
                            variant="outline"
                            size="sm"
                            :disabled="saving"
                        >
                            «{{ typed }}» es «{{ match.name }}»
                        </Button>
                    </Form>
                </div>
            </div>

            <Form
                :key="prefill ?? ''"
                v-bind="store.form()"
                error-bag="addStock"
                :reset-on-success="[
                    'ingredient',
                    'quantity',
                    'label',
                    'create_ingredient',
                    'confirm_new',
                    'category',
                ]"
                :options="{ preserveScroll: true }"
                v-slot="{ errors, processing }"
                class="grid gap-4"
                @success="
                    open = false;
                    createIngredient = false;
                    typedName = '';
                    contentQuantity = '';
                "
            >
                <div class="grid gap-2">
                    <Label for="ingredient">Alimento</Label>
                    <Input
                        class="h-11 sm:h-9"
                        id="ingredient"
                        name="ingredient"
                        :default-value="prefill ?? ''"
                        list="ingredient-suggestions"
                        autocomplete="off"
                        placeholder="Por ejemplo: arroz"
                        required
                        @input="
                            typedName = ($event.target as HTMLInputElement)
                                .value
                        "
                    />
                    <datalist id="ingredient-suggestions">
                        <option
                            v-for="name in suggestions"
                            :key="name"
                            :value="name"
                        />
                    </datalist>
                    <InputError :message="errors.ingredient" />
                    <div
                        v-if="errors.ingredient && similar.length > 0"
                        class="grid gap-2 rounded-lg border border-dashed p-3 text-sm"
                    >
                        <p>¿Querías decir…?</p>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                class="min-h-11 sm:min-h-0"
                                v-for="match in similar"
                                :key="match.id"
                                type="button"
                                variant="secondary"
                                size="sm"
                                @click="useSuggestion(match.name)"
                            >
                                {{ match.name }}
                                <span
                                    v-if="match.via"
                                    class="text-muted-foreground"
                                >
                                    (alias «{{ match.via }}»)
                                </span>
                            </Button>
                        </div>
                        <Label
                            for="confirm_new"
                            class="flex min-h-11 items-center gap-2 font-normal sm:min-h-0"
                        >
                            <Checkbox
                                id="confirm_new"
                                name="confirm_new"
                                value="1"
                            />
                            No, «{{ typed }}» es un ingrediente nuevo
                        </Label>
                    </div>
                    <Label
                        for="create_ingredient"
                        class="flex min-h-11 items-center gap-2 text-sm font-normal sm:min-h-0"
                    >
                        <Checkbox
                            id="create_ingredient"
                            v-model="createIngredient"
                            name="create_ingredient"
                            value="1"
                        />
                        Es un alimento nuevo: crearlo en el catálogo
                    </Label>
                    <div v-if="createIngredient" class="grid gap-2">
                        <Label for="category"
                            >Categoría del nuevo alimento</Label
                        >
                        <NativeSelect
                            class="h-11 sm:h-9"
                            id="category"
                            name="category"
                            :options="categories"
                            placeholder="Sin clasificar"
                        />
                        <InputError :message="errors.category" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="quantity">Cantidad</Label>
                        <Input
                            class="h-11 sm:h-9"
                            id="quantity"
                            name="quantity"
                            inputmode="decimal"
                            placeholder="1,5"
                            required
                        />
                        <InputError :message="errors.quantity" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="unit">Unidad</Label>
                        <NativeSelect
                            class="h-11 sm:h-9"
                            id="unit"
                            name="unit"
                            :options="units"
                            :preferred="preferred"
                            :default-value="unit"
                            :aria-describedby="
                                incompatible ? 'unit-warning' : undefined
                            "
                            @change="
                                unit = ($event.target as HTMLSelectElement)
                                    .value
                            "
                        />
                        <InputError :message="errors.unit" />
                    </div>
                    <div class="col-span-2 grid gap-2 sm:col-span-1">
                        <Label for="location">Ubicación</Label>
                        <NativeSelect
                            class="h-11 sm:h-9"
                            id="location"
                            name="location"
                            :options="locations"
                            :default-value="defaultLocation ?? 'pantry'"
                        />
                        <InputError :message="errors.location" />
                    </div>
                </div>

                <p
                    v-if="isPack && knownContent"
                    class="-mt-1 text-sm text-muted-foreground"
                    data-pack-content
                >
                    Cada envase trae
                    <strong class="text-foreground">{{ knownContent }}</strong>
                    (de su ficha).
                </p>
                <fieldset
                    v-else-if="isPack"
                    class="-mt-1 grid gap-2"
                    aria-describedby="content-help"
                >
                    <legend class="mb-2 text-sm font-medium">
                        ¿Cuánto trae cada envase? (opcional)
                    </legend>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-for="preset in CONTENT_PRESETS"
                            :key="preset.label"
                            type="button"
                            variant="outline"
                            size="sm"
                            :aria-pressed="
                                contentQuantity === preset.quantity &&
                                contentUnit === preset.unit
                            "
                            class="min-w-11 aria-pressed:border-primary aria-pressed:bg-primary aria-pressed:text-primary-foreground"
                            @click="
                                contentQuantity = preset.quantity;
                                contentUnit = preset.unit;
                            "
                        >
                            {{ preset.label }}
                        </Button>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="grid gap-1">
                            <Label for="content_quantity" class="text-xs"
                                >Contenido</Label
                            >
                            <Input
                                v-model="contentQuantity"
                                class="h-11 sm:h-9"
                                id="content_quantity"
                                name="content_quantity"
                                inputmode="decimal"
                                placeholder="500"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label for="content_unit" class="text-xs"
                                >Unidad</Label
                            >
                            <NativeSelect
                                class="h-11 sm:h-9"
                                id="content_unit"
                                :name="contentQuantity ? 'content_unit' : ''"
                                :options="CONTENT_UNITS"
                                :default-value="contentUnit"
                                @change="
                                    contentUnit = (
                                        $event.target as HTMLSelectElement
                                    ).value
                                "
                            />
                        </div>
                    </div>
                    <p id="content-help" class="text-xs text-muted-foreground">
                        Se guarda en su ficha: la próxima vez ya estará puesto y
                        las recetas en gramos o mililitros podrán contar estos
                        envases.
                    </p>
                    <InputError
                        :message="
                            errors.content_quantity ?? errors.content_unit
                        "
                    />
                </fieldset>

                <p
                    v-if="incompatible && food"
                    id="unit-warning"
                    role="status"
                    class="-mt-1 flex gap-2 rounded-md border border-warning-border bg-warning-soft p-3 text-sm text-warning"
                >
                    <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                    <span>
                        «{{ food.name }}» se cuenta en
                        {{ unitOf(food.default_unit)?.label }}. En
                        {{ unitOf(unit)?.label }} no se podrá comparar con lo
                        que piden las recetas, salvo que
                        {{
                            isPack
                                ? 'indiques cuánto trae cada envase'
                                : 'añadas una equivalencia en su ficha'
                        }}.
                    </span>
                </p>

                <div class="grid gap-2">
                    <Label for="label">Etiqueta (opcional)</Label>
                    <Input
                        class="h-11 sm:h-9"
                        id="label"
                        name="label"
                        placeholder="Por ejemplo: paquete abierto"
                    />
                    <InputError :message="errors.label" />
                </div>

                <Button
                    type="submit"
                    class="min-h-11 w-full sm:min-h-0 sm:w-auto sm:justify-self-end"
                    :disabled="processing"
                >
                    <Spinner v-if="processing" />
                    Añadir al inventario
                </Button>
            </Form>
        </DialogContent>
    </Dialog>
</template>
