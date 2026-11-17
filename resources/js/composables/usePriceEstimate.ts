import { useHttp } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import type { Ref } from 'vue';
import { computed, ref, watch } from 'vue';
import { estimate as estimateRoute } from '@/routes/generations';
import type { ImageQuality, ImageSize, PriceEstimate } from '@/types';

export type EstimateInput = {
    prompt: string;
    size: ImageSize;
    quality: ImageQuality;
    referenceImageCount: number;
};

type EstimateRequest = {
    prompt: string;
    size: ImageSize;
    quality: ImageQuality;
    reference_image_count: number;
};

/**
 * Keeps a live price range for the generation being composed. The server does
 * the maths (the same code that quotes the price on submit), so the range
 * shown here is exactly the one stored with the generation.
 */
export function usePriceEstimate(input: () => EstimateInput): {
    estimate: Ref<PriceEstimate | null>;
    isEstimating: Ref<boolean>;
    isUnavailable: Ref<boolean>;
} {
    const http = useHttp<EstimateRequest, PriceEstimate>({
        prompt: '',
        size: '1024x1024',
        quality: 'medium',
        reference_image_count: 0,
    });

    const estimate = ref<PriceEstimate | null>(null);
    const isUnavailable = ref(false);
    const isWaiting = ref(false);

    const refresh = useDebounceFn(() => {
        const current = input();

        http.cancel();
        http.prompt = current.prompt;
        http.size = current.size;
        http.quality = current.quality;
        http.reference_image_count = current.referenceImageCount;

        void http
            .post(estimateRoute.url(), {
                onSuccess: (response) => {
                    estimate.value = response;
                    isUnavailable.value = false;
                },
                onHttpException: () => {
                    isUnavailable.value = true;

                    return false;
                },
                onNetworkError: () => {
                    isUnavailable.value = true;

                    return false;
                },
                onFinish: () => {
                    isWaiting.value = false;
                },
            })
            .catch(() => {
                // Handled by the callbacks above; a newer request may also have cancelled this one.
            });
    }, 300);

    watch(
        input,
        () => {
            isWaiting.value = true;
            void refresh();
        },
        { deep: true, immediate: true },
    );

    return {
        estimate,
        isEstimating: computed(() => isWaiting.value || http.processing),
        isUnavailable,
    };
}
