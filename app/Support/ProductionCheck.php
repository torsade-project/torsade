<?php

// torsade/app/Support/ProductionCheck.php

namespace App\Support;

/**
 * The outcome of one check of a production installation: what it states,
 * whether it holds, and, when it does not, what to correct.
 */
final class ProductionCheck
{
    private function __construct(
        public readonly string $name,
        public readonly bool $passed,
        public readonly string $correction,
    ) {}

    public static function pass(string $name): self
    {
        return new self($name, true, '');
    }

    public static function fail(string $name, string $correction): self
    {
        return new self($name, false, $correction);
    }
}
