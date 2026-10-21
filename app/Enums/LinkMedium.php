<?php

// torsade/app/Enums/LinkMedium.php

namespace App\Enums;

/**
 * What carries a link between two ports. It is optional: a link is worth
 * documenting before anyone has looked at the cable.
 */
enum LinkMedium: string
{
    case Copper = 'copper';
    case FiberMultimode = 'fiber_multimode';
    case FiberSinglemode = 'fiber_singlemode';
    case DirectAttach = 'direct_attach';
    case Other = 'other';

    /**
     * The translation key of the medium's label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Copper => 'Copper',
            self::FiberMultimode => 'Multimode fibre',
            self::FiberSinglemode => 'Single-mode fibre',
            self::DirectAttach => 'Direct-attach cable',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $medium): array => ['value' => $medium->value, 'label' => __($medium->label())],
            self::cases(),
        );
    }
}
