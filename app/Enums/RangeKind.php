<?php

// torsade/app/Enums/RangeKind.php

namespace App\Enums;

/**
 * What a range of addresses of a subnet is set aside for. The way a subnet
 * is cut into ranges is documentation in itself, and it guides the choice
 * of the next address.
 */
enum RangeKind: string
{
    /** Handed out by a DHCP server: static addresses are kept out of it. */
    case Dhcp = 'dhcp';

    /** For addresses set on the machines: where the next address is taken. */
    case Static = 'static';

    /** Kept aside: nothing is proposed there. */
    case Reserved = 'reserved';

    /**
     * The translation key of the kind's label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Dhcp => 'DHCP',
            self::Static => 'Static (range)',
            self::Reserved => 'Reserved (range)',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $kind): array => ['value' => $kind->value, 'label' => __($kind->label())],
            self::cases(),
        );
    }
}
