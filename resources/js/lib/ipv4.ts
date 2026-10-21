// torsade/resources/js/lib/ipv4.ts
import type { FreeInterval } from '@/types';

// Conveniences for the interface only: sorting a column, proposing the next
// free address, telling what a block being typed would hold. Every rule of
// the addressing plan is checked by the server, whatever these functions say.

/**
 * The address as a number, or null when it is not a dotted-decimal IPv4
 * address.
 */
export function ipv4ToNumber(address: string): number | null {
    const parts = address.split('.');

    if (parts.length !== 4) {
        return null;
    }

    let value = 0;

    for (const part of parts) {
        if (!/^(0|[1-9]\d{0,2})$/.test(part) || Number(part) > 255) {
            return null;
        }

        // Multiplication, not bit shifts: JavaScript shifts work on signed
        // 32-bit integers and would turn the upper half negative.
        value = value * 256 + Number(part);
    }

    return value;
}

export function numberToIpv4(value: number): string {
    return [
        Math.floor(value / 16777216) % 256,
        Math.floor(value / 65536) % 256,
        Math.floor(value / 256) % 256,
        value % 256,
    ].join('.');
}

/**
 * Numeric order of two addresses; anything that is not an address goes last.
 */
export function compareIpv4(a: string, b: string): number {
    const first = ipv4ToNumber(a) ?? Number.MAX_SAFE_INTEGER;
    const second = ipv4ToNumber(b) ?? Number.MAX_SAFE_INTEGER;

    return first - second;
}

// The addresses a prefix covers, first and last, as numbers.
export type PrefixRange = { start: number; end: number };

/**
 * What a prefix covers, or null when it is not one: the address must be the
 * network address of a prefix of that length, as the server requires.
 */
export function prefixRange(
    network: string,
    prefixLength: number,
): PrefixRange | null {
    const start = ipv4ToNumber(network);

    if (
        start === null ||
        !Number.isInteger(prefixLength) ||
        prefixLength < 1 ||
        prefixLength > 32
    ) {
        return null;
    }

    const size = 2 ** (32 - prefixLength);

    return start % size === 0 ? { start, end: start + size - 1 } : null;
}

/**
 * Whether a prefix lies in another one. Two prefixes are either apart or
 * one inside the other: comparing their bounds is enough.
 */
export function rangeHolds(outer: PrefixRange, inner: PrefixRange): boolean {
    return outer.start <= inner.start && inner.end <= outer.end;
}

/**
 * The free address to propose next that is not already taken by a line
 * being typed: the lowest one of a static range and, failing that, the
 * lowest one outside of every range. An address of a reserved range is
 * never proposed. The search stops as soon as an address is found: it never
 * walks through a whole interval.
 */
export function nextFreeAddress(
    intervals: FreeInterval[],
    taken: ReadonlySet<string> = new Set(),
): string | null {
    const candidates = [
        ...intervals.filter((interval) => interval.kind === 'static'),
        ...intervals.filter((interval) => interval.kind === null),
    ];

    for (const interval of candidates) {
        const start = ipv4ToNumber(interval.start);

        if (start === null) {
            continue;
        }

        // At most one step per taken address, plus one.
        const steps = Math.min(interval.count, taken.size + 1);

        for (let offset = 0; offset < steps; offset++) {
            const candidate = numberToIpv4(start + offset);

            if (!taken.has(candidate)) {
                return candidate;
            }
        }
    }

    return null;
}
