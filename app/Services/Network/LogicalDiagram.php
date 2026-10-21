<?php

// torsade/app/Services/Network/LogicalDiagram.php

namespace App\Services\Network;

use App\Enums\DeviceType;
use App\Enums\PortMode;
use App\Enums\Status;
use App\Enums\SubnetKind;
use App\Models\Device;
use App\Models\IpAddress;
use App\Models\PortVlan;
use App\Models\Site;
use App\Models\Subnet;
use App\Support\Ipv4;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The logical diagram of a site: its subnets drawn as bars, the internet,
 * and the devices plugged into those subnets, worked out from what is
 * declared and never stored. The page lays it out and draws it.
 *
 * Drawn are the subnets of the site, whatever their kind, and the subnets
 * without a site where a device of the site that is drawn has an address:
 * a carrier link, a tunnel, a point-to-point link. The internet is drawn as
 * soon as an external subnet is, linked to each of them. Every firewall,
 * router and switch of the site is drawn, even with no address nor VLAN;
 * any other device of the site when it has addresses in two subnets drawn,
 * or carries the VLAN of one on a port or an aggregate; and, at the edge,
 * the devices of other sites that have an address in a subnet drawn or
 * carry its VLAN. A device is plugged into a subnet once, whether by its
 * addresses there, by the VLAN it carries, or by both. What is retired is
 * left out: subnets, devices and addresses.
 *
 * Each connection is read from top to bottom, by rank: the internet; the
 * external, tunnel, point-to-point and loopback subnets; the firewalls and
 * the routers; the segments; the switches and the other devices. Subnets
 * are given in the order of their addresses, devices in the order of their
 * names: the same data always gives the same diagram.
 *
 * The number of queries does not depend on the number of subnets, of
 * devices nor of addresses.
 *
 * @phpstan-import-type Deviation from Deviations
 *
 * @phpstan-type LogicalSubnet array{key: string, id: int, cidr: string, name: string|null, kind: string, kind_label: string, rank: int, planned: bool, vlan: array{id: int, vid: int, name: string}|null, gateway: string|null, others: int, others_label: string|null, deviations: list<Deviation>}
 * @phpstan-type LogicalDevice array{key: string, id: int, name: string, type: string, type_label: string, rank: int, planned: bool, site: array{id: int, name: string}|null, members: int, deviations: list<Deviation>}
 * @phpstan-type LogicalAddress array{address: string, gateway: bool}
 * @phpstan-type Connection array{key: string, kind: string, source: string, target: string, subnet: string, device: string|null, addresses: list<LogicalAddress>, carried: string|null}
 * @phpstan-type LogicalDiagramData array{site: array{id: int, name: string, code: string|null}, internet: array{key: string, rank: int}|null, subnets: list<LogicalSubnet>, devices: list<LogicalDevice>, connections: list<Connection>, summary: array{subnets: int, devices: int, connections: int, deviations: int}}
 */
final class LogicalDiagram
{
    /**
     * The types of device drawn whether they are plugged into a subnet or
     * not: those that make the network.
     */
    public const array DRAWN_TYPES = [DeviceType::Firewall, DeviceType::Router, DeviceType::Switch];

    /**
     * The key of the node of the internet.
     */
    public const string INTERNET = 'internet';

    /**
     * The ranks, from the top of the diagram down.
     */
    public const int RANK_INTERNET = 0;

    public const int RANK_EDGE_SUBNETS = 1;

    public const int RANK_ROUTERS = 2;

    public const int RANK_SEGMENTS = 3;

    public const int RANK_DEVICES = 4;

    public function __construct(
        private readonly Deviations $deviations,
        private readonly DiagramDeviceDeviations $deviceDeviations,
    ) {}

