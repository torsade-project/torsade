// torsade/scripts/docs/checks.test.mjs
// The checks of the documentation, tried on the example folders next to
// them: they pass on the good one and fail on each bad one, with a message
// that names the file and the line.
//
// Usage: node --test scripts/docs/checks.test.mjs
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));

function run(script, example) {
    const result = spawnSync(
        process.execPath,
        [
            path.join(here, script),
            '--root',
            path.join(here, 'fixtures', example),
        ],
        { encoding: 'utf8' },
    );

    return { status: result.status, output: result.stdout + result.stderr };
}

await test('the links of the good example pass, external ones not called', () => {
    const { status, output } = run('check-links.mjs', 'good');

    assert.equal(status, 0, output);
    assert.match(output, /Documentation links: 12 relative links in 5 files/);
});

await test('the labels of the good example pass, in tables, links and plurals', () => {
    const { status, output } = run('check-labels.mjs', 'good');

    assert.equal(status, 0, output);
    assert.match(output, /Labels of the guide: 8 labels in bold in 2 files/);
});

await test('a dead link fails, with its file and its line', () => {
    const { status, output } = run('check-links.mjs', 'dead-link');

    assert.equal(status, 1, output);
    assert.match(
        output,
        /documentation\/guide\/page\.md:5: link to "absente\.md": no file tracked by Git at documentation\/guide\/absente\.md\./,
    );
});

await test('an anchor without its heading fails', () => {
    const { status, output } = run('check-links.mjs', 'missing-anchor');

    assert.equal(status, 1, output);
    assert.match(
        output,
        /documentation\/guide\/page\.md:7: link to "#modifier-un-site": no heading with the anchor "#modifier-un-site" in documentation\/guide\/page\.md\./,
    );
});

await test('a page left out of the table of contents fails', () => {
    const { status, output } = run('check-links.mjs', 'not-in-contents');

    assert.equal(status, 1, output);
    assert.match(
        output,
        /documentation\/guide\/orpheline\.md:1: not linked from the table of contents, documentation\/README\.md\./,
    );
    assert.doesNotMatch(output, /guide\/page\.md/);
});

await test('an invented label fails, in a table too', () => {
    const { status, output } = run('check-labels.mjs', 'invented-label');

    assert.equal(status, 1, output);
    assert.match(
        output,
        /documentation\/guide\/page\.md:9: "Bouton inventé" is in bold but is not a label of lang\/fr\.json\./,
    );
    assert.doesNotMatch(output, /"Enregistrer"/);
});

await test('bold made with underscores fails', () => {
    const { status, output } = run('check-labels.mjs', 'underscore-bold');

    assert.equal(status, 1, output);
    assert.match(
        output,
        /documentation\/guide\/page\.md:6: "__Enregistrer__" is bold made with underscores: quote a label with \*\*\./,
    );
});
