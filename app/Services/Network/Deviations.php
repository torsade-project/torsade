<?php

// torsade/app/Services/Network/Deviations.php

namespace App\Services\Network;

use App\Enums\DeviationKind;
use App\Enums\DeviceType;
use App\Enums\SubnetKind;
use App\Models\AddressBlock;
use App\Models\AddressingRule;
use App\Models\Device;
use App\Models\IpAddress;
use App\Models\Port;
use App\Models\PortAggregate;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\Vlan;
use App\Services\Network\Concerns\PhrasesMessages;
use App\Support\AddressingScheme;
use App\Support\Ipv4;
use Illuminate\Support\Collection;

/**
 * Where what is documented differs from the standard of the organisation:
 * a VLAN that is not numbered or named like its entry of the catalogue, a
 * subnet that is not where the blocks of its site say, a site or a subnet
 * that is not what the addressing rule expects, an address attached to a
 * device of another site, a switch that merges two VLANs of one number.
 *
 * Nothing here is stored and nothing is refused: the network is documented
 * as it is, and a deviation only makes its imperfection visible. Each one
 * comes with its reason in words.
 *
 * The methods work on objects already read: they never query one object at
 * a time, so that a page costs the same whatever it lists.
 *
 * @phpstan-type Deviation array{kind: string, reason: string}
 */
final class Deviations
{
    use PhrasesMessages;

    /**
     * How many blocks of a site a reason names before it says "and N more".
     */
    private const int BLOCKS_NAMED = 3;

    /**
     * How a VLAN differs from the entry of the catalogue it is attached to.
     * The entry must be loaded with the VLAN.
     *
     * @return list<Deviation>
     */
    public function ofVlan(Vlan $vlan): array
    {
        $entry = $vlan->catalogVlan;

        if ($entry === null) {
            return [];
        }

        $deviations = [];

        if ($vlan->vid !== $entry->vid) {
            $deviations[] = $this->deviation(
                DeviationKind::VlanVid,
                __('VID :vid, catalogue: :expected', ['vid' => $vlan->vid, 'expected' => $entry->vid]),
            );
        }

        // "Serveurs" and "SERVEURS" are the same name.
        if (mb_strtolower($vlan->name) !== mb_strtolower($entry->name)) {
            $deviations[] = $this->deviation(
                DeviationKind::VlanName,
                __('Name :name, catalogue: :expected', ['name' => $vlan->name, 'expected' => $entry->name]),
            );
        }

        return $deviations;
    }

    /**
     * How a site stands with regard to the addressing rule: its number is
     * beyond what the rule reaches, or the block the rule expects for it is
     * not declared. The blocks of the site must be loaded.
     *
     * @return list<Deviation>
     */
    public function ofSite(Site $site, ?AddressingScheme $scheme): array
    {
        // Without a level of sites, the rule uses no number and expects no
        // block of a site.
        if ($scheme === null || ! $scheme->hasSiteLevel() || $site->number === null) {
            return [];
        }

        $expected = $scheme->siteBlock($site->number);

        if ($expected === null) {
            return [$this->deviation(
                DeviationKind::SiteNumberOutsideRule,
                __('Number :number: the rule allows :min to :max', ['number' => $site->number, 'min' => 0, 'max' => $scheme->maxSiteNumber()]),
            )];
        }

        $declared = $site->addressBlocks->contains(
            fn (AddressBlock $block): bool => $block->network === $expected['network'] && $block->prefix_length === $expected['prefix_length'],
        );

        return $declared ? [] : [$this->deviation(
            DeviationKind::SiteBlockMissing,
            __('Block expected by the rule: :block', ['block' => AddressingScheme::cidr($expected)]),
        )];
    }

    /**
     * How a subnet differs from the standard of the organisation: where it
     * lies among the blocks of the sites, and whether it is the subnet the
     * addressing rule expects its VLAN to carry.
     *
     * @param  Collection<int, AddressBlock>  $siteBlocks  Every block that belongs to a site, with its site.
     * @param  AddressingScheme|null  $scheme  What the rule works out. With it, the VLAN and the site of the subnet must be loaded.
     * @return list<Deviation>
     */
    public function ofSubnet(Subnet $subnet, Collection $siteBlocks, ?AddressingScheme $scheme = null): array
    {
        return [...$this->amongBlocks($subnet, $siteBlocks), ...$this->againstRule($subnet, $scheme)];
    }

