<?php

// torsade/app/Support/TemporaryPassword.php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Temporary passwords handed to a user by an administrator or by the console.
 * They are shown once and must be changed at the first login.
 */
final class TemporaryPassword
{
    public const LENGTH = 24;

    /**
     * Letters and digits only, so that the value survives a copy and paste
     * or being read aloud. 24 such characters carry about 140 bits.
     */
    public static function generate(): string
    {
        return Str::password(self::LENGTH, symbols: false);
    }
}
