<?php

// torsade/app/Casts/AsIpAddress.php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * An IP address is stored in binary (4 bytes for IPv4, 16 for IPv6), so that
 * the database sorts it numerically, and handled as text everywhere else.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class AsIpAddress implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::toText((string) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::toBinary((string) $value);
    }

    /**
     * The stored form of an address, also needed to compare a column with a
     * typed address in a query: casts do not apply to query bindings.
     *
     * @throws InvalidArgumentException
     */
    public static function toBinary(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException("[{$address}] is not an IP address.");
        }

        return (string) inet_pton($address);
    }

    /**
     * @throws UnexpectedValueException
     */
    public static function toText(string $binary): string
    {
        $address = in_array(strlen($binary), [4, 16], true) ? inet_ntop($binary) : false;

        if ($address === false) {
            throw new UnexpectedValueException('A stored IP address is neither 4 nor 16 bytes long.');
        }

        return $address;
    }
}
