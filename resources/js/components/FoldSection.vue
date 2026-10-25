<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';

/**
 * A folded section of a long sheet (R4): its title and a one-line summary of what is inside,
 * so the sheet reads at a glance and opens only where the user wants to change something.
 */
defineProps<{
    title: string;
    summary?: string | null;
    open?: boolean;
}>();
</script>

<template>
    <details class="group border-t" :open="open" data-fold>
        <summary
            class="flex min-h-12 cursor-pointer list-none items-center gap-3 py-2 outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset [&::-webkit-details-marker]:hidden"
        >
            <span class="grid min-w-0 flex-1">
                <span class="text-sm font-semibold">{{ title }}</span>
                <span
                    v-if="summary"
                    class="truncate text-xs text-muted-foreground"
                    >{{ summary }}</span
                >
            </span>
            <ChevronDown
                class="size-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-180"
                aria-hidden="true"
            />
        </summary>
        <div class="pb-4">
            <slot />
        </div>
    </details>
</template>
