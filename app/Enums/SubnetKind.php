<?php

// torsade/app/Enums/SubnetKind.php

namespace App\Enums;

use App\Support\Ipv4;

/**
 * What a subnet is for. A point-to-point link or a loopback is not a local
 * network: it has no gateway and no ranges, and its size is fixed. Each kind
 * says what it accepts; App\Services\Network\Subnets enforces it.
 */
enum SubnetKind: string
{
    /** A local network, with or without a VLAN. */
    case Segment = 'segment';

    /** A link between two routers, in a /30 or a /31. */
    case PointToPoint = 'point_to_point';

    /** The address of a router itself, in a /32. */
    case Loopback = 'loopback';

    /** The inside of a VPN tunnel. */
    case Tunnel = 'tunnel';

    /** Addresses that belong to someone else: a carrier, a provider. */
    case External = 'external';

    /**
     * The translation key of the kind's label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Segment => 'Segment',
            self::PointToPoint => 'Point-to-point',
            self::Loopback => 'Loopback',
            self::Tunnel => 'Tunnel',
            self::External => 'External',
        };
    }

    public function allowsVlan(): bool
    {
        return match ($this) {
            self::Loopback, self::Tunnel => false,
            default => true,
        };
    }

    /**
     * The prefix lengths a subnet of this kind can have.
     *
     * @return list<int>
     */
    public function prefixLengths(): array
    {
        return match ($this) {
            self::PointToPoint => [30, 31],
            self::Loopback => [32],
            default => range(1, Ipv4::MAX_PREFIX),
        };
    }

    public function allowsPrefix(int $prefix): bool
    {
        return in_array($prefix, $this->prefixLengths(), true);
    }

    public function allowsGateway(): bool
    {
        return match ($this) {
            self::PointToPoint, self::Loopback => false,
            default => true,
        };
    }

    public function allowsRanges(): bool
    {
        return $this->allowsGateway();
    }

    /**
     * The kinds, each with what it accepts: the form hides what a kind
     * excludes, and the server refuses it whatever the form does.
     *
     * @return list<array{value: string, label: string, allows_vlan: bool, allows_gateway: bool, allows_ranges: bool, prefix_lengths: list<int>}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $kind): array => [
                'value' => $kind->value,
                'label' => __($kind->label()),
                'allows_vlan' => $kind->allowsVlan(),
                'allows_gateway' => $kind->allowsGateway(),
                'allows_ranges' => $kind->allowsRanges(),
                'prefix_lengths' => $kind->prefixLengths(),
            ],
            self::cases(),
        );
    }
}
