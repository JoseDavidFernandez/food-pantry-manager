<script setup lang="ts">
import type { Component } from 'vue';

/**
 * Empty state (DESIGN-SYSTEM §5): an icon, a short sentence and, if there is one, the action
 * that solves it.
 */
defineProps<{
    icon: Component;
    title: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="flex flex-col items-center gap-3 rounded-xl border border-dashed bg-card/50 px-6 py-10 text-center"
    >
        <span
            class="flex size-12 items-center justify-center rounded-full bg-accent text-accent-foreground"
            aria-hidden="true"
        >
            <component :is="icon" class="size-6" />
        </span>
        <div class="grid gap-1">
            <p class="font-medium">{{ title }}</p>
            <p
                v-if="description"
                class="max-w-md text-sm text-muted-foreground"
            >
                {{ description }}
            </p>
        </div>
        <slot />
    </div>
</template>
