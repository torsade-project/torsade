<?php

// torsade/app/Rules/MacAddressFormat.php

namespace App\Rules;

use App\Support\MacAddress;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A MAC address in one of the notations the tools print it in.
 */
final class MacAddressFormat implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! MacAddress::isValid($value)) {
            $fail(__('Enter a MAC address such as aa:bb:cc:dd:ee:ff.'));
        }
    }
}
