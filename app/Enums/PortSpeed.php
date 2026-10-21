<?php

// torsade/app/Enums/PortSpeed.php

namespace App\Enums;

/**
 * The highest speed a port can run at. What it actually negotiates is not
 * documented: it depends on what is plugged into it.
 */
enum PortSpeed: string
{
    case Mbit100 = '100m';
    case Gbit1 = '1g';
    case Gbit2_5 = '2_5g';
    case Gbit5 = '5g';
    case Gbit10 = '10g';
    case Gbit25 = '25g';
    case Gbit40 = '40g';
    case Gbit100 = '100g';

    /**
     * The translation key of the speed's label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Mbit100 => '100 Mbit/s',
            self::Gbit1 => '1 Gbit/s',
            self::Gbit2_5 => '2.5 Gbit/s',
            self::Gbit5 => '5 Gbit/s',
            self::Gbit10 => '10 Gbit/s',
            self::Gbit25 => '25 Gbit/s',
            self::Gbit40 => '40 Gbit/s',
            self::Gbit100 => '100 Gbit/s',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $speed): array => ['value' => $speed->value, 'label' => __($speed->label())],
            self::cases(),
        );
    }
}
