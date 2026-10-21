<?php

// torsade/app/Enums/IpAssignment.php

namespace App\Enums;

/**
 * How a machine gets its address. A static address set on the machine must
 * stay out of the DHCP ranges, where it would end up in conflict; an address
 * reserved on the DHCP server may sit inside one.
 */
enum IpAssignment: string
{
    case Static = 'static';
    case DhcpReservation = 'dhcp_reservation';

    /**
     * The translation key of the assignment's label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Static => 'Static',
            self::DhcpReservation => 'DHCP reservation',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $assignment): array => ['value' => $assignment->value, 'label' => __($assignment->label())],
            self::cases(),
        );
    }
}
