<?php

// torsade/app/Enums/PortConnector.php

namespace App\Enums;

/**
 * What plugs into a port: a copper jack, or the cage of a fibre module.
 */
enum PortConnector: string
{
    case Rj45 = 'rj45';
    case Sfp = 'sfp';
    case SfpPlus = 'sfp_plus';
    case Sfp28 = 'sfp28';
    case QsfpPlus = 'qsfp_plus';
    case Qsfp28 = 'qsfp28';
    case Other = 'other';

    /**
     * The translation key of the connector's label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Rj45 => 'RJ45',
            self::Sfp => 'SFP',
            self::SfpPlus => 'SFP+',
            self::Sfp28 => 'SFP28',
            self::QsfpPlus => 'QSFP+',
            self::Qsfp28 => 'QSFP28',
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
