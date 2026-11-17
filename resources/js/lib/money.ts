/**
 * Money arrives from the server as integer nanodollars (1 USD = 1,000,000,000),
 * the same precision Pixelforge bills in, so it is never a float until display.
 */
const NANODOLLARS_PER_DOLLAR = 1_000_000_000;
const NANODOLLARS_PER_CENT = 10_000_000;
const NANODOLLARS_PER_HUNDREDTH_OF_A_CENT = 100_000;

export type Rounding = 'down' | 'up' | 'nearest';

function roundTo(
    nanodollars: number,
    unit: number,
    rounding: Rounding,
): number {
    const units = nanodollars / unit;

    switch (rounding) {
        case 'down':
            return Math.floor(units);
        case 'up':
            return Math.ceil(units);
        default:
            return Math.round(units);
    }
}

/**
 * A short, readable price: cents from one cent up ("$0.38"), and four decimals
 * below that ("$0.0042") so sub-cent prices never show as "$0.00".
 */
export function formatUsd(
    nanodollars: number,
    rounding: Rounding = 'nearest',
): string {
    if (nanodollars === 0) {
        return '$0.00';
    }

    if (nanodollars >= NANODOLLARS_PER_CENT) {
        const cents = roundTo(nanodollars, NANODOLLARS_PER_CENT, rounding);

        return `$${(cents / 100).toFixed(2)}`;
    }

    const hundredthsOfACent = roundTo(
        nanodollars,
        NANODOLLARS_PER_HUNDREDTH_OF_A_CENT,
        rounding,
    );

    return hundredthsOfACent === 0
        ? '< $0.0001'
        : `$${(hundredthsOfACent / 10_000).toFixed(4)}`;
}

/**
 * A price range rounded outward (low end down, high end up), so the range
 * shown always contains the real price.
 */
export function formatUsdRange(
    minNanodollars: number,
    maxNanodollars: number,
): string {
    const min = formatUsd(minNanodollars, 'down');
    const max = formatUsd(maxNanodollars, 'up');

    return min === max ? min : `${min} – ${max}`;
}

/**
 * The exact amount, to the nanodollar, with no rounding (e.g. "$0.381887500").
 */
export function formatUsdExact(nanodollars: number): string {
    const dollars = Math.floor(nanodollars / NANODOLLARS_PER_DOLLAR);
    const fraction = String(nanodollars % NANODOLLARS_PER_DOLLAR).padStart(
        9,
        '0',
    );

    return `$${dollars}.${fraction}`;
}
