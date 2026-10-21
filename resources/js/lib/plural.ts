// torsade/resources/js/lib/plural.ts

// Picks the form of a translation that agrees with a number, the way the
// PHP side does with trans_choice(): both read the same catalogue, so both
// must pick the same form.
//
// A translation gives its forms separated by "|". They are either plain,
// singular then plural ("line|lines"), or each one starts with the numbers
// it is for: "{0} none|{1} one|[2,*] several".

/**
 * The position of the plain form to use for a number, in a language: 0 for
 * the singular, 1 for the plural.
 *
 * French counts zero as a singular: "0 ligne", "1 ligne", "2 lignes".
 * English, the default here, only has "1 line".
 */
export function pluralIndex(locale: string, count: number): number {
    if (locale === 'fr') {
        return count === 0 || count === 1 ? 0 : 1;
    }

    return count === 1 ? 0 : 1;
}

// "{1} one" or "[2,*] several": the numbers a form is for, then the form.
const CONDITION = /^[{[]([^[\]{}]*)[}\]]([\s\S]*)$/;

// Whether a number is among those a form is for: "1" or "2,*".
const applies = (condition: string, count: number): boolean => {
    if (!condition.includes(',')) {
        return Number(condition) === count;
    }

    const [from, to] = condition.split(',', 2).map((bound) => bound.trim());

    return (
        (from === '*' || count >= Number(from)) &&
        (to === '*' || count <= Number(to))
    );
};

/**
 * The form of a message that agrees with a number.
 */
export function chooseForm(
    message: string,
    count: number,
    locale: string,
): string {
    const forms = message.split('|');

    // A form written for this very number wins.
    for (const form of forms) {
        const match = CONDITION.exec(form);

        if (match && applies(match[1], count)) {
            return match[2].trim();
        }
    }

    // Otherwise the plain forms, in the order singular, plural.
    const plain = forms.map((form) => form.replace(CONDITION, '$2').trim());

    if (plain.length === 1) {
        return plain[0];
    }

    return plain[pluralIndex(locale, count)] ?? plain[0];
}
