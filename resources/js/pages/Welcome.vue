<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    BookOpen,
    CalendarDays,
    Refrigerator,
    ShoppingCart,
} from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

const name = usePage().props.name;

const features = [
    {
        icon: Refrigerator,
        text: 'Lo que tienes en el frigorífico, el congelador y la despensa.',
    },
    {
        icon: BookOpen,
        text: 'Tus recetas, escaladas a las raciones que necesites.',
    },
    { icon: CalendarDays, text: 'El menú de la semana, comida y cena.' },
    {
        icon: ShoppingCart,
        text: 'La lista de la compra con lo que falta de verdad.',
    },
];
</script>

<template>
    <Head title="Bienvenida" />

    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <header class="flex items-center justify-between gap-4 p-4 sm:px-8">
            <div class="flex items-center gap-2 font-semibold">
                <span
                    class="flex size-8 items-center justify-center rounded-md bg-primary text-primary-foreground"
                >
                    <AppLogoIcon class="size-5 fill-current" />
                </span>
                {{ name }}
            </div>
            <nav class="flex items-center gap-2" aria-label="Cuenta">
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
            class="mx-auto flex w-full max-w-2xl flex-1 flex-col justify-center gap-8 p-4 sm:p-8"
        >
            <div class="grid gap-3">
                <h1 class="text-2xl font-semibold sm:text-3xl">
                    Planifica la semana y compra solo lo que falta
                </h1>
                <p class="text-muted-foreground">
                    Organiza la comida de casa en un solo sitio: el menú calcula
                    lo que necesitas, lo compara con tu inventario y prepara la
                    lista de la compra.
                </p>
            </div>

            <ul class="grid gap-3 sm:grid-cols-2">
                <li
                    v-for="feature in features"
                    :key="feature.text"
                    class="flex items-start gap-3 rounded-xl border p-4 text-sm"
                >
                    <component
                        :is="feature.icon"
                        class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                    />
                    {{ feature.text }}
                </li>
            </ul>
        </main>
    </div>
</template>
