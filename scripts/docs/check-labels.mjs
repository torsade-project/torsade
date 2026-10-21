// torsade/scripts/docs/check-labels.mjs
// Checks the labels of the interface quoted in the user guide.
//
// In documentation/guide/, bold text is kept for the labels of the
// interface, quoted as they read on screen: each text in bold, in a table
// or a link too, is a value of lang/fr.json. A value with parameters
// (`:count`, `:name`…) matches with any text in their place; a value with
// plural forms matches with each of its forms, without their number
// prefixes (`{0}`, `[2,*]`). Bold made with underscores is refused, so that
// every label goes through this check.
//
// Usage: node scripts/docs/check-labels.mjs [--root <folder>]
import {
    markdownFiles,
    proseLines,
    readText,
    report,
    rootFromArguments,
    trackedFiles,
} from './markdown.mjs';

const GUIDE = 'documentation/guide';
const TRANSLATIONS = 'lang/fr.json';

const root = rootFromArguments(process.argv.slice(2));
const tracked = trackedFiles(root);
const files = markdownFiles(tracked, { folders: [GUIDE] });
const errors = [];
let checked = 0;

const exact = new Set();
const patterns = [];
const PARAMETER = /:[A-Za-z_][A-Za-z0-9_]*/g;

for (const value of Object.values(JSON.parse(readText(root, TRANSLATIONS)))) {
    for (const form of String(value).split('|')) {
        const label = form.replace(/^\s*(\{[^}]*\}|\[[^\]]*\])\s*/, '');

        if (label.match(PARAMETER) === null) {
            exact.add(label);

            continue;
        }

        const source = label
            .split(PARAMETER)
            .map((part) => part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
            .join('.+');

        patterns.push(new RegExp(`^${source}$`, 'u'));
    }
}

function isLabel(text) {
    return exact.has(text) || patterns.some((pattern) => pattern.test(text));
}

// The text a reader sees: a link in bold reads as its text.
function visible(text) {
    return text.replace(/!?\[([^\]]*)\]\([^)]*\)/g, '$1');
}

const BOLD = /\*\*(?=\S)(.+?)(?<=\S)\*\*/g;
const UNDERSCORE_BOLD =
    /(^|[^\p{L}\p{N}_])__(?=\S)(.+?)(?<=\S)__(?![\p{L}\p{N}_])/gu;

for (const file of files) {
    for (const { number, text } of proseLines(readText(root, file))) {
        const where = `${file}:${number}`;

        for (const match of text.matchAll(BOLD)) {
            const label = visible(match[1]);

            checked += 1;

            if (!isLabel(label)) {
                errors.push(
                    `${where}: "${label}" is in bold but is not a label of ${TRANSLATIONS}.`,
                );
            }
        }

        if (text.replace(BOLD, '').includes('**')) {
            errors.push(
                `${where}: bold text opened and not closed on the line.`,
            );
        }

        for (const match of text.matchAll(UNDERSCORE_BOLD)) {
            errors.push(
                `${where}: "__${match[2]}__" is bold made with underscores: quote a label with **.`,
            );
        }
    }
}

report(
    'Labels of the guide',
    errors,
    `${checked} labels in bold in ${files.length} files, each one in ${TRANSLATIONS}.`,
);