    /**
     * @return LogicalDiagramData
     */
    public function of(Site $site): array
    {
        $retired = Status::Retired->value;
        $documented = ['ipAddresses as documented_count' => fn (Builder $query) => $query->where('status', '!=', $retired)];

        // The subnets of the site, whatever their kind.
        $subnets = Subnet::query()
            ->where('site_id', $site->id)
            ->where('status', '!=', $retired)
            ->with(['site', 'vlan.catalogVlan'])
            ->withCount($documented)
            ->get()
            ->keyBy('id')
            ->all();

        // The devices of the site, and where they have an address: the
        // subnets without a site among them are drawn when a device drawn
        // has an address there.
        $siteDevices = Device::query()
            ->where('site_id', $site->id)
            ->where('status', '!=', $retired)
            ->with('site')
            ->withCount('members')
            ->get()
            ->keyBy('id')
            ->all();

        $siteAddresses = IpAddress::query()
            ->whereIn('device_id', Device::query()->select('id')->where('site_id', $site->id))
            ->where('status', '!=', $retired)
            ->with(['subnet' => fn ($query) => $query->with(['site', 'vlan.catalogVlan'])->withCount($documented)])
            ->get();

        /** @var array<int, Subnet> $siteless */
        $siteless = [];
        /** @var array<int, array<int, true>> $subnetsOf */
        $subnetsOf = [];

        foreach ($siteAddresses as $address) {
            $subnet = $address->subnet;

            $subnetsOf[(int) $address->device_id][$subnet->id] = true;

            if ($subnet->site_id === null && $subnet->status !== Status::Retired) {
                $siteless[$subnet->id] = $subnet;
            }
        }

        // Who carries the VLANs of these subnets, on a port or an
        // aggregate, wherever the device is.
        $vlanSubnets = [];

        foreach ([...$subnets, ...$siteless] as $subnet) {
            if ($subnet->vlan_id !== null) {
                $vlanSubnets[$subnet->vlan_id] = $subnet->id;
            }
        }

        $lines = $vlanSubnets === [] ? [] : PortVlan::query()
            ->whereIn('vlan_id', array_keys($vlanSubnets))
            ->with([
                'port:id,device_id,mode',
                'aggregate' => fn ($query) => $query->select(['id', 'device_id', 'mode'])->withCount('ports'),
            ])
            ->get()
            ->all();

        /** @var array<int, array<int, list<PortVlan>>> $carried */
        $carried = [];

        foreach ($lines as $line) {
            $holder = $line->port ?? $line->aggregate;

            if ($holder !== null) {
                $carried[$holder->device_id][$vlanSubnets[$line->vlan_id]][] = $line;
            }
        }

        // The subnets without a site where a device drawn has an address:
        // a device drawn for them alone may no longer be, once one of them
        // is not, until nothing changes.
        do {
            $drawnSubnets = $subnets + $siteless;
            $drawnSite = array_filter($siteDevices, fn (Device $device): bool => $this->drawsDevice($device, $drawnSubnets, $subnetsOf, $carried));
            $kept = array_filter($siteless, fn (Subnet $subnet): bool => array_any(array_keys($drawnSite), fn (int $id): bool => isset($subnetsOf[$id][$subnet->id])));
            $changed = count($kept) !== count($siteless);
            $siteless = $kept;
        } while ($changed);

        $drawnSubnets = array_values($subnets + $siteless);

        usort($drawnSubnets, fn (Subnet $a, Subnet $b): int => Ipv4::compare($a->network, $b->network) ?: $a->prefix_length <=> $b->prefix_length);

        $drawnIds = array_map(fn (Subnet $subnet): int => $subnet->id, $drawnSubnets);
        $drawnSet = array_flip($drawnIds);

        // The addresses of devices in the subnets drawn: those of the site,
        // and those that bring a device of another site to the edge.
        $addresses = $drawnIds === [] ? [] : IpAddress::query()
            ->whereIn('subnet_id', $drawnIds)
            ->whereNotNull('device_id')
            ->where('status', '!=', $retired)
            ->with(['device' => fn ($query) => $query->with('site')->withCount('members')])
            ->get()
            ->all();

        /** @var array<int, Device> $devices */
        $devices = $drawnSite;
        /** @var array<int, array<int, list<IpAddress>>> $plugged */
        $plugged = [];

        foreach ($addresses as $address) {
            $device = $address->device;

            if ($device === null || $device->status === Status::Retired) {
                continue;
            }

            if ($device->site_id !== $site->id) {
                $devices[$device->id] ??= $device;
            }

            if (isset($devices[$device->id])) {
                $plugged[$device->id][$address->subnet_id][] = $address;
            }
        }

        // The devices of other sites that carry the VLAN of a subnet drawn.
        $carriers = [];

        foreach ($carried as $deviceId => $bySubnet) {
            if (! isset($devices[$deviceId]) && ! isset($siteDevices[$deviceId]) && array_intersect_key($bySubnet, $drawnSet) !== []) {
                $carriers[] = $deviceId;
            }
        }

        if ($carriers !== []) {
            foreach (Device::query()->whereKey($carriers)->where('status', '!=', $retired)->with('site')->withCount('members')->get() as $device) {
                $devices[$device->id] = $device;
            }
        }

        $connections = [];

        foreach ($devices as $device) {
            foreach ($drawnSubnets as $subnet) {
                $own = $plugged[$device->id][$subnet->id] ?? [];
                $how = $carried[$device->id][$subnet->id] ?? [];

                if ($own === [] && $how === []) {
                    continue;
                }

                $connections[] = $this->connection($device, $subnet, $own, $how);
            }
        }

        // What each bar counts: the addresses documented there that belong
        // to no device drawn.
        $taken = [];

        foreach ($plugged as $bySubnet) {
            foreach ($bySubnet as $subnetId => $own) {
                $taken[$subnetId] = ($taken[$subnetId] ?? 0) + count($own);
            }
        }

        $siteBlocks = $this->deviations->siteBlocks();
        $scheme = $this->deviations->scheme();

        $logicalSubnets = array_map(function (Subnet $subnet) use ($taken, $siteBlocks, $scheme): array {
            $others = max(0, (int) $subnet->getAttribute('documented_count') - ($taken[$subnet->id] ?? 0));
            $vlan = $subnet->vlan;

            return [
                'key' => 'subnet-'.$subnet->id,
                'id' => $subnet->id,
                'cidr' => $subnet->cidr(),
                'name' => $subnet->name,
                'kind' => $subnet->kind->value,
                'kind_label' => (string) __($subnet->kind->label()),
                'rank' => self::subnetRank($subnet),
                'planned' => $subnet->status === Status::Planned,
                'vlan' => $vlan === null ? null : ['id' => $vlan->id, 'vid' => $vlan->vid, 'name' => $vlan->name],
                'gateway' => $subnet->gateway,
                'others' => $others,
                'others_label' => $others === 0 ? null : trans_choice(':count other address|:count other addresses', $others, ['count' => $others]),
                'deviations' => [
                    ...$this->deviations->ofSubnet($subnet, $siteBlocks, $scheme),
                    ...($vlan === null ? [] : $this->deviations->ofVlan($vlan)),
                ],
            ];
        }, $drawnSubnets);

        $drawnDevices = array_values($devices);

        usort($drawnDevices, fn (Device $a, Device $b): int => strnatcasecmp($a->name, $b->name) ?: $a->id <=> $b->id);

        $deviceDeviations = $this->deviceDeviations->of(array_values(array_filter($drawnDevices, fn (Device $device): bool => $device->site_id === $site->id)));

        $logicalDevices = array_map(fn (Device $device): array => [
            'key' => 'device-'.$device->id,
            'id' => $device->id,
            'name' => $device->name,
            'type' => $device->type->value,
            'type_label' => (string) __($device->type->label()),
            'rank' => self::deviceRank($device),
            'planned' => $device->status === Status::Planned,
            'site' => $device->site_id === $site->id ? null : ['id' => $device->site->id, 'name' => $device->site->name],
            'members' => (int) ($device->members_count ?? 0),
            'deviations' => $deviceDeviations[$device->id] ?? [],
        ], $drawnDevices);

        // The connections in the order of the subnets, then of the devices;
        // the internet first.
        $subnetOrder = array_flip(array_map(fn (array $subnet): string => $subnet['key'], $logicalSubnets));
        $deviceOrder = array_flip(array_map(fn (array $device): string => $device['key'], $logicalDevices));

        usort($connections, fn (array $a, array $b): int => [$subnetOrder[$a['subnet']], $deviceOrder[$a['device']]] <=> [$subnetOrder[$b['subnet']], $deviceOrder[$b['device']]]);

        $external = array_values(array_filter($drawnSubnets, fn (Subnet $subnet): bool => $subnet->kind === SubnetKind::External));

        $internetLinks = array_map(fn (Subnet $subnet): array => [
            'key' => 'internet-'.$subnet->id,
            'kind' => 'internet',
            'source' => self::INTERNET,
            'target' => 'subnet-'.$subnet->id,
            'subnet' => 'subnet-'.$subnet->id,
            'device' => null,
            'addresses' => [],
            'carried' => null,
        ], $external);

        return [
            'site' => ['id' => $site->id, 'name' => $site->name, 'code' => $site->code],
            'internet' => $external === [] ? null : ['key' => self::INTERNET, 'rank' => self::RANK_INTERNET],
            'subnets' => $logicalSubnets,
            'devices' => $logicalDevices,
            'connections' => [...$internetLinks, ...$connections],
            'summary' => [
                'subnets' => count($logicalSubnets),
                'devices' => count($logicalDevices),
                'connections' => count($connections),
                'deviations' => array_sum(array_map(fn (array $subnet): int => count($subnet['deviations']), $logicalSubnets))
                    + array_sum(array_map(fn (array $device): int => count($device['deviations']), $logicalDevices)),
            ],
        ];
    }

