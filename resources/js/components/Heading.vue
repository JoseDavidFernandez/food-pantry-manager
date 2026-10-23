<script setup lang="ts">
/**
 * Page title (default: the only h1 of the page, DESIGN-SYSTEM §2) or section title (small: h2).
 */
type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
};

withDefaults(defineProps<Props>(), {
    variant: 'default',
});
</script>

<template>
    <header v-if="variant === 'small'">
        <h2 class="mb-0.5 text-base font-semibold">{{ title }}</h2>
        <p v-if="description" class="text-sm text-muted-foreground">
            {{ description }}
        </p>
    </header>
    <header v-else class="grid gap-1">
        <h1 class="text-2xl font-semibold tracking-tight">{{ title }}</h1>
        <p v-if="description" class="text-sm text-muted-foreground">
            {{ description }}
        </p>
    </header>
</template>
