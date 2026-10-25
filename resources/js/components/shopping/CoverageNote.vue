<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import { show as showMenu } from '@/routes/menu';

/**
 * What the list counts and why (RN-36, RN-42, RN-44), folded: above the list when there is
 * something to notice, below it otherwise, so it never pushes the products down (B2).
 */
defineProps<{
    coverage: {
        dishes: number;
        past_this_week: number;
        proposals?: number;
    };
    notices: number;
}>();
</script>

<template>
    <details
        class="group rounded-lg text-sm"
        :class="
            notices > 0
                ? 'border border-warning-border bg-warning-soft px-3'
                : 'text-muted-foreground'
        "
        data-coverage
    >
        <summary
            class="flex min-h-11 cursor-pointer items-center rounded outline-none focus-visible:ring-2 focus-visible:ring-ring sm:min-h-8"
        >
            <template v-if="notices > 0"
                >{{ notices === 1 ? '1 aviso' : `${notices} avisos` }} sobre el
                cálculo</template
            >
            <template v-else>¿Cómo se calcula?</template>
            <ChevronDown
                class="ml-1 size-4 transition-transform group-open:rotate-180"
                aria-hidden="true"
            />
        </summary>
        <div class="grid gap-2 pb-3" aria-live="polite">
            <p>
                {{
                    coverage.dishes === 0
                        ? 'No hay platos planificados en este periodo a partir de hoy.'
                        : `Calculada con ${coverage.dishes} ${coverage.dishes === 1 ? 'plato planificado' : 'platos planificados'} del periodo que aún no han pasado (sin los marcados como «No comido»), descontando lo que tienes en casa.`
                }}
            </p>
            <p v-if="coverage.past_this_week > 0" role="note">
                {{
                    coverage.past_this_week === 1
                        ? 'Hay 1 plato en un día de esta semana que ya ha pasado'
                        : `Hay ${coverage.past_this_week} platos en días de esta semana que ya han pasado`
                }}: se conservan en el menú como historial y ya cuentan en lo
                que tienes previsto en casa. Para comprar sus ingredientes,
                planifícalos de hoy en adelante.
            </p>
            <p v-if="(coverage.proposals ?? 0) > 0" role="note" data-proposals>
                {{
                    coverage.proposals === 1
                        ? '1 plato usa'
                        : `${coverage.proposals} platos usan`
                }}
                una opción propuesta entre ingredientes alternativos (por
                ejemplo, nata o leche evaporada). La lista la incluye;
                confírmala o cámbiala en el
                <Link
                    :href="showMenu()"
                    class="font-medium underline underline-offset-4"
                    >menú</Link
                >.
            </p>
        </div>
    </details>
</template>
