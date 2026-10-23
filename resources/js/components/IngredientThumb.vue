<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { cn } from '@/lib/utils';
import { categoryIcon } from '@/lib/visuals';

/**
 * Ingredient picture: the locally stored image or, without one (or if it fails to load), the
 * icon of its category. Decorative: the name is always shown next to it.
 */
const props = withDefaults(
    defineProps<{
        imageUrl?: string | null;
        category?: string | null;
        size?: 'sm' | 'md' | 'lg';
        class?: string;
    }>(),
    { imageUrl: null, category: null, size: 'md', class: '' },
);

const failed = ref(false);
watch(
    () => props.imageUrl,
    () => (failed.value = false),
);

const icon = computed(() => categoryIcon(props.category));

const sizes = {
    sm: 'size-9 rounded-lg [&_svg]:size-4',
    md: 'size-12 rounded-xl [&_svg]:size-5',
    lg: 'size-20 rounded-2xl [&_svg]:size-8',
};
</script>

<template>
    <span
        :class="
            cn(
                'flex shrink-0 items-center justify-center overflow-hidden bg-accent text-accent-foreground',
                sizes[size],
                props.class,
            )
        "
        aria-hidden="true"
    >
        <img
            v-if="imageUrl && !failed"
            :src="imageUrl"
            alt=""
            loading="lazy"
            class="size-full object-cover"
            @error="failed = true"
        />
        <component :is="icon" v-else />
    </span>
</template>
