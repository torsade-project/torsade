<?php

// torsade/app/Enums/PortMode.php

namespace App\Enums;

/**
 * How a port, or an aggregate of ports, carries VLANs. An access port
 * carries one untagged VLAN, and a tagged one at most for a telephone with
 * a workstation behind it. A trunk carries tagged VLANs, and one untagged
 * VLAN at most, its native VLAN. A port without a mode is not configured,
 * or is routed: it carries no VLAN.
 */
enum PortMode: string
{
    case Access = 'access';
    case Trunk = 'trunk';

    /**
     * The translation key of the mode's label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Access => 'Access',
            self::Trunk => 'Trunk',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $mode): array => ['value' => $mode->value, 'label' => __($mode->label())],
            self::cases(),
        );
    }
}
