<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- A hydraulic floor tile: almagre corners, an ochre diamond and a charcoal core. -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 32 32"
        aria-hidden="true"
        :class="className"
        v-bind="$attrs"
    >
        <rect width="32" height="32" rx="3" fill="#E4E3DF" />
        <g fill="var(--pigment-almagre, #A63F28)">
            <path d="M0 3a3 3 0 0 1 3-3h8a11 11 0 0 1-11 11Z" />
            <path d="M32 3a3 3 0 0 0-3-3h-8a11 11 0 0 0 11 11Z" />
            <path d="M32 29a3 3 0 0 1-3 3h-8a11 11 0 0 1 11-11Z" />
            <path d="M0 29a3 3 0 0 0 3 3h8A11 11 0 0 0 0 21Z" />
        </g>
        <path
            d="M16 6.5 25.5 16 16 25.5 6.5 16Z"
            fill="var(--pigment-ocre, #C98A1E)"
        />
        <path d="M16 12.5 19.5 16 16 19.5 12.5 16Z" fill="#1F1D1B" />
    </svg>
</template>
