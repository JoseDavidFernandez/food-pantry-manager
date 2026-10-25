<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { Component } from 'vue';
import { cn } from '@/lib/utils';

/**
 * Filter tabs shared by the lists (inventory locations, active/archived recipes): links with
 * aria-current, 44 px on mobile and a horizontal scroll that fades at the edge when they overflow.
 */
export type FilterTab = {
    label: string;
    href: NonNullable<InertiaLinkProps['href']>;
    active: boolean;
    icon?: Component;
    count?: number;
};

defineProps<{
    tabs: FilterTab[];
    label: string;
    preserveScroll?: boolean;
}>();
</script>

<template>
    <nav
        class="-mx-4 flex gap-1.5 overflow-x-auto [mask-image:linear-gradient(to_right,black_calc(100%-2rem),transparent)] px-4 pb-1 sm:mx-0 sm:flex-wrap sm:[mask-image:none] sm:px-0"
        :aria-label="label"
    >
        <Link
            v-for="tab in tabs"
            :key="tab.label"
            :href="tab.href"
            :preserve-scroll="preserveScroll"
            :aria-current="tab.active ? 'page' : undefined"
            :class="
                cn(
                    'flex min-h-11 shrink-0 items-center gap-2 rounded-md border px-3.5 text-sm font-semibold transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none sm:min-h-10',
                    tab.active
                        ? 'border-foreground bg-foreground text-background'
                        : 'border-border bg-card text-foreground hover:bg-accent',
                )
            "
        >
            <component
                :is="tab.icon"
                v-if="tab.icon"
                class="size-4"
                aria-hidden="true"
            />
            {{ tab.label }}
            <span
                v-if="tab.count !== undefined"
                :class="
                    cn(
                        'tabular-nums',
                        tab.active
                            ? 'text-background'
                            : 'text-muted-foreground',
                    )
                "
                >{{ tab.count }}</span
            >
        </Link>
    </nav>
</template>
