<script setup lang="ts">
/**
 * Page title (the only h1 of the page), an optional description and the page actions
 * (DESIGN-SYSTEM §2: one title and one visible primary action per screen).
 */
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <header class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
        <div class="grid min-w-0 gap-1">
            <h1
                class="text-[1.75rem] leading-none font-display text-balance sm:text-3xl"
            >
                {{ title }}
            </h1>
            <p v-if="description" class="text-sm text-muted-foreground">
                {{ description }}
            </p>
            <slot name="meta" />
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </header>
</template>
