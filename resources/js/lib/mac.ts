// torsade/resources/js/lib/mac.ts

// What may stand between the digits of a MAC address, in the notations
// people type: aa:bb:cc:dd:ee:ff, aa-bb-cc-dd-ee-ff, aabb.ccdd.eeff, or
// with spaces.
const SEPARATORS = /[:\-.\s]/g;

/**
 * A typed text read as a piece of MAC address: its hexadecimal digits in
 * lower case, once the separators are removed.
 *
 * Null when anything else is left: the text is then not a piece of MAC
 * address and must not be compared with one. "compta" is a host name, not
 * the digits "c" and "a".
 */
export function macDigits(text: string): string | null {
    const digits = text.toLowerCase().replace(SEPARATORS, '');

    return /^[0-9a-f]+$/.test(digits) ? digits : null;
}

/**
 * Whether a MAC address holds what was typed, whatever the case and the
 * separators on either side: "AA-BB", "aa:bb" and "aabb" all find
 * aa:bb:cc:dd:ee:ff.
 */
export function macContains(mac: string | null, typed: string): boolean {
    const wanted = macDigits(typed);
    const stored = mac === null ? null : macDigits(mac);

    return wanted !== null && stored !== null && stored.includes(wanted);
}
