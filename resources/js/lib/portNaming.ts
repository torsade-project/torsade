// torsade/resources/js/lib/portNaming.ts

// How ports are named from a pattern: "{m}" is the number of the member of
// the device, "{n}" the number of the port. "{m}/0/{n}" gives "1/0/1" to
// "1/0/24" on the member 1, "port{n}" gives "port1".
//
// The forms use these functions to show the names while a pattern is typed.
// The server works the same names out by itself and never takes them from
// the browser; both are checked against the same table of cases.

const MEMBER = '{m}';
const NUMBER = '{n}';

export const MAX_PORT_NAME_LENGTH = 32;

export type PatternProblem =
    | 'no_number'
    | 'several_numbers'
    | 'several_members'
    | 'characters';

// What names a group of ports: a pattern, the number of its first port and
// how many ports it has.
export type NamedGroup = {
    name_pattern: string;
    first_number: number;
    quantity: number;
};

export type Clash = {
    // The first name the group repeats.
    name: string;
    // The place of the group that gave it first.
    with: number;
};

const count = (text: string, mark: string): number =>
    text.split(mark).length - 1;

/**
 * What is wrong with a pattern, or null when it can name ports: the number
 * of the port once, the number of the member once at most, and around them
 * only letters, digits and "/ - . :".
 *
 * A pattern that names one port alone can do without the number of the
 * port: "MGMT", "WAN".
 */
export function patternProblem(
    pattern: string,
    single = false,
): PatternProblem | null {
    const numbers = count(pattern, NUMBER);

    if (numbers === 0 && !single) {
        return 'no_number';
    }

    if (numbers > 1) {
        return 'several_numbers';
    }

    if (count(pattern, MEMBER) > 1) {
        return 'several_members';
    }

    const around = pattern.split(MEMBER).join('').split(NUMBER).join('');

    return /^[A-Za-z0-9/\-.:]*$/.test(around) ? null : 'characters';
}

/**
 * The name of one port.
 */
export function portName(
    pattern: string,
    member: number,
    number: number,
): string {
    return pattern
        .split(MEMBER)
        .join(String(member))
        .split(NUMBER)
        .join(String(number));
}

/**
 * The names of so many ports, numbered from the first number on.
 */
export function portNames(
    pattern: string,
    member: number,
    first: number,
    quantity: number,
): string[] {
    const names: string[] = [];

    for (let number = first; number < first + quantity; number++) {
        names.push(portName(pattern, member, number));
    }

    return names;
}

/**
 * The groups that give a name a group before them already gives, for one
 * member: for each such group, by its place, the first name it repeats and
 * the place of the group that gave it first.
 */
export function groupClashes(
    groups: NamedGroup[],
    member = 1,
): Record<number, Clash> {
    const givenBy = new Map<string, number>();
    const clashes: Record<number, Clash> = {};

    groups.forEach((group, place) => {
        const names = portNames(
            group.name_pattern,
            member,
            group.first_number,
            group.quantity,
        );

        for (const name of names) {
            const first = givenBy.get(name);

            if (first !== undefined && clashes[place] === undefined) {
                clashes[place] = { name, with: first };
            }
        }

        // A group never repeats itself: its names only count for the
        // groups after it.
        for (const name of names) {
            if (!givenBy.has(name)) {
                givenBy.set(name, place);
            }
        }
    });

    return clashes;
}
