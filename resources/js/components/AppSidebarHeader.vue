<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { House } from '@lucide/vue';
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { show as showHousehold } from '@/routes/household';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

// The household of the signed-in member (shared on every request), so a rename by the admin
// shows up on the next page without keeping a copy anywhere.
const page = usePage();
const household = computed(() => page.props.auth.household);
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center gap-2 border-b border-border px-4 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 sm:px-6 md:px-4"
    >
        <div class="flex min-w-0 flex-1 items-center gap-2">
            <SidebarTrigger class="-ml-1 size-11 shrink-0 sm:size-7" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>
        <Link
            v-if="household"
            :href="showHousehold()"
            class="relative flex max-w-[45%] min-w-0 shrink items-center gap-1.5 rounded-md border bg-card px-3 py-1 text-sm shadow-xs after:absolute after:inset-x-0 after:-inset-y-2 after:content-[''] hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:max-w-xs"
            :title="household.name"
            :aria-label="`Hogar: ${household.name}`"
        >
            <House class="size-4 shrink-0 text-primary" aria-hidden="true" />
            <span class="truncate font-medium">{{ household.name }}</span>
        </Link>
    </header>
</template>
