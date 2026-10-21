<?php

// torsade/app/Enums/Status.php

namespace App\Enums;

/**
 * Where a network object stands in its life: Torsade documents what is
 * planned and what was retired as well as what is in service.
 */
enum Status: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Retired = 'retired';

    /**
     * The translation key of the status' label.
     *
     * A status agrees with what it describes in the languages that have
     * genders: in French a VLAN or a subnet is "actif", an IP address or a
     * DHCP range is "active". Hence a second set of keys, for the objects
     * whose name is feminine.
     */
    public function label(bool $feminine = false): string
    {
        return match ($this) {
            self::Planned => $feminine ? 'Planned (feminine)' : 'Planned',
            self::Active => $feminine ? 'Active (feminine)' : 'Active',
            self::Retired => $feminine ? 'Retired (feminine)' : 'Retired',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(bool $feminine = false): array
    {
        return array_map(
            fn (self $status): array => ['value' => $status->value, 'label' => __($status->label($feminine))],
            self::cases(),
        );
    }
}
