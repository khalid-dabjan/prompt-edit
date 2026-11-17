<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2 } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatUsd, formatUsdExact } from '@/lib/money';
import { cn } from '@/lib/utils';
import { report as reportRoute } from '@/routes/admin';
import type { AdminReport } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Admin report', href: reportRoute() }],
    },
});

const props = defineProps<{
    startDate: string;
    endDate: string;
    report: AdminReport;
}>();

/**
 * Days with no activity on either side are hidden to keep the table readable.
 */
const activeDays = computed(() =>
    props.report.days.filter(
        (day) =>
            day.generations > 0 ||
            (day.pixelforge_generations ?? 0) > 0 ||
            (day.difference_nanodollars ?? 0) !== 0,
    ),
);

const mismatchedDays = computed(
    () =>
        props.report.days.filter(
            (day) => (day.difference_nanodollars ?? 0) !== 0,
        ).length,
);

function formatSignedExact(nanodollars: number): string {
    return nanodollars < 0
        ? `−${formatUsdExact(-nanodollars)}`
        : formatUsdExact(nanodollars);
}
</script>

<template>
    <Head title="Admin report" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <Heading
                title="Admin report"
                description="What users were charged, what Pixelforge charged us, and the profit. Days are UTC, by when generations completed, as Pixelforge bills them."
            />

            <Form
                v-bind="reportRoute.form()"
                class="flex flex-wrap items-end gap-2"
                #default="{ errors, processing }"
            >
                <div class="grid gap-1">
                    <Label for="start_date">From</Label>
                    <Input
                        id="start_date"
                        type="date"
                        name="start_date"
                        :default-value="startDate"
                    />
                </div>
                <div class="grid gap-1">
                    <Label for="end_date">To</Label>
                    <Input
                        id="end_date"
                        type="date"
                        name="end_date"
                        :default-value="endDate"
                    />
                </div>
                <Button type="submit" variant="outline" :disabled="processing">
                    Apply
                </Button>
                <InputError
                    class="basis-full"
                    :message="errors.start_date ?? errors.end_date"
                />
            </Form>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Card>
                <CardHeader>
                    <CardDescription>Credit spend</CardDescription>
                    <CardTitle
                        class="text-2xl tabular-nums"
                        :title="
                            formatUsdExact(
                                report.totals.credit_spend_nanodollars,
                            )
                        "
                    >
                        {{ formatUsd(report.totals.credit_spend_nanodollars) }}
                    </CardTitle>
                </CardHeader>
                <CardContent class="text-sm text-muted-foreground">
                    {{ report.totals.generations }} billed generations
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>Provider cost</CardDescription>
                    <CardTitle
                        class="text-2xl tabular-nums"
                        :title="
                            formatUsdExact(
                                report.totals.provider_cost_nanodollars,
                            )
                        "
                    >
                        {{ formatUsd(report.totals.provider_cost_nanodollars) }}
                    </CardTitle>
                </CardHeader>
                <CardContent class="text-sm text-muted-foreground">
                    From our records
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>Profit</CardDescription>
                    <CardTitle
                        class="text-2xl tabular-nums"
                        :title="
                            formatSignedExact(report.totals.profit_nanodollars)
                        "
                    >
                        {{
                            report.totals.profit_nanodollars < 0
                                ? `−${formatUsd(-report.totals.profit_nanodollars)}`
                                : formatUsd(report.totals.profit_nanodollars)
                        }}
                    </CardTitle>
                </CardHeader>
                <CardContent class="text-sm text-muted-foreground">
                    Credit spend − provider cost
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>Matches Pixelforge?</CardDescription>
                    <CardTitle class="flex items-center gap-2 text-2xl">
                        <template v-if="!report.pixelforge_available">
                            <span class="text-base text-muted-foreground">
                                Pixelforge unavailable
                            </span>
                        </template>
                        <template v-else-if="mismatchedDays === 0">
                            <CheckCircle2
                                class="size-6 text-emerald-600 dark:text-emerald-400"
                            />
                            Yes
                        </template>
                        <template v-else>
                            <AlertTriangle class="size-6 text-destructive" />
                            {{ mismatchedDays }} day{{
                                mismatchedDays === 1 ? '' : 's'
                            }}
                            off
                        </template>
                    </CardTitle>
                </CardHeader>
                <CardContent class="text-sm text-muted-foreground">
                    <template
                        v-if="
                            report.totals.pixelforge_cost_nanodollars !== null
                        "
                    >
                        Pixelforge billed
                        {{
                            formatUsdExact(
                                report.totals.pixelforge_cost_nanodollars,
                            )
                        }}
                    </template>
                    <template v-else>Checked against GET /v1/usage</template>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Per day</CardTitle>
                <CardDescription>
                    Exact amounts. Days with no activity are hidden.
                </CardDescription>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <p
                    v-if="activeDays.length === 0"
                    class="py-6 text-center text-sm text-muted-foreground"
                >
                    No billed generations in this period.
                </p>
                <table v-else class="w-full text-sm tabular-nums">
                    <thead class="text-left text-muted-foreground">
                        <tr class="border-b">
                            <th class="py-2 pr-4 font-medium">Day (UTC)</th>
                            <th class="py-2 pr-4 text-right font-medium">
                                Generations
                            </th>
                            <th class="py-2 pr-4 text-right font-medium">
                                Credit spend
                            </th>
                            <th class="py-2 pr-4 text-right font-medium">
                                Provider cost (ours)
                            </th>
                            <th class="py-2 pr-4 text-right font-medium">
                                Pixelforge billed
                            </th>
                            <th class="py-2 pr-4 font-medium">Reconciled</th>
                            <th class="py-2 text-right font-medium">Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="day in activeDays"
                            :key="day.date"
                            class="border-b last:border-0"
                        >
                            <td class="py-2 pr-4">{{ day.date }}</td>
                            <td class="py-2 pr-4 text-right">
                                {{ day.generations }}
                                <span
                                    v-if="
                                        day.pixelforge_generations !== null &&
                                        day.pixelforge_generations !==
                                            day.generations
                                    "
                                    class="text-destructive"
                                >
                                    ({{ day.pixelforge_generations }})
                                </span>
                            </td>
                            <td class="py-2 pr-4 text-right">
                                {{
                                    formatUsdExact(day.credit_spend_nanodollars)
                                }}
                            </td>
                            <td class="py-2 pr-4 text-right">
                                {{
                                    formatUsdExact(
                                        day.provider_cost_nanodollars,
                                    )
                                }}
                            </td>
                            <td class="py-2 pr-4 text-right">
                                {{
                                    day.pixelforge_cost_nanodollars === null
                                        ? '—'
                                        : formatUsdExact(
                                              day.pixelforge_cost_nanodollars,
                                          )
                                }}
                            </td>
                            <td class="py-2 pr-4">
                                <span
                                    v-if="day.difference_nanodollars === null"
                                    class="text-muted-foreground"
                                >
                                    —
                                </span>
                                <span
                                    v-else-if="day.difference_nanodollars === 0"
                                    class="text-emerald-600 dark:text-emerald-400"
                                >
                                    ✓ Matches
                                </span>
                                <span v-else class="text-destructive">
                                    Off by
                                    {{
                                        formatSignedExact(
                                            day.difference_nanodollars,
                                        )
                                    }}
                                </span>
                            </td>
                            <td
                                :class="
                                    cn(
                                        'py-2 text-right',
                                        day.profit_nanodollars < 0 &&
                                            'text-destructive',
                                    )
                                "
                            >
                                {{ formatSignedExact(day.profit_nanodollars) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Per user</CardTitle>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <p
                    v-if="report.users.length === 0"
                    class="py-6 text-center text-sm text-muted-foreground"
                >
                    No billed generations in this period.
                </p>
                <table v-else class="w-full text-sm tabular-nums">
                    <thead class="text-left text-muted-foreground">
                        <tr class="border-b">
                            <th class="py-2 pr-4 font-medium">User</th>
                            <th class="py-2 pr-4 text-right font-medium">
                                Generations
                            </th>
                            <th class="py-2 pr-4 text-right font-medium">
                                Credit spend
                            </th>
                            <th class="py-2 pr-4 text-right font-medium">
                                Provider cost
                            </th>
                            <th class="py-2 text-right font-medium">Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="user in report.users"
                            :key="user.user_id ?? 'deleted'"
                            class="border-b last:border-0"
                        >
                            <td class="py-2 pr-4">
                                <template v-if="user.user_id !== null">
                                    <div class="font-medium">
                                        {{ user.name }}
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ user.email }}
                                    </div>
                                </template>
                                <span v-else class="text-muted-foreground">
                                    Deleted accounts
                                </span>
                            </td>
                            <td class="py-2 pr-4 text-right">
                                {{ user.generations }}
                            </td>
                            <td class="py-2 pr-4 text-right">
                                {{
                                    formatUsdExact(
                                        user.credit_spend_nanodollars,
                                    )
                                }}
                            </td>
                            <td class="py-2 pr-4 text-right">
                                {{
                                    formatUsdExact(
                                        user.provider_cost_nanodollars,
                                    )
                                }}
                            </td>
                            <td
                                :class="
                                    cn(
                                        'py-2 text-right',
                                        user.profit_nanodollars < 0 &&
                                            'text-destructive',
                                    )
                                "
                            >
                                {{ formatSignedExact(user.profit_nanodollars) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>
    </div>
</template>
