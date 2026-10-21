<?php

// torsade/app/Rules/PortNamePattern.php

namespace App\Rules;

use App\Support\PortNaming;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A pattern ports are named after: "{n}", the number of the port, once;
 * "{m}", the number of the member, once at most; around them only letters,
 * digits and "/ - . :".
 *
 * A series added to a member may name one port alone: its pattern can then
 * do without "{n}".
 */
final class PortNamePattern implements ValidationRule
{
    /**
     * @param  bool  $series  Whether the pattern names a series added to a member, and not a group of a template.
     * @param  bool  $single  Whether that series is made of one port alone.
     */
    public function __construct(
        private readonly bool $series = false,
        private readonly bool $single = false,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $problem = is_string($value) ? PortNaming::problem($value, $this->series && $this->single) : PortNaming::NO_NUMBER;
        $message = self::message($problem, $this->series);

        if ($message !== null) {
            $fail($message);
        }
    }

    /**
     * What to tell about a pattern, from what is wrong with it.
     *
     * @param  bool  $series  Whether the pattern names a series, which says when "{n}" can be left out.
     */
    public static function message(?string $problem, bool $series = false): ?string
    {
        return match (true) {
            $problem === PortNaming::NO_NUMBER && $series => (string) __('For several ports, a pattern holds {n}, the number of the port, once: "{m}/0/{n}" or "port{n}". One port alone can do without it: "MGMT".'),
            default => self::plainMessage($problem),
        };
    }

    private static function plainMessage(?string $problem): ?string
    {
        return match ($problem) {
            null => null,
            PortNaming::SEVERAL_MEMBERS => (string) __('A pattern holds {m}, the number of the member, once at most.'),
            PortNaming::CHARACTERS => (string) __('Besides {m} and {n}, a pattern only holds letters, digits and the characters / - . and the colon.'),
            default => (string) __('A pattern holds {n}, the number of the port, once: "{m}/0/{n}" or "port{n}".'),
        };
    }
}
