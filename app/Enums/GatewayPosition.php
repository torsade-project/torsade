<?php

// torsade/app/Enums/GatewayPosition.php

namespace App\Enums;

/**
 * Where the addressing rule of the organisation puts the gateway of a
 * subnet it proposes.
 */
enum GatewayPosition: string
{
    /** The first usable address: 10.20.10.1 in 10.20.10.0/24. */
    case First = 'first';

    /** The last usable address: 10.20.10.254 in 10.20.10.0/24. */
    case Last = 'last';

    /** No gateway is proposed. */
    case None = 'none';

    /**
     * The translation key of the position's label.
     */
    public function label(): string
    {
        return match ($this) {
            self::First => 'First usable address',
            self::Last => 'Last usable address',
            self::None => 'No gateway (position)',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $position): array => ['value' => $position->value, 'label' => __($position->label())],
            self::cases(),
        );
    }
}
