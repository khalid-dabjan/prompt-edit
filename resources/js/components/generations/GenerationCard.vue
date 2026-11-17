<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { AlertTriangle, ImageOff, RotateCcw } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatUsd, formatUsdExact, formatUsdRange } from '@/lib/money';
import { cn } from '@/lib/utils';
import { create } from '@/routes/generations';
import type { Generation, GenerationStatus } from '@/types';

const props = defineProps<{
    generation: Generation;
}>();

const statusLabels: Record<GenerationStatus, string> = {
    pending: 'Starting',
    queued: 'Queued',
    processing: 'Generating',
    succeeded: 'Ready',
    failed: 'Failed',
};

const sizeLabels: Record<Generation['size'], string> = {
    '1024x1024': 'Square',
    '1536x1024': 'Landscape',
    '1024x1536': 'Portrait',
};

const imageFailedToLoad = ref(false);

watch(
    () => props.generation.image_url,
    () => (imageFailedToLoad.value = false),
);

const isUnfinished = computed(() =>
    ['pending', 'queued', 'processing'].includes(props.generation.status),
);

const showImage = computed(
    () => props.generation.image_url !== null && !imageFailedToLoad.value,
);

const showExpired = computed(
    () =>
        props.generation.status === 'succeeded' &&
        (props.generation.image_expired || imageFailedToLoad.value),
);

const progressLabel = computed(() => {
    switch (props.generation.status) {
        case 'pending':
            return 'Sending to Pixelforge…';
        case 'queued':
            return 'Waiting in queue…';
        default:
            return 'Generating your image…';
    }
});

const estimatedRange = computed(() =>
    formatUsdRange(
        props.generation.estimated_min_charge_nanodollars,
        props.generation.estimated_max_charge_nanodollars,
    ),
);

const createdAt = computed(() =>
    new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(props.generation.created_at)),
);
</script>

<template>
    <article
        class="flex flex-col overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border"
    >
        <div
            class="relative flex aspect-square items-center justify-center bg-muted"
        >
            <a
                v-if="showImage"
                :href="generation.image_url ?? undefined"
                target="_blank"
                rel="noopener"
                class="block size-full"
            >
                <img
                    :src="generation.image_url ?? undefined"
                    :alt="generation.prompt"
                    loading="lazy"
                    class="size-full object-contain"
                    @error="imageFailedToLoad = true"
                />
            </a>

            <div
                v-else-if="isUnfinished"
                class="flex flex-col items-center gap-3 text-sm text-muted-foreground"
                role="status"
            >
                <div
                    class="absolute inset-0 animate-pulse bg-linear-to-br from-muted via-background/40 to-muted"
                />
                <Spinner class="relative size-6" />
                <span class="relative">{{ progressLabel }}</span>
            </div>

            <div
                v-else-if="generation.status === 'failed'"
                class="flex flex-col items-center gap-2 p-6 text-center text-sm"
            >
                <AlertTriangle class="size-6 text-destructive" />
                <p class="text-muted-foreground">
                    {{ generation.failure_message }}
                </p>
            </div>

            <div
                v-else-if="showExpired"
                class="flex flex-col items-center gap-2 p-6 text-center text-sm text-muted-foreground"
            >
                <ImageOff class="size-6" />
                <p>This image is no longer available.</p>
            </div>
        </div>

        <div class="flex flex-1 flex-col gap-3 p-4">
            <p
                class="line-clamp-2 text-sm font-medium"
                :title="generation.prompt"
            >
                {{ generation.prompt }}
            </p>

            <div class="flex flex-wrap items-center gap-1.5">
                <Badge
                    :variant="
                        generation.status === 'failed'
                            ? 'destructive'
                            : generation.status === 'succeeded'
                              ? 'default'
                              : 'secondary'
                    "
                >
                    <Spinner v-if="isUnfinished" class="size-3" />
                    {{ statusLabels[generation.status] }}
                </Badge>
                <Badge variant="outline">{{
                    sizeLabels[generation.size]
                }}</Badge>
                <Badge variant="outline" class="capitalize">
                    {{ generation.quality }}
                </Badge>
                <Badge
                    v-if="generation.reference_image_count"
                    variant="outline"
                >
                    {{ generation.reference_image_count }} reference{{
                        generation.reference_image_count === 1 ? '' : 's'
                    }}
                </Badge>
            </div>

            <div
                class="mt-auto flex items-end justify-between gap-3 border-t pt-3 text-sm"
            >
                <div class="flex flex-col">
                    <template v-if="generation.status === 'succeeded'">
                        <span
                            v-if="generation.charge_waived"
                            class="font-medium"
                        >
                            Not charged
                        </span>
                        <TooltipProvider v-else>
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <span
                                        class="cursor-help font-medium tabular-nums underline decoration-dotted underline-offset-4"
                                    >
                                        {{
                                            formatUsd(
                                                generation.charge_nanodollars ??
                                                    0,
                                            )
                                        }}
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent>
                                    Exactly
                                    {{
                                        formatUsdExact(
                                            generation.charge_nanodollars ?? 0,
                                        )
                                    }}
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                        <span class="text-xs text-muted-foreground">
                            <template v-if="generation.charge_waived">
                                This one's on us
                            </template>
                            <template v-else>
                                Estimated {{ estimatedRange }}
                            </template>
                        </span>
                    </template>

                    <template v-else-if="generation.status === 'failed'">
                        <span class="font-medium">Not charged</span>
                        <span class="text-xs text-muted-foreground">
                            Failed generations are free
                        </span>
                    </template>

                    <template v-else>
                        <span class="font-medium tabular-nums">
                            {{ estimatedRange }}
                        </span>
                        <span class="text-xs text-muted-foreground">
                            Estimated, final when ready
                        </span>
                    </template>
                </div>

                <div class="flex flex-col items-end gap-1">
                    <Button
                        v-if="generation.status === 'failed'"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <Link
                            :href="create({ query: { from: generation.id } })"
                        >
                            <RotateCcw class="size-3.5" />
                            Try again
                        </Link>
                    </Button>
                    <time
                        :datetime="generation.created_at"
                        :class="
                            cn(
                                'text-xs text-muted-foreground',
                                generation.status === 'failed' && 'sr-only',
                            )
                        "
                    >
                        {{ createdAt }}
                    </time>
                </div>
            </div>
        </div>
    </article>
</template>
