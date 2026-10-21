<?php

// torsade/app/Support/JackLabels.php

namespace App\Support;

/**
 * How the jacks of a series are labelled: a prefix, a number written with
 * at least so many digits, and a suffix. "B." and "", from 1 to 24 on two
 * digits, give "B.01" to "B.24".
 *
 * Only text handling on what it is given: it reads nothing and decides
 * nothing. The window of a series works the same labels out in the
 * browser, to show them while they are typed; both are checked against the
 * same table of cases.
 */
final class JackLabels
{
    /**
     * How many jacks one series makes at most.
     */
    public const int MAX_QUANTITY = 200;

    /**
     * The most digits a number is written with.
     */
    public const int MAX_DIGITS = 6;

    /**
     * The highest number a series reaches.
     */
    public const int MAX_NUMBER = 99999;

    /**
     * One label: the number with at least so many digits, zeros in front.
     */
    public static function label(string $prefix, string $suffix, int $number, int $digits): string
    {
        return $prefix.str_pad((string) $number, $digits, '0', STR_PAD_LEFT).$suffix;
    }

    /**
     * The labels from a first number to a last one, in their order; none
     * when the last one comes before the first.
     *
     * @return list<string>
     */
    public static function labels(string $prefix, string $suffix, int $first, int $last, int $digits): array
    {
        if ($last < $first) {
            return [];
        }

        return array_map(fn (int $number): string => self::label($prefix, $suffix, $number, $digits), range($first, $last));
    }
}
