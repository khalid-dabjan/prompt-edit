<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Wallet } from '@lucide/vue';
import { computed } from 'vue';
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { formatUsd } from '@/lib/money';
import { index as credits } from '@/routes/credits';

const page = usePage();

const balance = computed(() =>
    formatUsd(page.props.balanceNanodollars ?? 0, 'down'),
);
</script>

<template>
    <SidebarGroup class="group-data-[collapsible=icon]:p-0">
        <SidebarGroupContent>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        as-child
                        :tooltip="`Credits: ${balance}`"
                    >
                        <Link :href="credits()">
                            <Wallet />
                            <span
                                class="flex flex-1 items-center justify-between"
                            >
                                Credits
                                <span class="font-medium tabular-nums">
                                    {{ balance }}
                                </span>
                            </span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarGroupContent>
    </SidebarGroup>
</template>
