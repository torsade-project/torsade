// torsade/tests/e2e/jack-label-math.spec.ts
import fs from 'node:fs';
import path from 'node:path';
// No page is opened here: the plain test, without the fixtures that watch a
// browser.
import { expect, test } from '@playwright/test';
import { jackLabels } from '@/lib/jackLabels';
import { projectRoot } from './support/environment';

// The window of a series of jacks works out in the browser the labels the
// server gives them. The function that does it is checked against the table
// of cases the server is checked against: the two sides cannot drift apart.

type Table = {
    labels: [string, string, number, number, number, string[]][];
};

const table = JSON.parse(
    fs.readFileSync(
        path.join(projectRoot, 'tests', 'fixtures', 'jack-label-cases.json'),
        'utf8',
    ),
) as Table;

test('the labels of a series of jacks', () => {
    expect(table.labels.length).toBeGreaterThanOrEqual(8);

    for (const [
        prefix,
        suffix,
        first,
        last,
        digits,
        expected,
    ] of table.labels) {
        expect(
            jackLabels(prefix, suffix, first, last, digits),
            JSON.stringify([prefix, suffix, first, last, digits]),
        ).toEqual(expected);
    }
});
