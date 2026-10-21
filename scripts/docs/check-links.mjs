// torsade/scripts/docs/check-links.mjs
// Checks the links of the documentation, and its table of contents.
//
// - In README.md, CHANGELOG.md and documentation/, each relative link leads
//   to a file tracked by Git, and each anchor to a heading of that file. An
//   external link is never called: the application, and its documentation,
//   work without access to the internet.
// - Each Markdown file of documentation/ is linked from
//   documentation/README.md, so that no page is left out of reach.
//
// Usage: node scripts/docs/check-links.mjs [--root <folder>]
import path from 'node:path';
import {
    headingAnchors,
    markdownFiles,
    proseLines,
    readText,
    report,
    rootFromArguments,
    trackedFiles,
} from './markdown.mjs';

const TABLE_OF_CONTENTS = 'documentation/README.md';

const root = rootFromArguments(process.argv.slice(2));
const tracked = trackedFiles(root);
const files = markdownFiles(tracked, {
    files: ['README.md', 'CHANGELOG.md'],
    folders: ['documentation'],
});
const anchorsByFile = new Map();
const errors = [];
let checked = 0;

// Inline links and images, `[text](target "title")`, and reference
// definitions, `[name]: target`.
const INLINE_LINK =
    /!?\[(?:[^[\]]|\[[^\]]*\])*\]\(\s*(<[^>]*>|[^)\s]+)(?:\s+(?:"[^"]*"|'[^']*'))?\s*\)/g;
const REFERENCE = /^\s{0,3}\[[^\]]+\]:\s*(<[^>]*>|\S+)/;

function targetsOf(line) {
    const targets = [...line.matchAll(INLINE_LINK)].map((match) => match[1]);
    const reference = REFERENCE.exec(line);

    if (reference !== null) {
        targets.push(reference[1]);
    }

    return targets.map((target) => target.replace(/^<|>$/g, ''));
}

function isExternal(target) {
    return /^[a-z][a-z0-9+.-]*:/i.test(target) || target.startsWith('//');
}

function anchorsOf(file) {
    if (!anchorsByFile.has(file)) {
        anchorsByFile.set(file, headingAnchors(readText(root, file)));
    }

    return anchorsByFile.get(file);
}

// The tracked file, or folder holding tracked files, a link leads to, as a
// path relative to the root; null when it leads out of the root.
function resolve(from, linkPath) {
    if (linkPath === '') {
        return from;
    }

    const resolved = path.posix.normalize(
        path.posix.join(path.posix.dirname(from), decodeURI(linkPath)),
    );

    if (resolved === '..' || resolved.startsWith('../')) {
        return null;
    }

    return resolved.replace(/\/$/, '');
}

function existsInGit(resolved) {
    return (
        resolved === '.' ||
        tracked.has(resolved) ||
        [...tracked].some((file) => file.startsWith(`${resolved}/`))
    );
}

const linkedFromContents = new Set();

for (const file of files) {
    for (const { number, text } of proseLines(readText(root, file))) {
        for (const target of targetsOf(text)) {
            if (isExternal(target)) {
                continue;
            }

            checked += 1;

            const hash = target.indexOf('#');
            const linkPath = hash === -1 ? target : target.slice(0, hash);
            const anchor = hash === -1 ? null : target.slice(hash + 1);
            const resolved = resolve(file, linkPath);
            const where = `${file}:${number}`;

            if (resolved === null) {
                errors.push(
                    `${where}: link to "${target}" leads out of the repository.`,
                );

                continue;
            }

            if (!existsInGit(resolved)) {
                errors.push(
                    `${where}: link to "${target}": no file tracked by Git at ${resolved}.`,
                );

                continue;
            }

            if (file === TABLE_OF_CONTENTS) {
                linkedFromContents.add(resolved);
            }

            if (anchor === null) {
                continue;
            }

            if (!tracked.has(resolved) || !resolved.endsWith('.md')) {
                errors.push(
                    `${where}: link to "${target}": an anchor needs a Markdown file, ${resolved} is not one.`,
                );

                continue;
            }

            if (!anchorsOf(resolved).has(decodeURIComponent(anchor))) {
                errors.push(
                    `${where}: link to "${target}": no heading with the anchor "#${anchor}" in ${resolved}.`,
                );
            }
        }
    }
}

const pages = files.filter(
    (file) => file.startsWith('documentation/') && file !== TABLE_OF_CONTENTS,
);

if (pages.length > 0 && !tracked.has(TABLE_OF_CONTENTS)) {
    errors.push(`${TABLE_OF_CONTENTS}: the table of contents is missing.`);
} else {
    for (const page of pages) {
        if (!linkedFromContents.has(page)) {
            errors.push(
                `${page}:1: not linked from the table of contents, ${TABLE_OF_CONTENTS}.`,
            );
        }
    }
}

report(
    'Documentation links',
    errors,
    `${checked} relative links in ${files.length} files, every page in the table of contents.`,
);
