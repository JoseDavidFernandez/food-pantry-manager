<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ImageOff, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import IngredientThumb from '@/components/IngredientThumb.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import {
    destroy as destroyImage,
    store as storeImage,
} from '@/routes/ingredients/image';
import { search as searchImages } from '@/routes/ingredients/images';
import type { ImageCandidate, ImageCredit } from '@/types/pantry';

/**
 * Optional image of an ingredient (TECHNICAL-DESIGN §10). Searching only happens when the user
 * asks, and a result is only used after choosing it explicitly. Without an image the
 * ingredient shows the icon of its category.
 *
 * - With `ingredientId`, choosing saves it at once (editing an ingredient).
 * - Without it, the choice is sent with the surrounding form (creating an ingredient).
 */
const props = defineProps<{
    idPrefix: string;
    initialQuery: string;
    category: string | null;
    ingredientId?: number;
    imageUrl?: string | null;
    credit?: ImageCredit | null;
}>();

type Source = 'photos' | 'products' | 'barcode';

const sources: { value: Source; label: string; hint: string }[] = [
    {
        value: 'photos',
        label: 'Fotos',
        hint: 'Fotos genéricas de Wikimedia Commons, con licencia libre.',
    },
    {
        value: 'products',
        label: 'Productos envasados',
        hint: 'Productos comerciales de Open Food Facts.',
    },
    {
        value: 'barcode',
        label: 'Código de barras',
        hint: 'El código EAN del envase (8 a 14 cifras), en Open Food Facts.',
    },
];

const source = ref<Source>('photos');
const query = ref(props.initialQuery);
const loading = ref(false);
const searched = ref(false);
const error = ref<string | null>(null);
const results = ref<ImageCandidate[]>([]);
const chosen = ref<ImageCandidate | null>(null);
const saving = ref(false);

const hint = computed(
    () => sources.find((s) => s.value === source.value)?.hint,
);

async function search(): Promise<void> {
    if (query.value.trim() === '') {
        return;
    }

    loading.value = true;
    error.value = null;
    chosen.value = null;

    try {
        const response = await fetch(
            searchImages.url({
                query: { source: source.value, q: query.value.trim() },
            }),
            { headers: { Accept: 'application/json' } },
        );
        const data = (await response.json()) as {
            results?: ImageCandidate[];
            error?: string | null;
            message?: string;
            errors?: Record<string, string[]>;
        };

        results.value = data.results ?? [];
        error.value =
            data.error ??
            (response.ok
                ? null
                : (Object.values(data.errors ?? {})[0]?.[0] ??
                  data.message ??
                  'No se ha podido buscar.'));
    } catch {
        results.value = [];
        error.value =
            'No se ha podido buscar. Comprueba la conexión o continúa sin imagen.';
    } finally {
        loading.value = false;
        searched.value = true;
    }
}

function save(): void {
    if (chosen.value === null || props.ingredientId === undefined) {
        return;
    }

    router.post(
        storeImage.url(props.ingredientId),
        { provider: chosen.value.provider, reference: chosen.value.reference },
        {
            preserveScroll: true,
            onStart: () => (saving.value = true),
            onFinish: () => (saving.value = false),
            onSuccess: () => {
                chosen.value = null;
                results.value = [];
                searched.value = false;
            },
        },
    );
}

