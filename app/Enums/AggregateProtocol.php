<?php

// torsade/app/Enums/AggregateProtocol.php

namespace App\Enums;

/**
 * How the ports of an aggregate agree to work as one link: negotiated with
 * the other end, or set on both ends by hand.
 */
enum AggregateProtocol: string
{
    case Lacp = 'lacp';
    case Static = 'static';

    /**
     * The translation key of the protocol's label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Lacp => 'LACP',
            self::Static => 'Static (aggregate)',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $protocol): array => ['value' => $protocol->value, 'label' => __($protocol->label())],
            self::cases(),
        );
    }
}
