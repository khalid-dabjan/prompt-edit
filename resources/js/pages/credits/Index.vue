<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Info, Plus } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatUsd, formatUsdExact } from '@/lib/money';
import { cn } from '@/lib/utils';
import { index, store } from '@/routes/credits';
import { index as history } from '@/routes/generations';
import type {
    CreditTransaction,
    CreditTransactionType,
    Paginated,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Credits', href: index() }],
    },
});

defineProps<{
    packsUsd: number[];
    transactions: Paginated<CreditTransaction>;
}>();

const page = usePage();

const balance = computed(() => page.props.balanceNanodollars ?? 0);

const transactionLabels: Record<CreditTransactionType, string> = {
    purchase: 'Credits added',
    hold: 'Reserved for a generation',
    hold_release: 'Reservation returned',
    charge: 'Generation charged',
};

function formatSigned(nanodollars: number): string {
    const amount = formatUsd(Math.abs(nanodollars));

    return nanodollars < 0 ? `−${amount}` : `+${amount}`;
}

function formatDate(iso: string): string {
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(iso));
}
</script>

<template>
    <Head title="Credits" />

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4">
        <Heading
            title="Credits"
            description="Credits are US dollars. Generations are paid from your balance."
        />

        <div class="grid gap-6 md:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardDescription>Available balance</CardDescription>
                    <CardTitle class="text-4xl tabular-nums">
                        {{ formatUsd(balance, 'down') }}
                    </CardTitle>
                </CardHeader>
                <CardContent class="flex gap-2 text-sm text-muted-foreground">
                    <Info class="mt-0.5 size-4 shrink-0" />
                    <p>
                        When you start a generation, its highest possible price
                        is reserved. Once it's ready, you pay the actual price
                        and the rest comes back. Failed generations are free.
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Buy credits</CardTitle>
                    <CardDescription>
                        This is a demo: no payment is taken.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="grid grid-cols-3 gap-2">
                        <Form
                            v-for="pack in packsUsd"
                            :key="pack"
                            v-bind="store.form()"
                            :options="{ preserveScroll: true }"
                            #default="{ processing }"
                        >
                            <input
                                type="hidden"
                                name="amount_usd"
                                :value="pack"
                            />
                            <Button
                                type="submit"
                                variant="outline"
                                class="h-16 w-full flex-col gap-0.5"
                                :disabled="processing"
                            >
                                <span
                                    class="flex items-center gap-1 text-lg font-semibold"
                                >
                                    <Plus class="size-4" />${{ pack }}
                                </span>
                            </Button>
                        </Form>
                    </div>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Activity</CardTitle>
            </CardHeader>
            <CardContent>
                <p
                    v-if="transactions.data.length === 0"
                    class="py-6 text-center text-sm text-muted-foreground"
                >
                    No activity yet. Buy credits to start generating.
                </p>

                <ul v-else class="divide-y">
                    <li
                        v-for="transaction in transactions.data"
                        :key="transaction.id"
                        class="flex items-center justify-between gap-4 py-3 text-sm"
                    >
                        <div class="flex min-w-0 flex-col">
                            <span class="font-medium">
                                {{ transactionLabels[transaction.type] }}
                            </span>
                            <Link
                                v-if="transaction.generation"
                                :href="history()"
                                class="truncate text-muted-foreground hover:underline"
                                :title="transaction.generation.prompt"
                            >
                                {{ transaction.generation.prompt }}
                            </Link>
                            <span class="text-xs text-muted-foreground">
                                {{ formatDate(transaction.created_at) }}
                            </span>
                        </div>
                        <span
                            :class="
                                cn(
                                    'shrink-0 font-medium tabular-nums',
                                    transaction.amount_nanodollars > 0 &&
                                        'text-emerald-600 dark:text-emerald-400',
                                )
                            "
                            :title="
                                formatUsdExact(
                                    Math.abs(transaction.amount_nanodollars),
                                )
                            "
                        >
                            {{ formatSigned(transaction.amount_nanodollars) }}
                        </span>
                    </li>
                </ul>

                <nav
                    v-if="transactions.meta.last_page > 1"
                    class="mt-4 flex justify-end gap-2"
                    aria-label="Pagination"
                >
                    <Button
                        v-if="transactions.links.prev"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <Link :href="transactions.links.prev" preserve-scroll>
                            <ChevronLeft class="size-4" />
                            Newer
                        </Link>
                    </Button>
                    <Button
                        v-if="transactions.links.next"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <Link :href="transactions.links.next" preserve-scroll>
                            Older
                            <ChevronRight class="size-4" />
                        </Link>
                    </Button>
                </nav>
            </CardContent>
        </Card>
    </div>
</template>
