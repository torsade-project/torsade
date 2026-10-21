<?php

// torsade/app/Support/AddressingScheme.php

namespace App\Support;

use App\Enums\GatewayPosition;
use App\Models\AddressingRule;
use App\Models\AddressingRuleRange;

/**
 * What the addressing rule of the organisation works out: the block a site
 * is expected to have, the subnet a VLAN is expected to carry, the gateway
 * and the ranges proposed for a subnet.
 *
 * A rule may have no level of sites: the subnets of VLANs are then ranked
 * by VID straight in the block of the organisation, the same for every
 * site, and no block is expected of a site, nor any number.
 *
 * Only arithmetic on what it is given: it reads nothing and decides
 * nothing. The forms work the same values out in the browser, from the
 * same parameters; both are checked against the same table of cases.
 *
 * @phpstan-type Prefix array{network: string, prefix_length: int}
 * @phpstan-type SchemeRange array{kind: string, start_offset: int, end_offset: int, name: string|null}
 * @phpstan-type PlacedRange array{kind: string, start_address: string, end_address: string, name: string|null, fits: bool}
 */
final readonly class AddressingScheme
{
    /**
     * @param  string  $network  The network address of the block of the organisation.
     * @param  int  $prefix  Its prefix length.
     * @param  int|null  $sitePrefix  The length of the blocks of sites; null without a level of sites.
     * @param  list<SchemeRange>  $ranges  The ranges a subnet is cut into, as offsets from its network address.
     */
    public function __construct(
        public string $network,
        public int $prefix,
        public ?int $sitePrefix,
        public int $vlanPrefix,
        public GatewayPosition $gateway = GatewayPosition::First,
        public array $ranges = [],
    ) {}

    /**
     * The rule must come with its block and its ranges.
     */
    public static function fromRule(AddressingRule $rule): self
    {
        return new self(
            $rule->aggregateBlock->network,
            $rule->aggregateBlock->prefix_length,
            $rule->site_prefix_length,
            $rule->vlan_prefix_length,
            $rule->gateway_position,
            array_values($rule->ranges->map(fn (AddressingRuleRange $range): array => [
                'kind' => $range->kind->value,
                'start_offset' => $range->start_offset,
                'end_offset' => $range->end_offset,
                'name' => $range->name,
            ])->all()),
        );
    }

    /**
     * Whether the rule cuts the block of the organisation into blocks of
     * sites, ranked by the numbers of the sites.
     */
    public function hasSiteLevel(): bool
    {
        return $this->sitePrefix !== null;
    }

    /**
     * The highest number a site can have for the rule to give it a block:
     * the block of the organisation holds that many blocks of sites, the
     * first one being the site 0. Null without a level of sites: the rule
     * gives no block to any site.
     */
    public function maxSiteNumber(): ?int
    {
        return $this->sitePrefix === null ? null : (1 << ($this->sitePrefix - $this->prefix)) - 1;
    }

    public function allowsSiteNumber(?int $number): bool
    {
        $max = $this->maxSiteNumber();

        return $max !== null && $number !== null && $number >= 0 && $number <= $max;
    }

    /**
     * The block a site is expected to have: the one whose rank in the
     * block of the organisation is the number of the site. Null when the
     * site has no number, or one the rule does not reach, and without a
     * level of sites.
     *
     * @return Prefix|null
     */
    public function siteBlock(?int $number): ?array
    {
        if ($number === null || $this->sitePrefix === null || ! $this->allowsSiteNumber($number)) {
            return null;
        }

        return [
            'network' => Ipv4::toAddress(Ipv4::toInt($this->network) + $number * Ipv4::size($this->sitePrefix)),
            'prefix_length' => $this->sitePrefix,
        ];
    }

    /**
     * The highest VID the rule covers: the block of a site, or the block of
     * the organisation without a level of sites, holds that many subnets
     * after the first one, which no VLAN has since no VLAN is numbered 0.
     */
    public function maxVid(): int
    {
        return (1 << ($this->vlanPrefix - ($this->sitePrefix ?? $this->prefix))) - 1;
    }

    public function coversVid(int $vid): bool
    {
        return $vid >= 1 && $vid <= $this->maxVid();
    }

    /**
     * The subnet a VLAN is expected to carry: the one whose rank in the
     * block of its site is its VID. Null when the site has no expected
     * block or when the rule does not cover the VID. Without a level of
     * sites, the rank is taken in the block of the organisation, whatever
     * the site.
     *
     * @return Prefix|null
     */
    public function vlanSubnet(?int $siteNumber, int $vid): ?array
    {
        $base = $this->sitePrefix === null ? $this->network : $this->siteBlock($siteNumber)['network'] ?? null;

        if ($base === null || ! $this->coversVid($vid)) {
            return null;
        }

        return [
            'network' => Ipv4::toAddress(Ipv4::toInt($base) + $vid * Ipv4::size($this->vlanPrefix)),
            'prefix_length' => $this->vlanPrefix,
        ];
    }

    /**
     * The gateway proposed for a subnet: its first or its last usable
     * address, or none.
     */
    public function gatewayOf(string $network, int $prefix): ?string
    {
        [$first, $last] = Ipv4::usableRange($network, $prefix);

        return match ($this->gateway) {
            GatewayPosition::First => $first,
            GatewayPosition::Last => $last,
            GatewayPosition::None => null,
        };
    }

    /**
     * The offset of the gateway in a subnet of a VLAN, which a DHCP range
     * of the rule must leave out. Null when the rule proposes no gateway.
     */
    public function gatewayOffset(): ?int
    {
        return match ($this->gateway) {
            GatewayPosition::First => 1,
            GatewayPosition::Last => Ipv4::size($this->vlanPrefix) - 2,
            GatewayPosition::None => null,
        };
    }

    public function hasBreakdown(): bool
    {
        return $this->ranges !== [];
    }

    /**
     * The ranges of the rule laid out in a subnet, each one shifted from
     * its network address. A range fits when both its ends are usable
     * addresses of the subnet; one that does not is still returned, so that
     * it can be named.
     *
     * @return list<PlacedRange>
     */
    public function breakdownOf(string $network, int $prefix): array
    {
        $base = Ipv4::toInt($network);
        $size = Ipv4::size($prefix);
        $highest = Ipv4::toInt('255.255.255.255');

        // The offsets of the usable addresses: all but the first and the
        // last, except in the subnets too small to have a network and a
        // broadcast address.
        [$lowest, $last] = $prefix >= 31 ? [0, $size - 1] : [1, $size - 2];

        return array_map(
            fn (array $range): array => [
                'kind' => $range['kind'],
                'start_address' => Ipv4::toAddress(min($base + $range['start_offset'], $highest)),
                'end_address' => Ipv4::toAddress(min($base + $range['end_offset'], $highest)),
                'name' => $range['name'],
                'fits' => $range['start_offset'] >= $lowest
                    && $range['end_offset'] <= $last
                    && $range['start_offset'] <= $range['end_offset'],
            ],
            $this->ranges,
        );
    }

    /**
     * Whether every range of the rule fits in a subnet. A rule without
     * ranges has nothing to lay out: it does not fit anywhere.
     */
    public function breakdownFits(string $network, int $prefix): bool
    {
        if (! $this->hasBreakdown()) {
            return false;
        }

        foreach ($this->breakdownOf($network, $prefix) as $range) {
            if (! $range['fits']) {
                return false;
            }
        }

        return true;
    }

    /**
     * What the pages hand to the browser, which works the same values out
     * while a form is being filled in.
     *
     * @return array{network: string, prefix_length: int, site_prefix_length: int|null, vlan_prefix_length: int, gateway_position: string, ranges: list<SchemeRange>}
     */
    public function toArray(): array
    {
        return [
            'network' => $this->network,
            'prefix_length' => $this->prefix,
            'site_prefix_length' => $this->sitePrefix,
            'vlan_prefix_length' => $this->vlanPrefix,
            'gateway_position' => $this->gateway->value,
            'ranges' => $this->ranges,
        ];
    }

    /**
     * A prefix in CIDR notation: "10.20.0.0/16".
     *
     * @param  Prefix  $prefix
     */
    public static function cidr(array $prefix): string
    {
        return $prefix['network'].'/'.$prefix['prefix_length'];
    }
}
