<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, ImageIcon, Sparkles } from '@lucide/vue';
import { watch } from 'vue';
import GenerationCard from '@/components/generations/GenerationCard.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { create, index } from '@/routes/generations';
import type { Generation, Paginated } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'History', href: index() }],
    },
});

const props = defineProps<{
    generations: Paginated<Generation>;
    hasUnfinished: boolean;
}>();

// Status changes arrive from webhooks and polling on the server; while any
// generation is unfinished, refresh this page's data every few seconds.
const { start, stop } = usePoll(
    2500,
    { only: ['generations', 'hasUnfinished', 'balanceNanodollars'] },
    { autoStart: false },
);

watch(
    () => props.hasUnfinished,
    (hasUnfinished) => (hasUnfinished ? start() : stop()),
    { immediate: true },
);
</script>

<template>
    <Head title="History" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="History"
                description="Every image you've generated, with what it cost."
            />
            <Button as-child>
                <Link :href="create()">
                    <Sparkles class="size-4" />
                    New generation
                </Link>
            </Button>
        </div>

        <div
            v-if="generations.data.length === 0"
            class="flex flex-col items-center gap-4 rounded-xl border border-dashed border-sidebar-border/70 p-12 text-center dark:border-sidebar-border"
        >
            <ImageIcon class="size-8 text-muted-foreground" />
            <div class="flex flex-col gap-1">
                <p class="font-medium">No generations yet</p>
                <p class="text-sm text-muted-foreground">
                    Describe an image and see what it costs before you start.
                </p>
            </div>
            <Button as-child>
                <Link :href="create()">Create your first image</Link>
            </Button>
        </div>

        <template v-else>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <GenerationCard
                    v-for="generation in generations.data"
                    :key="generation.id"
                    :generation="generation"
                />
            </div>

            <nav
                v-if="generations.meta.last_page > 1"
                class="flex items-center justify-between gap-4 text-sm text-muted-foreground"
                aria-label="Pagination"
            >
                <span>
                    {{ generations.meta.from }}–{{ generations.meta.to }} of
                    {{ generations.meta.total }}
                </span>
                <div class="flex gap-2">
                    <Button
                        v-if="generations.links.prev"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <Link :href="generations.links.prev" preserve-scroll>
                            <ChevronLeft class="size-4" />
                            Newer
                        </Link>
                    </Button>
                    <Button v-else variant="outline" size="sm" disabled>
                        <ChevronLeft class="size-4" />
                        Newer
                    </Button>

                    <Button
                        v-if="generations.links.next"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <Link :href="generations.links.next" preserve-scroll>
                            Older
                            <ChevronRight class="size-4" />
                        </Link>
                    </Button>
                    <Button v-else variant="outline" size="sm" disabled>
                        Older
                        <ChevronRight class="size-4" />
                    </Button>
                </div>
            </nav>
        </template>
    </div>
</template>
