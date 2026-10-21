// torsade/resources/js/lib/i18n.ts
import fr from '../../../lang/fr.json';
import { chooseForm } from '@/lib/plural';

export type Replacements = Record<string, string | number>;

// One JSON catalogue per language, shared with PHP's __() helper: the key is
// the English source text. To add a language, add lang/<code>.json and
// register it here.
const catalogues: Record<string, Record<string, string>> = { fr };

const fallbackLocale = 'fr';

/**
 * The language of the page, set by the server on the <html> element. It is
 * read from the DOM so that translations work before any component is mounted.
 */
export function currentLocale(): string {
    if (typeof document === 'undefined') {
        return fallbackLocale;
    }

    return (document.documentElement.lang || fallbackLocale).split('-')[0];
}

/**
 * Translate an interface string. Placeholders are written ":name", as in the
 * PHP translation files.
 * A missing translation falls back to the key itself.
 */
export function trans(key: string, replacements: Replacements = {}): string {
    return replace(catalogues[currentLocale()]?.[key] ?? key, replacements);
}

/**
 * Translate an interface string that shows a number next to a noun: the
 * form that agrees with the number is picked, "1 ligne" or "2 lignes". The
 * key holds the forms of the source text, separated by "|", as for the PHP
 * trans_choice() helper.
 *
 * ":count" is replaced by the number, written the way the language of the
 * page writes numbers, unless another value is given for it.
 */
export function transChoice(
    key: string,
    count: number,
    replacements: Replacements = {},
): string {
    const locale = currentLocale();

    return replace(
        chooseForm(catalogues[locale]?.[key] ?? key, count, locale),
        {
            count: new Intl.NumberFormat(locale).format(count),
            ...replacements,
        },
    );
}

function replace(text: string, replacements: Replacements): string {
    for (const [name, value] of Object.entries(replacements)) {
        text = text.replaceAll(`:${name}`, String(value));
    }

    return text;
}
