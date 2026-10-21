// torsade/tests/e2e/port-naming-math.spec.ts
import fs from 'node:fs';
import path from 'node:path';
// No page is opened here: the plain test, without the fixtures that watch a
// browser.
import { expect, test } from '@playwright/test';
import {
    groupClashes,
    patternProblem,
    portName,
    portNames,
} from '@/lib/portNaming';
import type { Clash } from '@/lib/portNaming';
import { projectRoot } from './support/environment';

// The forms work out in the browser the names a pattern gives to ports. The
// functions that do it are checked against the table of cases the server is
// checked against: the two sides cannot drift apart.

type Table = {
    patterns: [string, string | null][];
    // The patterns that name one port alone.
    single_patterns: [string, string | null][];
    names: [string, number, number, number, string[]][];
    clashes: {
        groups: [string, number, number][];
        member: number;
        clashes: Record<string, [string, number]>;
    }[];
};

const table = JSON.parse(
    fs.readFileSync(
        path.join(projectRoot, 'tests', 'fixtures', 'port-naming-cases.json'),
        'utf8',
    ),
) as Table;

test('the table has cases of every sort', () => {
    // Every way a pattern can be wrong, and patterns that are right.
    const problems = new Set(table.patterns.map(([, problem]) => problem));

    expect(problems.size).toBe(5);

    for (const problem of [
        'characters',
        'no_number',
        'several_members',
        'several_numbers',
        null,
    ]) {
        expect(problems.has(problem), String(problem)).toBe(true);
    }

    expect(table.names.length).toBeGreaterThanOrEqual(8);
    expect(table.clashes.length).toBeGreaterThanOrEqual(6);

    // For one port alone: every way to be wrong but the missing number.
    const single = new Set(table.single_patterns.map(([, problem]) => problem));

    expect(single.size).toBe(4);
    expect(single.has('no_number')).toBe(false);
});

test('a pattern for one port alone can do without the number of the port', () => {
    for (const [pattern, problem] of table.single_patterns) {
        expect(patternProblem(pattern, true), JSON.stringify(pattern)).toBe(
            problem,
        );

        // For several ports, the same pattern needs the number of the
        // port: without it, that is what is wrong first.
        expect(patternProblem(pattern), JSON.stringify(pattern)).toBe(
            pattern.includes('{n}') ? problem : 'no_number',
        );
    }
});

test('a pattern holds the number of the port once and the number of the member once at most', () => {
    for (const [pattern, problem] of table.patterns) {
        expect(patternProblem(pattern), JSON.stringify(pattern)).toBe(problem);
    }
});

test('the names a pattern gives', () => {
    for (const [pattern, member, first, quantity, expected] of table.names) {
        expect(
            portNames(pattern, member, first, quantity),
            `${pattern} on the member ${member}`,
        ).toEqual(expected);

        if (quantity > 0) {
            expect(portName(pattern, member, first)).toBe(expected[0]);
        }
    }
});

test('the groups that repeat a name another group gives', () => {
    table.clashes.forEach((one, index) => {
        const groups = one.groups.map(
            ([name_pattern, first_number, quantity]) => ({
                name_pattern,
                first_number,
                quantity,
            }),
        );

        const expected: Record<number, Clash> = {};

        for (const [place, [name, first]] of Object.entries(one.clashes)) {
            expected[Number(place)] = { name, with: first };
        }

        expect(groupClashes(groups, one.member), `case ${index}`).toEqual(
            expected,
        );
    });
});
