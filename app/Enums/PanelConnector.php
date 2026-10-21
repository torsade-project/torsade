<?php

// torsade/app/Enums/PanelConnector.php

namespace App\Enums;

/**
 * What the ports of a patch panel take: copper, or one of the fibre
 * connectors.
 */
enum PanelConnector: string
{
    case Rj45 = 'rj45';
    case Lc = 'lc';
    case Sc = 'sc';
    case Other = 'other';

    /**
     * The translation key of the connector's label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Rj45 => 'RJ45',
            self::Lc => 'LC fibre',
            self::Sc => 'SC fibre',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $connector): array => ['value' => $connector->value, 'label' => __($connector->label())],
            self::cases(),
        );
    }
}
