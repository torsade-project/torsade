<?php

// torsade/app/Support/Version.php

namespace App\Support;

/**
 * The version of the application. Its single source is the VERSION file at
 * the root of the project, written at each release.
 */
final class Version
{
    /**
     * Shown when the file is missing, as in a working copy without it.
     */
    public const UNKNOWN = 'dev';

    public static function fromFile(string $path): string
    {
        if (! is_file($path)) {
            return self::UNKNOWN;
        }

        $version = trim((string) file_get_contents($path));

        return $version === '' ? self::UNKNOWN : $version;
    }
}