    /**
     * Whether a subnet is the one the addressing rule expects. Only a
     * segment carried by a VLAN is expected anywhere, and only when the
     * rule reaches the number of its site and its VID: beyond, the rule
     * says nothing, and nothing differs from it.
     *
     * The VLAN and the site of the subnet must be loaded.
     *
     * @return list<Deviation>
     */
    public function againstRule(Subnet $subnet, ?AddressingScheme $scheme): array
    {
        if ($scheme === null || $subnet->kind !== SubnetKind::Segment || $subnet->vlan === null) {
            return [];
        }

        $expected = $scheme->vlanSubnet($subnet->site?->number, $subnet->vlan->vid);

        if ($expected === null || ($expected['network'] === $subnet->network && $expected['prefix_length'] === $subnet->prefix_length)) {
            return [];
        }

        return [$this->deviation(
            DeviationKind::SubnetDiffersFromRule,
            __('Expected by the rule: :subnet', ['subnet' => AddressingScheme::cidr($expected)]),
        )];
    }

    /**
     * How a subnet is placed with regard to the blocks of the sites.
     *
     * @param  Collection<int, AddressBlock>  $siteBlocks  Every block that belongs to a site, with its site.
     * @return list<Deviation>
     */
    private function amongBlocks(Subnet $subnet, Collection $siteBlocks): array
    {
        // The blocks of sites never overlap: one of them at most holds the
        // subnet.
        $holder = $siteBlocks->first(fn (AddressBlock $block): bool => Ipv4::prefixContains(
            $block->network,
            $block->prefix_length,
            $subnet->network,
            $subnet->prefix_length,
        ));

        if ($subnet->site_id === null) {
            return $holder === null ? [] : [$this->deviation(
                DeviationKind::SubnetWithoutSiteInSiteBlock,
                __('No site, in the block :block of the site :site', ['block' => $holder->cidr(), 'site' => $holder->site?->name]),
            )];
        }

        $deviations = [];

        if ($holder !== null && $holder->site_id !== $subnet->site_id) {
            $deviations[] = $this->deviation(
                DeviationKind::SubnetInBlockOfAnotherSite,
                __('In the block :block of the site :site', ['block' => $holder->cidr(), 'site' => $holder->site?->name]),
            );
        }

        // Only a segment is expected in the blocks of its site. A link to a
        // carrier, a loopback, a tunnel or outside addresses are often, by
        // design, somewhere else: the addresses of the carrier, a block the
        // organisation keeps for its links.
        if ($subnet->kind !== SubnetKind::Segment) {
            return $deviations;
        }

        $ownBlocks = $siteBlocks->filter(fn (AddressBlock $block): bool => $block->site_id === $subnet->site_id);

        if ($ownBlocks->isNotEmpty() && ($holder === null || $holder->site_id !== $subnet->site_id)) {
            // The blocks are named: the reader sees at once whether the
            // subnet is misplaced or the block was declared too small.
            $deviations[] = $this->deviation(
                DeviationKind::SubnetOutsideSiteBlocks,
                __('Outside the blocks of its site (:blocks)', [
                    'blocks' => $this->enumerate(
                        $ownBlocks->map(fn (AddressBlock $block): string => $block->cidr())->values()->all(),
                        self::BLOCKS_NAMED,
                    ),
                ]),
            );
        }

        return $deviations;
    }

    /**
     * The same deviation seen from the other side: the subnets of other
     * sites that lie in the blocks of a site. Subnets without a site are
     * left out: they are told on their own page and on the page of the
     * block.
     *
     * @param  Collection<int, AddressBlock>  $blocks  The blocks of the site.
     * @param  Collection<int, Subnet>  $subnets  The subnets that lie in those blocks, each with its site.
     * @return list<array{subnet: Subnet, deviations: list<Deviation>}>
     */
    public function ofForeignSubnetsIn(Collection $blocks, Collection $subnets): array
    {
        $found = [];

        foreach ($subnets as $subnet) {
            $holder = $blocks->first(fn (AddressBlock $block): bool => Ipv4::prefixContains(
                $block->network,
                $block->prefix_length,
                $subnet->network,
                $subnet->prefix_length,
            ));

            if ($holder === null || $subnet->site_id === null || $subnet->site_id === $holder->site_id) {
                continue;
            }

            $found[] = [
                'subnet' => $subnet,
                'deviations' => [$this->deviation(
                    DeviationKind::SubnetInBlockOfAnotherSite,
                    __('Subnet of the site :site, in the block :block of this site', ['site' => $subnet->site?->name, 'block' => $holder->cidr()]),
                )],
            ];
        }

        return $found;
    }

