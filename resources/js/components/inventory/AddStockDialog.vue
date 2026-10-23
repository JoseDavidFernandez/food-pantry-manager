<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
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
defineProps<{
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
        document.getElementById('quantity')?.focus();
    }
}
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
                            default-value="g"
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
