<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ImagePlus, Info, Sparkles, X } from '@lucide/vue';
import { computed } from 'vue';
import AlertError from '@/components/AlertError.vue';
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
import { Spinner } from '@/components/ui/spinner';
import { usePriceEstimate } from '@/composables/usePriceEstimate';
import { formatUsd, formatUsdRange } from '@/lib/money';
import { cn } from '@/lib/utils';
import { index as credits } from '@/routes/credits';
import { create, store } from '@/routes/generations';
import type { GenerationPrefill, ImageQuality, ImageSize } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generate', href: create() }],
    },
});

const props = defineProps<{
    markupPercent: number;
    prefill: GenerationPrefill | null;
}>();

const MAX_PROMPT_LENGTH = 4000;
const MAX_REFERENCE_IMAGES = 4;

// Bound from script: a literal "//" in a component attribute confuses vue-tsc 3.3.
const REFERENCE_IMAGE_PLACEHOLDER = 'https://example.com/reference.png';

const sizes: { value: ImageSize; label: string; shape: string }[] = [
    { value: '1024x1024', label: 'Square', shape: 'h-7 w-7' },
    { value: '1536x1024', label: 'Landscape', shape: 'h-6 w-9' },
    { value: '1024x1536', label: 'Portrait', shape: 'h-9 w-6' },
];

const qualities: { value: ImageQuality; label: string; level: number }[] = [
    { value: 'low', label: 'Low', level: 1 },
    { value: 'medium', label: 'Medium', level: 2 },
    { value: 'high', label: 'High', level: 3 },
];

const form = useForm({
    prompt: props.prefill?.prompt ?? '',
    size: props.prefill?.size ?? ('1024x1024' as ImageSize),
    quality: props.prefill?.quality ?? ('medium' as ImageQuality),
    reference_images: [...(props.prefill?.reference_images ?? [])] as string[],
});

const filledReferenceImages = computed(() =>
    form.reference_images.map((url) => url.trim()).filter((url) => url !== ''),
);

const { estimate, isEstimating, isUnavailable } = usePriceEstimate(() => ({
    prompt: form.prompt,
    size: form.size,
    quality: form.quality,
    referenceImageCount: filledReferenceImages.value.length,
}));

const priceRange = computed(() =>
    estimate.value
        ? formatUsdRange(
              estimate.value.min_charge_nanodollars,
              estimate.value.max_charge_nanodollars,
          )
        : null,
);

/**
 * Errors keyed by name, including ones that aren't form fields: the server's
 * `generation` error and per-item `reference_images.N` errors.
 */
const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

const referenceImageErrors = computed(() =>
    form.reference_images.map(
        (url, index) => errors.value[`reference_images.${index}`],
    ),
);

const page = usePage();

const balance = computed(() => page.props.balanceNanodollars ?? 0);

/**
 * The highest possible price is reserved on submit, so the balance must cover it.
 * The server enforces this too; this just says so before the user tries.
 */
const cannotAfford = computed(
    () =>
        estimate.value !== null &&
        balance.value < estimate.value.max_charge_nanodollars,
);

const canSubmit = computed(
    () => form.prompt.trim() !== '' && !form.processing && !cannotAfford.value,
);

function addReferenceImage(): void {
    if (form.reference_images.length < MAX_REFERENCE_IMAGES) {
        form.reference_images.push('');
    }
}

function removeReferenceImage(index: number): void {
    form.reference_images.splice(index, 1);
    form.clearErrors();
}

