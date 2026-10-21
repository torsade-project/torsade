<?php

// torsade/app/Enums/PoeType.php

namespace App\Enums;

/**
 * What a port can power, by the IEEE standard it follows. Manufacturers
 * name the same standards differently ("PoE+", "PoE++", "UPoE"): their word
 * is kept beside this one, as a free label.
 */
enum PoeType: string
{
    case None = 'none';
    case Af = '802.3af';
    case At = '802.3at';
    case BtType3 = '802.3bt_type3';
    case BtType4 = '802.3bt_type4';

    /**
     * The translation key of the type's label.
     */
    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Af => '802.3af',
            self::At => '802.3at',
            self::BtType3 => '802.3bt Type 3',
            self::BtType4 => '802.3bt Type 4',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => __($type->label())],
            self::cases(),
        );
    }
}
