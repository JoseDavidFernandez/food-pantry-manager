<script setup lang="ts">
import { onBeforeUnmount } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import {
    BookOpen,
    CalendarDays,
    LayoutGrid,
    Refrigerator,
    ShoppingCart,
} from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as inventory } from '@/routes/inventory';
import { show as menu } from '@/routes/menu';
import { index as recipes } from '@/routes/recipes';
import { period as shoppingList } from '@/routes/shopping-list';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Inicio',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Menú',
        href: menu(),
        icon: CalendarDays,
    },
    {
        title: 'Lista de la compra',
        href: shoppingList(),
        icon: ShoppingCart,
    },
    {
        title: 'Inventario',
        href: inventory(),
        icon: Refrigerator,
    },
    {
        title: 'Recetas',
        href: recipes(),
        icon: BookOpen,
    },
];

const footerNavItems: NavItem[] = [];

// On mobile the sidebar is a sheet over the page: close it on any visit, otherwise the new page
// stays hidden behind it (the layout, and with it the sheet state, persists). «start» also
// covers a link to the current page, which does not fire «navigate».
const { setOpenMobile } = useSidebar();
const stopListening = router.on('start', () => setOpenMobile(false));
onBeforeUnmount(stopListening);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
