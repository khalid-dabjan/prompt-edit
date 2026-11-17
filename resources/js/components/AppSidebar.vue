<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChartColumn, Images, Sparkles } from '@lucide/vue';
import logo from '@/../images/prompt-edit-logo.png';
import CreditBalance from '@/components/CreditBalance.vue';
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
} from '@/components/ui/sidebar';
import { report as adminReport } from '@/routes/admin';
import {
    create as createGeneration,
    index as generationsIndex,
} from '@/routes/generations';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems: NavItem[] = [
    {
        title: 'Generate',
        href: createGeneration(),
        icon: Sparkles,
    },
    {
        title: 'History',
        href: generationsIndex(),
        icon: Images,
    },
    ...(page.props.auth.user.is_admin
        ? [{ title: 'Admin report', href: adminReport(), icon: ChartColumn }]
        : []),
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="createGeneration()">
                            <!-- Collapsed to icon width, only the lightning mark at the logo's left edge shows. -->
                            <div
                                class="h-7 overflow-hidden group-data-[collapsible=icon]:size-8"
                            >
                                <img
                                    :src="logo"
                                    alt="PromptEdit"
                                    class="h-full w-auto max-w-none dark:invert"
                                />
                            </div>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <CreditBalance />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
