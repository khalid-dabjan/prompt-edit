export type ImageSize = '1024x1024' | '1536x1024' | '1024x1536';

export type ImageQuality = 'low' | 'medium' | 'high';

export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

/**
 * A price range in nanodollars (1 USD = 1,000,000,000).
 */
export type PriceEstimate = {
    min_charge_nanodollars: number;
    max_charge_nanodollars: number;
};

/**
 * A generation as GenerationResource sends it. Money is in nanodollars.
 */
export type Generation = {
    id: number;
    status: GenerationStatus;
    prompt: string;
    size: ImageSize;
    quality: ImageQuality;
    reference_image_count: number;
    estimated_min_charge_nanodollars: number;
    estimated_max_charge_nanodollars: number;
    charge_nanodollars: number | null;
    charge_waived: boolean;
    failure_message: string | null;
    image_url: string | null;
    image_expired: boolean;
    created_at: string;
    completed_at: string | null;
};

/**
 * Prefilled values for the Generate page, from an earlier generation.
 */
export type GenerationPrefill = {
    prompt: string;
    size: ImageSize;
    quality: ImageQuality;
    reference_images: string[];
};

/**
 * A Laravel paginated API resource collection.
 */
export type Paginated<T> = {
    data: T[];
    links: {
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
    };
};
