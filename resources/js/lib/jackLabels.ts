// torsade/resources/js/lib/jackLabels.ts

// How the jacks of a series are labelled: a prefix, a number written with
// at least so many digits, and a suffix. "B." and "", from 1 to 24 on two
// digits, give "B.01" to "B.24". The server works the same labels out, and
// both are checked against the same table of cases.

// How many jacks one series makes at most.
export const MAX_QUANTITY = 200;

// The most digits a number is written with.
export const MAX_DIGITS = 6;

// The highest number a series reaches.
export const MAX_NUMBER = 99999;

// One label: the number with at least so many digits, zeros in front.
export function jackLabel(
    prefix: string,
    suffix: string,
    number: number,
    digits: number,
): string {
    return `${prefix}${String(number).padStart(digits, '0')}${suffix}`;
}

// The labels from a first number to a last one, in their order; none when
// the last one comes before the first.
export function jackLabels(
    prefix: string,
    suffix: string,
    first: number,
    last: number,
    digits: number,
): string[] {
    const labels: string[] = [];

    for (let number = first; number <= last; number++) {
        labels.push(jackLabel(prefix, suffix, number, digits));
    }

    return labels;
}