    /**
     * How an address stands with regard to the device it is attached to: an
     * interface is expected on a network of the site of its device, or on
     * the network of a VLAN the device carries, whatever its site. A subnet
     * without a site is on no site in particular: nothing differs. Then how
     * it stands with regard to the jack it is plugged into, when that jack
     * is loaded (see CablingDeviations::ofAddress()).
     *
     * The subnet of the address with its site and its VLAN, and its device
     * with its site, must be loaded; for a subnet that has a VLAN, the
     * device with what its ports and its aggregates carry, that VLAN at
     * least (see Device::carriesVlan()).
     *
     * @return list<Deviation>
     */
    public function ofAddress(IpAddress $address): array
    {
        $device = $address->device;
        $site = $address->subnet->site;

        // Those of its jack, when the jack is loaded with its path.
        $cabling = $address->relationLoaded('jack') ? (new CablingDeviations)->ofAddress($address) : [];

        if ($device === null || $site === null || $device->site_id === $site->id) {
            return $cabling;
        }

        // A device that carries the VLAN of the subnet on one of its ports
        // or its aggregates is plugged into that network, wherever it is: a
        // central firewall routes the VLANs of several sites.
        $vlanId = $address->subnet->vlan_id;

        if ($vlanId !== null && $device->carriesVlan($vlanId)) {
            return $cabling;
        }

        return [$this->deviation(
            DeviationKind::AddressOnDeviceOfAnotherSite,
            __('Subnet of the site :subnet, device of the site :device', ['subnet' => $site->name, 'device' => $device->site->name]),
        ), ...$cabling];
    }

    /**
     * The VLANs a switch merges: two different VLANs under one number on
     * its ports and its aggregates, even on two different ports, are one
     * network for a switch, which has one table of VLANs. One deviation for
     * each number. A firewall or a router keeps them apart, a VLAN being
     * there a subinterface of one interface: nothing is told for them.
     *
     * The VLANs carried, each with its site, must be loaded with the ports
     * and the aggregates given.
     *
     * @param  iterable<Port|PortAggregate>  $holders  The ports and the aggregates of the device.
     * @return list<Deviation>
     */
    public function ofSwitch(Device $device, iterable $holders): array
    {
        $vlans = [];

        foreach ($holders as $holder) {
            foreach ($holder->portVlans as $line) {
                $vlans[] = $line->vlan;
            }
        }

        return $this->ofSwitchCarrying($device, $vlans);
    }

    /**
     * The VLANs a switch merges, from the VLANs its ports and its
     * aggregates carry, each with its site, given once or more.
     *
     * @param  iterable<Vlan>  $carried
     * @return list<Deviation>
     */
    public function ofSwitchCarrying(Device $device, iterable $carried): array
    {
        if ($device->type !== DeviceType::Switch) {
            return [];
        }

        /** @var array<int, array<int, Vlan>> $byNumber */
        $byNumber = [];

        foreach ($carried as $vlan) {
            $byNumber[$vlan->vid][$vlan->id] = $vlan;
        }

        ksort($byNumber);

        $deviations = [];

        foreach ($byNumber as $vid => $vlans) {
            if (count($vlans) < 2) {
                continue;
            }

            // The site of the switch first, then the others by name.
            $sites = array_map(fn (Vlan $vlan): Site => $vlan->site, array_values($vlans));

            usort($sites, fn (Site $a, Site $b): int => [$a->id !== $device->site_id, mb_strtolower($a->name), $a->id] <=> [$b->id !== $device->site_id, mb_strtolower($b->name), $b->id]);

            $deviations[] = $this->deviation(
                DeviationKind::SwitchMergesVlans,
                __('VLAN :vid of the sites :sites: :device merges them', [
                    'vid' => $vid,
                    'sites' => $this->listed(array_map(fn (Site $site): string => $site->name, $sites)),
                    'device' => $device->name,
                ]),
            );
        }

        return $deviations;
    }

    /**
     * Every block that belongs to a site, with its site: what ofSubnet()
     * needs, read once for a whole page.
     *
     * @return Collection<int, AddressBlock>
     */
    public function siteBlocks(): Collection
    {
        return AddressBlock::query()
            ->whereNotNull('site_id')
            ->with('site')
            ->orderBy('network')
            ->get();
    }

    /**
     * What the addressing rule of the organisation works out, read once
     * for a whole page, or null when there is no rule.
     */
    public function scheme(): ?AddressingScheme
    {
        $rule = AddressingRule::query()->with(['aggregateBlock', 'ranges'])->first();

        return $rule === null ? null : AddressingScheme::fromRule($rule);
    }

    /**
     * @return Deviation
     */
    private function deviation(DeviationKind $kind, string $reason): array
    {
        return ['kind' => $kind->value, 'reason' => $reason];
    }
}
