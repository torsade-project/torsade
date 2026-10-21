<?php

// torsade/app/Rules/Ipv4Address.php

namespace App\Rules;

use App\Support\Ipv4;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A strict dotted-decimal IPv4 address. An IPv6 address gets its own
 * message: it is a valid address, only not one that can be entered yet.
 */
final class Ipv4Address implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && Ipv4::isValid($value)) {
            return;
        }

        $fail(is_string($value) && self::looksLikeIpv6($value)
            ? __('IPv6 addresses are not supported yet: enter an IPv4 address.')
            : __('Enter a valid IPv4 address, such as 192.168.1.10.'));
    }

    public static function looksLikeIpv6(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }
}
