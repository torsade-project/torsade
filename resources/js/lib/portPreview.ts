// torsade/resources/js/lib/portPreview.ts
import { trans } from '@/lib/i18n';
import { patternProblem, portName } from '@/lib/portNaming';
import type { NamedGroup } from '@/lib/portNaming';

/**
 * The names several groups of ports give, in a few words: "1/0/1 to 1/0/24,
 * then 1/1/1 to 1/1/4". A group whose pattern cannot name ports is left
 * out: it is told on its own line.
 */
export function namesInWords(groups: NamedGroup[], member = 1): string {
    return groups
        .filter(
            (group) =>
                // One port alone may be named without its number.
                patternProblem(group.name_pattern, group.quantity === 1) ===
                    null &&
                Number.isInteger(group.first_number) &&
                Number.isInteger(group.quantity) &&
                group.quantity >= 1,
        )
        .map((group) => {
            const first = portName(
                group.name_pattern,
                member,
                group.first_number,
            );

            if (group.quantity === 1) {
                return first;
            }

            return trans(':first to :last', {
                first,
                last: portName(
                    group.name_pattern,
                    member,
                    group.first_number + group.quantity - 1,
                ),
            });
        })
        .join(trans(', then '));
}

/**
 * What to tell about a pattern, from what is wrong with it: the words the
 * server uses when it refuses the same pattern.
 *
 * A series added to a member says when "{n}" can be left out, and one port
 * alone can do without it.
 */
export function patternMessage(
    pattern: string,
    series: { single: boolean } | null = null,
): string | null {
    const problem = patternProblem(pattern, series?.single ?? false);

    if (problem === 'no_number' && series !== null) {
        return trans(
            'For several ports, a pattern holds {n}, the number of the port, once: "{m}/0/{n}" or "port{n}". One port alone can do without it: "MGMT".',
        );
    }

    switch (problem) {
        case null:
            return null;
        case 'several_members':
            return trans(
                'A pattern holds {m}, the number of the member, once at most.',
            );
        case 'characters':
            return trans(
                'Besides {m} and {n}, a pattern only holds letters, digits and the characters / - . and the colon.',
            );
        default:
            return trans(
                'A pattern holds {n}, the number of the port, once: "{m}/0/{n}" or "port{n}".',
            );
    }
}