    /**
     * The keys of the nodes of a diagram worked out: the internet, the
     * subnets and the devices.
     *
     * @param  LogicalDiagramData  $diagram
     * @return list<string>
     */
    public static function nodes(array $diagram): array
    {
        return [
            ...($diagram['internet'] === null ? [] : [$diagram['internet']['key']]),
            ...array_column($diagram['subnets'], 'key'),
            ...array_column($diagram['devices'], 'key'),
        ];
    }

    /**
     * Whether a device of the site is drawn: a firewall, a router or a
     * switch always; any other device when it has addresses in two
     * subnets drawn, or carries the VLAN of one.
     *
     * @param  array<int, Subnet>  $drawn
     * @param  array<int, array<int, true>>  $subnetsOf
     * @param  array<int, array<int, list<PortVlan>>>  $carried
     */
    private function drawsDevice(Device $device, array $drawn, array $subnetsOf, array $carried): bool
    {
        return in_array($device->type, self::DRAWN_TYPES, true)
            || count(array_intersect_key($subnetsOf[$device->id] ?? [], $drawn)) >= 2
            || array_intersect_key($carried[$device->id] ?? [], $drawn) !== [];
    }

    /**
     * A device plugged into a subnet, from the higher rank to the lower:
     * its addresses there, the gateway marked, and how it carries the VLAN
     * of the subnet, if it does.
     *
     * @param  list<IpAddress>  $addresses
     * @param  list<PortVlan>  $lines
     * @return array{key: string, kind: string, source: string, target: string, subnet: string, device: string, addresses: list<LogicalAddress>, carried: string|null}
     */
    private function connection(Device $device, Subnet $subnet, array $addresses, array $lines): array
    {
        usort($addresses, fn (IpAddress $a, IpAddress $b): int => Ipv4::compare($a->address, $b->address));

        $deviceKey = 'device-'.$device->id;
        $subnetKey = 'subnet-'.$subnet->id;
        $downwards = self::subnetRank($subnet) < self::deviceRank($device);

        return [
            'key' => 'connection-'.$device->id.'-'.$subnet->id,
            'kind' => 'device',
            'source' => $downwards ? $subnetKey : $deviceKey,
            'target' => $downwards ? $deviceKey : $subnetKey,
            'subnet' => $subnetKey,
            'device' => $deviceKey,
            'addresses' => array_map(fn (IpAddress $address): array => [
                'address' => $address->address,
                'gateway' => $subnet->gateway !== null && $address->address === $subnet->gateway,
            ], $addresses),
            'carried' => $this->carried($lines),
        ];
    }

