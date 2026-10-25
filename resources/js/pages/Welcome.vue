<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ShoppingCart } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

const name = usePage().props.name;

// R6: the page shows the product instead of describing it: a sample week of tiles and a line
// of the shopping list, drawn with the app's own system. Sample content, labelled as such.
const sampleWeek = [
    { day: 'Lun', lunch: 'Lentejas', dinner: 'Tortilla' },
    { day: 'Mar', lunch: 'Merluza', dinner: null },
    { day: 'Mié', lunch: null, dinner: 'Crema de calabaza' },
];
</script>

<template>
    <Head title="Bienvenida" />

    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <header class="flex items-center justify-between gap-4 p-4 sm:px-8">
            <div class="flex min-w-0 items-center gap-2">
                <AppLogoIcon class="size-8 shrink-0" />
                <span
                    class="text-[15px] leading-none font-display max-sm:sr-only"
                    >{{ name }}</span
                >
            </div>
            <nav class="flex shrink-0 items-center gap-2" aria-label="Cuenta">
                <Button v-if="$page.props.auth.user" as-child>
                    <Link :href="dashboard()">Ir al inicio</Link>
                </Button>
                <template v-else>
                    <Button variant="ghost" as-child>
                        <Link :href="login()">Iniciar sesión</Link>
                    </Button>
                    <Button as-child>
                        <Link :href="register()">Crear cuenta</Link>
                    </Button>
                </template>
            </nav>
        </header>

        <main
            class="mx-auto grid w-full max-w-6xl flex-1 items-center gap-10 p-4 sm:p-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,28rem)]"
        >
            <div class="grid gap-5">
                <h1
                    class="text-4xl leading-[0.95] font-display text-balance sm:text-6xl"
                >
                    Planifica la semana. Compra solo lo que falta.
                </h1>
                <p
                    class="max-w-prose text-lg text-pretty text-muted-foreground"
                >
                    El menú calcula lo que necesitas, lo compara con lo que
                    tienes en casa y prepara la lista de la compra. Sin apuntar
                    nada a diario.
                </p>
                <div v-if="!$page.props.auth.user" class="flex flex-wrap gap-2">
                    <Button size="lg" as-child>
                        <Link :href="register()">Crear cuenta</Link>
                    </Button>
                    <Button size="lg" variant="outline" as-child>
                        <Link :href="login()">Ya tengo cuenta</Link>
                    </Button>
                </div>
            </div>

            <!-- A sample of the product, not data: not interactive, marked «Ejemplo». -->
            <figure class="grid gap-3" aria-label="Ejemplo de menú y de lista">
                <div
                    class="grid gap-1.5 rounded-md bg-border p-1.5"
                    aria-hidden="true"
                >
                    <div
                        v-for="row in sampleWeek"
                        :key="row.day"
                        class="grid grid-cols-[3rem_1fr_1fr] gap-1.5"
                    >
                        <span
                            class="grid place-items-center rounded-sm bg-card text-sm font-display"
                            >{{ row.day }}</span
                        >
                        <span
                            v-for="(dish, index) in [row.lunch, row.dinner]"
                            :key="index"
                            :class="[
                                'relative min-h-16 overflow-hidden rounded-sm p-2.5 text-sm font-semibold',
                                dish === null
                                    ? 'bg-muted text-muted-foreground'
                                    : index === 0
                                      ? 'bg-tile-lunch'
                                      : 'bg-tile-dinner',
                            ]"
                            >{{ dish ?? '+' }}
                            <span
                                v-if="dish !== null"
                                :class="[
                                    'absolute right-0 bottom-0 size-4 rounded-tl-full',
                                    index === 0
                                        ? 'bg-pigment-ocre'
                                        : 'bg-pigment-cobalto',
                                ]"
                        /></span>
                    </div>
                </div>
                <div
                    class="flex items-center gap-3 rounded-md bg-card p-3 text-sm"
                    aria-hidden="true"
                >
                    <ShoppingCart class="size-5 shrink-0 text-primary" />
                    <span class="min-w-0 flex-1"
                        ><span class="font-semibold">Croquetas</span> · Comprar
                        <strong>5 bolsas</strong></span
                    >
                    <span class="text-xs text-muted-foreground"
                        >1 bolsa = 20 unidades</span
                    >
                </div>
                <figcaption class="text-xs text-muted-foreground">
                    Ejemplo: comidas planificadas y lo que la lista pide
                    comprar.
                </figcaption>
            </figure>
        </main>
    </div>
</template>
