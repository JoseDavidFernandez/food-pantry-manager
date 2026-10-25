<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import ReadOnlyBanner from '@/components/ReadOnlyBanner.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <!-- R7: keyboard users skip the sidebar (first element, visible on focus). -->
        <a
            href="#contenido"
            class="sr-only z-50 rounded-md bg-primary px-4 py-3 text-sm font-semibold text-primary-foreground focus:not-sr-only focus:fixed focus:top-2 focus:left-2"
            >Saltar al contenido</a
        >
        <AppSidebar />
        <AppContent
            id="contenido"
            tabindex="-1"
            variant="sidebar"
            class="min-w-0 overflow-x-clip focus:outline-none"
        >
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <ReadOnlyBanner />
            <slot />
        </AppContent>
        <Toaster />
    </AppShell>
</template>
