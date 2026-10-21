<?php

// torsade/app/Support/PortNaming.php

namespace App\Support;

/**
 * How ports are named from a pattern. Manufacturers do not agree on the
 * names of ports: a pattern with two marks covers the usual notations
 * without imposing one. "{m}" is the number of the member of the device,
 * "{n}" the number of the port: "{m}/0/{n}" gives "1/0/1" to "1/0/24" on
 * the member 1, "Gi{m}/0/{n}" gives "Gi2/0/1" on the member 2, "port{n}"
 * gives "port1".
 *
 * Only text handling on what it is given: it reads nothing and decides
 * nothing. The forms work the same names out in the browser, to show them
 * while a pattern is typed; both are checked against the same table of
 * cases.
 *
 * @phpstan-type NamedGroup array{name_pattern: string, first_number: int, quantity: int}
 */
final class PortNaming
{
    public const string MEMBER = '{m}';

    public const string NUMBER = '{n}';

    /**
     * The longest name a port can have, and the longest pattern.
     */
    public const int MAX_NAME_LENGTH = 32;

    /**
     * How many ports one pattern can name at once.
     */
    public const int MAX_QUANTITY = 128;

    /**
     * The highest number the first port of a group can have.
     */
    public const int MAX_FIRST_NUMBER = 999;

    /**
     * What can be wrong with a pattern.
     */
    public const string NO_NUMBER = 'no_number';

    public const string SEVERAL_NUMBERS = 'several_numbers';

    public const string SEVERAL_MEMBERS = 'several_members';

    public const string CHARACTERS = 'characters';

    /**
     * What is wrong with a pattern, or null when it can name ports: the
     * number of the port once, the number of the member once at most, and
     * around them only letters, digits and "/ - . :".
     *
     * A pattern that names one port alone can do without the number of the
     * port: "MGMT", "WAN".
     */
    public static function problem(string $pattern, bool $single = false): ?string
    {
        $numbers = substr_count($pattern, self::NUMBER);

        if ($numbers === 0 && ! $single) {
            return self::NO_NUMBER;
        }

        if ($numbers > 1) {
            return self::SEVERAL_NUMBERS;
        }

        if (substr_count($pattern, self::MEMBER) > 1) {
            return self::SEVERAL_MEMBERS;
        }

        $around = str_replace([self::MEMBER, self::NUMBER], '', $pattern);

        return preg_match('/^[A-Za-z0-9\/\-.:]*$/D', $around) === 1 ? null : self::CHARACTERS;
    }

    /**
     * The name of one port.
     */
    public static function name(string $pattern, int $member, int $number): string
    {
        return str_replace([self::MEMBER, self::NUMBER], [(string) $member, (string) $number], $pattern);
    }

    /**
     * The names of so many ports, numbered from the first number on.
     *
     * @return list<string>
     */
    public static function names(string $pattern, int $member, int $first, int $quantity): array
    {
        $names = [];

        for ($number = $first; $number < $first + $quantity; $number++) {
            $names[] = self::name($pattern, $member, $number);
        }

        return $names;
    }

    /**
     * The groups that give a name a group before them already gives, for
     * one member: for each such group, the first name it repeats and the
     * place of the group that gave it first.
     *
     * @param  array<int, NamedGroup>  $groups
     * @return array<int, array{name: string, with: int}>
     */
    public static function clashes(array $groups, int $member = 1): array
    {
        $givenBy = [];
        $clashes = [];

        foreach (array_values($groups) as $place => $group) {
            $names = self::names($group['name_pattern'], $member, $group['first_number'], $group['quantity']);

            foreach ($names as $name) {
                if (isset($givenBy[$name])) {
                    $clashes[$place] ??= ['name' => $name, 'with' => $givenBy[$name]];
                }
            }

            // A group never repeats itself: its names only count for the
            // groups after it.
            foreach ($names as $name) {
                $givenBy[$name] ??= $place;
            }
        }

        return $clashes;
    }
}