function submit(): void {
    if (!canSubmit.value) {
        return;
    }

    form.transform((data) => ({
        ...data,
        reference_images: filledReferenceImages.value,
    })).submit(store(), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Generate" />

    <div
        class="mx-auto grid w-full max-w-6xl gap-6 p-4 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start"
    >
        <form
            id="generate-form"
            class="flex flex-col gap-6"
            @submit.prevent="submit"
        >
            <AlertError
                v-if="errors.generation"
                title="Couldn't start the generation"
                :errors="[errors.generation]"
            />

            <Card>
                <CardHeader>
                    <CardTitle>
                        <Label for="prompt" class="text-base">
                            Describe your image
                        </Label>
                    </CardTitle>
                    <CardDescription>
                        Be specific about the subject, style and lighting.
                    </CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-2">
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        v-focus
                        rows="5"
                        :maxlength="MAX_PROMPT_LENGTH"
                        :aria-invalid="!!form.errors.prompt"
                        placeholder="A lighthouse on a cliff at dusk, oil painting, warm light…"
                        class="min-h-32 w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm dark:bg-input/30"
                        @keydown.meta.enter.prevent="submit"
                        @keydown.ctrl.enter.prevent="submit"
                    />
                    <div
                        class="flex items-center justify-between gap-4 text-xs text-muted-foreground"
                    >
                        <InputError :message="form.errors.prompt" />
                        <span class="ml-auto tabular-nums">
                            {{ form.prompt.length.toLocaleString() }} /
                            {{ MAX_PROMPT_LENGTH.toLocaleString() }}
                        </span>
                    </div>
                </CardContent>
            </Card>

            <div class="grid gap-6 md:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Size</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div
                            role="radiogroup"
                            aria-label="Size"
                            class="grid grid-cols-3 gap-2"
                        >
                            <button
                                v-for="size in sizes"
                                :key="size.value"
                                type="button"
                                role="radio"
                                :aria-checked="form.size === size.value"
                                :class="
                                    cn(
                                        'flex flex-col items-center gap-2 rounded-lg border p-3 text-sm transition-colors hover:bg-accent',
                                        form.size === size.value
                                            ? 'border-primary bg-accent ring-1 ring-primary'
                                            : 'border-border',
                                    )
                                "
                                @click="form.size = size.value"
                            >
                                <span
                                    class="flex h-10 items-center justify-center"
                                >
                                    <span
                                        :class="
                                            cn(
                                                'rounded-sm border-2 border-current opacity-70',
                                                size.shape,
                                            )
                                        "
                                    />
                                </span>
                                <span class="font-medium">{{
                                    size.label
                                }}</span>
                                <span
                                    class="text-xs text-muted-foreground tabular-nums"
                                >
                                    {{ size.value.replace('x', '×') }}
                                </span>
                            </button>
                        </div>
                        <InputError class="mt-2" :message="form.errors.size" />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Quality</CardTitle>
                        <CardDescription>
                            Higher quality uses more tokens, so it costs more.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div
                            role="radiogroup"
                            aria-label="Quality"
                            class="grid grid-cols-3 gap-2"
                        >
                            <button
                                v-for="quality in qualities"
                                :key="quality.value"
                                type="button"
                                role="radio"
                                :aria-checked="form.quality === quality.value"
                                :class="
                                    cn(
                                        'flex flex-col items-center gap-2 rounded-lg border p-3 text-sm transition-colors hover:bg-accent',
                                        form.quality === quality.value
                                            ? 'border-primary bg-accent ring-1 ring-primary'
                                            : 'border-border',
                                    )
                                "
                                @click="form.quality = quality.value"
                            >
                                <span
                                    class="flex h-10 items-center gap-1"
                                    aria-hidden="true"
                                >
                                    <span
                                        v-for="bar in 3"
                                        :key="bar"
                                        :class="
                                            cn(
                                                'w-1.5 rounded-full',
                                                bar <= quality.level
                                                    ? 'bg-foreground/70'
                                                    : 'bg-foreground/15',
                                            )
                                        "
                                        :style="{ height: bar * 8 + 4 + 'px' }"
                                    />
                                </span>
                                <span class="font-medium">{{
                                    quality.label
                                }}</span>
                            </button>
                        </div>
                        <InputError
                            class="mt-2"
                            :message="form.errors.quality"
                        />
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">
                        Reference images
                        <span class="font-normal text-muted-foreground">
                            (optional)
                        </span>
                    </CardTitle>
                    <CardDescription>
                        Up to {{ MAX_REFERENCE_IMAGES }} image URLs to guide the
                        result. Each one adds to the cost.
                    </CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-3">
                    <div
                        v-for="(referenceImage, index) in form.reference_images"
                        :key="index"
                        class="flex flex-col gap-1"
                    >
                        <div class="flex items-center gap-2">
                            <Input
                                v-model="form.reference_images[index]"
                                type="url"
                                inputmode="url"
                                :placeholder="REFERENCE_IMAGE_PLACEHOLDER"
                                :aria-label="`Reference image ${index + 1}`"
                                :aria-invalid="!!referenceImageErrors[index]"
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :aria-label="`Remove reference image ${index + 1}`"
                                @click="removeReferenceImage(index)"
                            >
                                <X class="size-4" />
                            </Button>
                        </div>
                        <InputError :message="referenceImageErrors[index]" />
                    </div>

                    <InputError :message="form.errors.reference_images" />

                    <Button
                        v-if="
                            form.reference_images.length < MAX_REFERENCE_IMAGES
                        "
                        type="button"
                        variant="outline"
                        class="self-start"
                        @click="addReferenceImage"
                    >
                        <ImagePlus class="size-4" />
                        Add reference image
                    </Button>
                </CardContent>
            </Card>
        </form>

        <aside class="lg:sticky lg:top-4">
            <Card>
                <CardHeader>
                    <CardDescription>Estimated cost</CardDescription>
                    <CardTitle
                        class="flex min-h-9 items-center gap-2 text-3xl tabular-nums"
                        aria-live="polite"
                    >
                        <template v-if="priceRange">{{ priceRange }}</template>
                        <span
                            v-else-if="isUnavailable"
                            class="text-base font-normal text-muted-foreground"
                        >
                            Prices are unavailable right now
                        </span>
                        <span
                            v-else
                            class="h-8 w-40 animate-pulse rounded-md bg-muted"
                        />
                        <Spinner
                            v-if="isEstimating && priceRange"
                            class="size-4 text-muted-foreground"
                        />
                    </CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <ul
                        class="flex flex-col gap-2 text-sm text-muted-foreground"
                    >
                        <li class="flex gap-2">
                            <Info class="mt-0.5 size-4 shrink-0" />
                            <span>
                                You pay for the tokens the image uses, plus our
                                {{ props.markupPercent }}% fee. The exact price
                                is set when the image is ready and will be
                                within this range.
                            </span>
                        </li>
                        <li class="flex gap-2">
                            <Info class="mt-0.5 size-4 shrink-0" />
                            <span>If the generation fails, it's free.</span>
                        </li>
                    </ul>

                    <div
                        class="flex items-center justify-between border-t pt-4 text-sm"
                    >
                        <span class="text-muted-foreground">Your balance</span>
                        <Link
                            :href="credits()"
                            class="font-medium tabular-nums hover:underline"
                        >
                            {{ formatUsd(balance, 'down') }}
                        </Link>
                    </div>

                    <p
                        v-if="cannotAfford && estimate"
                        class="rounded-md border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-700 dark:text-red-300"
                        role="alert"
                    >
                        This can cost up to
                        {{ formatUsd(estimate.max_charge_nanodollars, 'up') }},
                        more than your balance.
                        <Link :href="credits()" class="font-medium underline">
                            Buy credits
                        </Link>
                        to continue.
                    </p>

                    <Button
                        type="submit"
                        form="generate-form"
                        size="lg"
                        class="w-full"
                        :disabled="!canSubmit"
                    >
                        <Spinner v-if="form.processing" />
                        <Sparkles v-else class="size-4" />
                        Generate
                    </Button>
                    <p class="text-center text-xs text-muted-foreground">
                        or press ⌘/Ctrl + Enter
                    </p>
                </CardContent>
            </Card>
        </aside>
    </div>
</template>