function remove(): void {
    if (props.ingredientId !== undefined) {
        router.delete(destroyImage.url(props.ingredientId), {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <fieldset class="grid gap-3 rounded-lg border p-3">
        <legend class="px-1 text-sm font-medium">Imagen (opcional)</legend>

        <div class="flex items-center gap-3">
            <IngredientThumb
                :image-url="chosen ? chosen.thumbnail_url : imageUrl"
                :category="category"
                size="lg"
            />
            <div class="grid gap-1 text-xs text-muted-foreground">
                <template v-if="chosen">
                    <p class="font-medium text-foreground">
                        {{ chosen.title }}
                    </p>
                    <p>
                        {{ chosen.author ?? 'Autor desconocido' }} ·
                        {{ chosen.license ?? 'Licencia en la fuente' }}
                    </p>
                </template>
                <template v-else-if="imageUrl && credit">
                    <p>
                        Imagen:
                        <a
                            v-if="credit.source_url"
                            :href="credit.source_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="underline"
                            >{{ credit.author ?? 'fuente' }}</a
                        >
                        <template v-if="credit.license">
                            ·
                            <a
                                v-if="credit.license_url"
                                :href="credit.license_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="underline"
                                >{{ credit.license }}</a
                            >
                            <span v-else>{{ credit.license }}</span>
                        </template>
                    </p>
                    <Button
                        v-if="ingredientId !== undefined"
                        type="button"
                        variant="link"
                        size="sm"
                        class="h-auto min-h-11 justify-start p-0 text-xs sm:min-h-0"
                        @click="remove"
                    >
                        <ImageOff class="size-3" />
                        Quitar la imagen
                    </Button>
                </template>
                <p v-else>Sin imagen: se muestra el icono de su categoría.</p>
            </div>
        </div>

        <div class="grid gap-2">
            <div
                class="flex flex-wrap gap-1"
                role="radiogroup"
                aria-label="Dónde buscar"
            >
                <Button
                    class="min-h-11 sm:min-h-0"
                    v-for="option in sources"
                    :key="option.value"
                    type="button"
                    size="sm"
                    role="radio"
                    :aria-checked="source === option.value"
                    :variant="source === option.value ? 'default' : 'outline'"
                    @click="source = option.value"
                >
                    {{ option.label }}
                </Button>
            </div>
            <p class="text-xs text-muted-foreground">
                {{ hint }} La búsqueda envía solo el texto escrito.
            </p>
            <div class="flex gap-2">
                <Label :for="`${idPrefix}-image-query`" class="sr-only"
                    >Texto para buscar la imagen</Label
                >
                <Input
                    :id="`${idPrefix}-image-query`"
                    v-model="query"
                    :inputmode="source === 'barcode' ? 'numeric' : 'text'"
                    autocomplete="off"
                    class="h-11 sm:h-9"
                    @keydown.enter.prevent="search"
                />
                <Button
                    class="min-h-11 sm:min-h-0"
                    type="button"
                    variant="outline"
                    :disabled="loading || query.trim() === ''"
                    @click="search"
                >
                    <Spinner v-if="loading" />
                    <Search v-else />
                    Buscar
                </Button>
            </div>
        </div>

        <p v-if="error" class="text-sm text-destructive" role="alert">
            {{ error }}
        </p>
        <p
            v-else-if="searched && results.length === 0 && !loading"
            class="text-sm text-muted-foreground"
            aria-live="polite"
        >
            Sin resultados. Prueba con otras palabras o continúa sin imagen.
        </p>

        <ul
            v-if="results.length > 0"
            class="grid max-h-64 grid-cols-3 gap-2 overflow-y-auto sm:grid-cols-4"
            aria-label="Resultados"
        >
            <li v-for="result in results" :key="result.reference">
                <button
                    type="button"
                    :aria-pressed="chosen?.reference === result.reference"
                    :aria-label="`Elegir: ${result.title}`"
                    :class="
                        cn(
                            'aspect-square w-full overflow-hidden rounded-lg border-2 bg-muted transition-colors',
                            chosen?.reference === result.reference
                                ? 'border-primary'
                                : 'border-transparent hover:border-input',
                        )
                    "
                    @click="chosen = result"
                >
                    <img
                        :src="result.thumbnail_url"
                        alt=""
                        loading="lazy"
                        referrerpolicy="no-referrer"
                        class="size-full object-cover"
                    />
                </button>
            </li>
        </ul>

        <div v-if="chosen" class="flex flex-wrap items-center gap-2">
            <template v-if="ingredientId !== undefined">
                <Button
                    class="min-h-11 sm:min-h-0"
                    type="button"
                    :disabled="saving"
                    @click="save"
                >
                    <Spinner v-if="saving" />
                    Usar esta imagen
                </Button>
            </template>
            <template v-else>
                <input
                    type="hidden"
                    name="image_provider"
                    :value="chosen.provider"
                />
                <input
                    type="hidden"
                    name="image_reference"
                    :value="chosen.reference"
                />
                <p class="text-sm text-muted-foreground">
                    Se guardará al crear el ingrediente.
                </p>
            </template>
            <Button
                class="min-h-11 sm:min-h-0"
                type="button"
                variant="ghost"
                @click="chosen = null"
            >
                No usar ninguna
            </Button>
        </div>
    </fieldset>
</template>
