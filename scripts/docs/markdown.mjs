// torsade/scripts/docs/markdown.mjs
// Reading of the Markdown files of the documentation, shared by its checks.
// Plain Node, no dependency: the checks run before anything is installed.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// The root of the repository, two levels above this folder.
export const repositoryRoot = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    '..',
    '..',
);

// The folder to check: `--root <dir>` on the command line, the repository
// otherwise. The examples of the checks are folders of their own.
export function rootFromArguments(argv) {
    const index = argv.indexOf('--root');

    if (index === -1) {
        return repositoryRoot;
    }

    if (argv[index + 1] === undefined) {
        throw new Error('--root needs a folder.');
    }

    return path.resolve(argv[index + 1]);
}

// The files Git tracks under the root, as paths relative to it with forward
// slashes: a link to a file left out of Git would be dead for everyone else.
export function trackedFiles(root) {
    const output = execFileSync('git', ['ls-files', '-z'], {
        cwd: root,
        encoding: 'utf8',
    });

    return new Set(output.split('\0').filter((file) => file !== ''));
}

// The tracked Markdown files of the folders given, and of the single files
// given, relative to the root.
export function markdownFiles(tracked, { files = [], folders = [] }) {
    return [...tracked]
        .filter(
            (file) =>
                files.includes(file) ||
                (file.endsWith('.md') &&
                    folders.some((folder) => file.startsWith(`${folder}/`))),
        )
        .sort((a, b) => a.localeCompare(b));
}

export function readText(root, file) {
    return readFileSync(path.join(root, file), 'utf8');
}

// The lines of a Markdown text, numbered from 1, with what is code blanked
// out: fenced blocks entirely, and inline code spans replaced by spaces of
// the same length. Neither a link nor bold text counts inside code.
export function proseLines(text) {
    const lines = text.split(/\r?\n/);
    let fence = null;

    return lines.map((line, index) => {
        const opening = /^\s{0,3}(`{3,}|~{3,})/.exec(line);

        if (fence !== null) {
            if (
                opening !== null &&
                opening[1][0] === fence[0] &&
                opening[1].length >= fence.length &&
                line.trim() === opening[1]
            ) {
                fence = null;
            }

            return { number: index + 1, text: '' };
        }

        if (opening !== null) {
            fence = opening[1];

            return { number: index + 1, text: '' };
        }

        return { number: index + 1, text: blankInlineCode(line) };
    });
}

function blankInlineCode(line) {
    return line.replace(/(`+)(.+?)\1/g, (span) => ' '.repeat(span.length));
}

// The anchors of the headings of a Markdown text, as GitHub makes them:
// lower case, letters, digits, spaces turned into hyphens, `-` and `_`
// kept, the rest dropped; a repeated anchor takes -1, -2…
export function headingAnchors(text) {
    const anchors = new Set();
    const seen = new Map();
    let fence = null;

    for (const line of text.split(/\r?\n/)) {
        const opening = /^\s{0,3}(`{3,}|~{3,})/.exec(line);

        if (fence !== null) {
            if (opening !== null && line.trim() === opening[1]) {
                fence = null;
            }

            continue;
        }

        if (opening !== null) {
            fence = opening[1];

            continue;
        }

        const heading = /^\s{0,3}#{1,6}\s+(.*?)(?:\s+#+)?\s*$/.exec(line);

        if (heading === null) {
            continue;
        }

        const base = slug(heading[1]);
        const count = seen.get(base) ?? 0;

        seen.set(base, count + 1);
        anchors.add(count === 0 ? base : `${base}-${count}`);
    }

    return anchors;
}

export function slug(heading) {
    return heading
        .replace(/!?\[([^\]]*)\]\([^)]*\)/g, '$1')
        .replace(/<[^>]+>/g, '')
        .replace(/[`*]/g, '')
        .trim()
        .toLowerCase()
        .replace(/[^\p{L}\p{M}\p{N}\p{Pc} -]/gu, '')
        .replace(/ /g, '-');
}

// Prints the errors and sets the exit code: 0 when there is none.
export function report(title, errors, summary) {
    if (errors.length === 0) {
        console.log(`${title}: ${summary}`);

        return;
    }

    for (const error of errors) {
        console.error(error);
    }

    console.error(
        `${title}: ${errors.length} ${errors.length === 1 ? 'error' : 'errors'}.`,
    );
    process.exitCode = 1;
}
