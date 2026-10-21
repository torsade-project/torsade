<?php

// torsade/database/factories/DrawnVids.php

namespace Database\Factories;

/**
 * The VLAN numbers the factories draw for a VLAN or an entry of the
 * catalogue that is given none. For tests only.
 *
 * They are taken from two ranges of their own, which no test writes by
 * hand: a number written in a test can then never meet a number that was
 * drawn, whatever the draw. Drawn anywhere between 1 and 4094, a number met
 * one that a test had chosen about once in four thousand runs, and the test
 * failed on a unique index or on a deviation nobody expected.
 *
 * The numbers drawn are remembered, so that the tests can tell a number of
 * these ranges that was drawn from one that was written by hand.
 */
final class DrawnVids
{
    /**
     * The numbers drawn for a VLAN.
     */
    public const int FIRST_OF_VLANS = 2100;

    public const int LAST_OF_VLANS = 2999;

    /**
     * The numbers drawn for an entry of the catalogue: never those of a
     * drawn VLAN, which would then seem to follow the entry.
     */
    public const int FIRST_OF_CATALOGUE = 3100;

    public const int LAST_OF_CATALOGUE = 3999;

    /**
     * @var array<int, true>
     */
    private static array $drawn = [];

    public static function forVlan(): int
    {
        return self::draw(self::FIRST_OF_VLANS, self::LAST_OF_VLANS);
    }

    public static function forCatalogue(): int
    {
        return self::draw(self::FIRST_OF_CATALOGUE, self::LAST_OF_CATALOGUE);
    }

    /**
     * Whether a number belongs to one of the two ranges kept for draws.
     */
    public static function isKept(int $vid): bool
    {
        return ($vid >= self::FIRST_OF_VLANS && $vid <= self::LAST_OF_VLANS)
            || ($vid >= self::FIRST_OF_CATALOGUE && $vid <= self::LAST_OF_CATALOGUE);
    }

    public static function wasDrawn(int $vid): bool
    {
        return isset(self::$drawn[$vid]);
    }

    /**
     * Forget what was drawn: each test starts from nothing.
     */
    public static function forget(): void
    {
        self::$drawn = [];
    }

    private static function draw(int $first, int $last): int
    {
        $vid = fake()->unique()->numberBetween($first, $last);

        self::$drawn[$vid] = true;

        return $vid;
    }
}
