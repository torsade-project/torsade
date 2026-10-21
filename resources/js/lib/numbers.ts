// torsade/resources/js/lib/numbers.ts
import { currentLocale } from '@/lib/i18n';

/**
 * A count written the way the language of the page writes numbers: a /8
 * holds "16 777 214" usable addresses in French.
 */
export function formatCount(value: number): string {
    return new Intl.NumberFormat(currentLocale()).format(value);
}

/**
 * A share of a whole as a percentage, with one decimal at most. A share too
 * small to round to anything is still shown as more than nothing.
 */
export function formatShare(part: number, whole: number): string {
    const share = whole > 0 ? part / whole : 0;

    const format = (value: number): string =>
        new Intl.NumberFormat(currentLocale(), {
            style: 'percent',
            maximumFractionDigits: 1,
        }).format(value);

    return share > 0 && share < 0.001 ? `< ${format(0.001)}` : format(share);
}