    /**
     * How a device carries a VLAN, port by port: "access on 24 ports,
     * tagged on 2 ports". An aggregate counts for each of its ports.
     *
     * @param  list<PortVlan>  $lines
     */
    private function carried(array $lines): ?string
    {
        if ($lines === []) {
            return null;
        }

        $counts = ['access' => 0, 'voice' => 0, 'native' => 0, 'tagged' => 0];

        foreach ($lines as $line) {
            $holder = $line->port ?? $line->aggregate;
            $ports = $line->aggregate !== null ? (int) $line->aggregate->getAttribute('ports_count') : 1;
            $trunk = $holder?->mode === PortMode::Trunk;

            $counts[$trunk ? ($line->tagged ? 'tagged' : 'native') : ($line->tagged ? 'voice' : 'access')] += $ports;
        }

        $parts = [];

        foreach ($counts as $how => $count) {
            if ($count === 0) {
                continue;
            }

            $parts[] = match ($how) {
                'access' => trans_choice('access on :count port|access on :count ports', $count, ['count' => $count]),
                'voice' => trans_choice('voice on :count port|voice on :count ports', $count, ['count' => $count]),
                'native' => trans_choice('native on :count port|native on :count ports', $count, ['count' => $count]),
                'tagged' => trans_choice('tagged on :count port|tagged on :count ports', $count, ['count' => $count]),
            };
        }

        return Str::ucfirst(implode(', ', $parts));
    }

    /**
     * How high a subnet stands: the external, tunnel, point-to-point and
     * loopback subnets above the firewalls and the routers, the segments
     * below them.
     */
    private static function subnetRank(Subnet $subnet): int
    {
        return $subnet->kind === SubnetKind::Segment ? self::RANK_SEGMENTS : self::RANK_EDGE_SUBNETS;
    }

    /**
     * How high a device stands: the firewalls and the routers above the
     * segments, the switches and any other device below them.
     */
    private static function deviceRank(Device $device): int
    {
        return in_array($device->type, [DeviceType::Firewall, DeviceType::Router], true) ? self::RANK_ROUTERS : self::RANK_DEVICES;
    }
}
