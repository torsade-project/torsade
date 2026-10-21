<?php

// torsade/app/Support/MacAddress.php

namespace App\Support;

/**
 * MAC addresses are typed the way each tool prints them, and stored in a
 * single form so that a search finds them whatever the source.
 */
final class MacAddress
{
    /**
     * The accepted notations, without regard to case: aa:bb:cc:dd:ee:ff
     * (Linux), aa-bb-cc-dd-ee-ff (Windows), aabb.ccdd.eeff (network
     * equipment) and aabbccddeeff. Separators cannot be mixed.
     */
    private const array FORMATS = [
        '/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/iD',
        '/^[0-9a-f]{2}(-[0-9a-f]{2}){5}$/iD',
        '/^[0-9a-f]{4}(\.[0-9a-f]{4}){2}$/iD',
        '/^[0-9a-f]{12}$/iD',
    ];

    public static function isValid(string $value): bool
    {
        return array_any(self::FORMATS, fn (string $format): bool => preg_match($format, $value) === 1);
    }

    /**
     * The stored form, "aa:bb:cc:dd:ee:ff", or null when the value is not a
     * MAC address in one of the accepted notations.
     */
    public static function normalise(string $value): ?string
    {
        if (! self::isValid($value)) {
            return null;
        }

        $digits = strtolower((string) preg_replace('/[^0-9a-f]/i', '', $value));

        return implode(':', str_split($digits, 2));
    }
}
