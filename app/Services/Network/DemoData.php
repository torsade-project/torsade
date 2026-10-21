<?php

// torsade/app/Services/Network/DemoData.php

namespace App\Services\Network;

use App\Casts\AsIpAddress;
use App\Enums\DiagramView;
use App\Models\AddressBlock;
use App\Models\AddressingRule;
use App\Models\Building;
use App\Models\CatalogVlan;
use App\Models\Device;
use App\Models\DeviceTemplate;
use App\Models\IpAddress;
use App\Models\Jack;
use App\Models\PatchPanelPort;
use App\Models\Port;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\Vlan;
use App\Support\Actor;
use App\Support\Ipv4;
use Illuminate\Support\Facades\DB;

/**
 * A fictitious network to try the application with: an invented company with
 * two sites and a third one being planned, in private address ranges, with
 * the VLAN catalogue, the address blocks and the addressing rule of an
 * organisation, the buildings, floors and rooms of its two sites, and the
 * devices kept in them. Its switches and its firewall have their members,
 * made from the generic templates, their ports with what they carry, and
 * aggregates of ports; they are linked to each other, the stack of the
 * servers by an aggregated link. Its rooms keep patch panels, its floors
 * have wall jacks cabled to them, the panels are patched to the switches,
 * workstations and telephones are plugged into the jacks by their
 * addresses, and access points and the switch of a meeting room by a jack
 * cord, each linked to the switch at the end of its panel. Its few
 * public addresses are taken from the ranges set aside for documentation.
 * Nothing here describes a real company, person, manufacturer or
 * addressing plan.
 *
 * A few things differ from its own standard on purpose, to show what a
 * deviation looks like; each one says so in its description.
 *
 * The objects are created through the same services as the interface, so
 * that they obey the same rules and appear in the activity log. The texts
 * are in French, like the networks the application is meant to document.
 */
final class DemoData
{
    private const string COMPANY = 'Tréfilerie des Trois Brins';

    public function __construct(
        private readonly Sites $sites,
        private readonly CatalogVlans $catalog,
        private readonly AddressBlocks $blocks,
        private readonly AddressingRules $rules,
        private readonly Vlans $vlans,
        private readonly Subnets $subnets,
        private readonly IpRanges $ipRanges,
        private readonly IpAddresses $ipAddresses,
        private readonly Places $places,
        private readonly Devices $devices,
        private readonly DeviceTemplates $templates,
        private readonly DeviceMembers $members,
        private readonly Ports $ports,
        private readonly PortAggregates $aggregates,
        private readonly Links $links,
        private readonly PatchPanels $patchPanels,
        private readonly Jacks $jacks,
        private readonly PatchCords $cords,
        private readonly JackCords $jackCords,
        private readonly DiagramLayouts $layouts,
    ) {}

    /**
     * Whether anything of the network documentation already exists.
     */
    public function networkDataExists(): bool
    {
        return Site::query()->exists()
            || CatalogVlan::query()->exists()
            || AddressBlock::query()->exists()
            || AddressingRule::query()->exists()
            || Vlan::query()->exists()
            || Subnet::query()->exists()
            || IpAddress::query()->exists()
            || Building::query()->exists()
            || Device::query()->exists()
            || DeviceTemplate::query()->exists();
    }

    /**
     * Create the whole demonstration network, or nothing.
     *
     * @return array{sites: int, catalog_vlans: int, address_blocks: int, addressing_rules: int, vlans: int, subnets: int, ip_ranges: int, ip_addresses: int, buildings: int, floors: int, rooms: int, devices: int, device_templates: int, device_members: int, ports: int, port_aggregates: int, links: int, patch_panels: int, jacks: int, patch_cords: int, jack_cords: int, diagram_positions: int}
     */
    public function create(Actor $actor): array
    {
        return DB::transaction(function () use ($actor): array {
            $counts = [
                'sites' => 0, 'catalog_vlans' => 0, 'address_blocks' => 0, 'addressing_rules' => 0, 'vlans' => 0, 'subnets' => 0, 'ip_ranges' => 0, 'ip_addresses' => 0,
                'buildings' => 0, 'floors' => 0, 'rooms' => 0, 'devices' => 0,
                'device_templates' => 0, 'device_members' => 0, 'ports' => 0, 'port_aggregates' => 0, 'links' => 0,
                'patch_panels' => 0, 'jacks' => 0, 'patch_cords' => 0, 'jack_cords' => 0, 'diagram_positions' => 0,
            ];

            // The standard of the organisation first: its VLAN catalogue
            // and the block all its sites are numbered in.
            $entries = [];

            foreach ($this->catalogPlans() as [$vid, $name, $description]) {
                $entries[$vid] = $this->catalog->create(['vid' => $vid, 'name' => $name, 'description' => $description], $actor);
                $counts['catalog_vlans']++;
            }

            $organisationBlock = null;

            foreach ($this->organisationBlockPlans() as $plan) {
                $block = $this->blocks->create($this->blockData($plan), $actor);

                // The rule starts from the first of them.
                $organisationBlock ??= $block;
                $counts['address_blocks']++;
            }

            // The addressing rule: 10.<site>.<VLAN>.0/24, the gateway on
            // the first address, and the usual way to cut a subnet.
            if ($organisationBlock !== null) {
                $this->rules->save([
                    'aggregate_block_id' => $organisationBlock->id,
                    'site_prefix_length' => 16,
                    'vlan_prefix_length' => 24,
                    'gateway_position' => 'first',
                    'ranges' => [
                        ['kind' => 'reserved', 'start_offset' => 2, 'end_offset' => 20, 'name' => 'Infrastructure'],
                        ['kind' => 'static', 'start_offset' => 21, 'end_offset' => 99, 'name' => 'Adresses fixes'],
                        ['kind' => 'dhcp', 'start_offset' => 100, 'end_offset' => 200, 'name' => null],
                    ],
                ], $actor);
                $counts['addressing_rules'] = 1;
            }

            $sites = [];
            // The VLANs of each site, by number: what its ports carry.
            $vlanIds = [];

            foreach ($this->sitePlans() as $plan) {
                $site = $this->sites->create([
                    'name' => $plan['name'],
                    'code' => $plan['code'],
                    // The rank of the site in the addressing rule, which
                    // its block follows.
                    'number' => $plan['number'],
                    'status' => $plan['status'] ?? 'active',
                    'description' => $plan['description'],
                ], $actor);
                $sites[$plan['name']] = $site;
                $counts['sites']++;

                foreach ($plan['blocks'] as $blockPlan) {
                    $this->blocks->create([...$this->blockData($blockPlan), 'site_id' => $site->id], $actor);
                    $counts['address_blocks']++;
                }

                foreach ($plan['vlans'] as $vlanPlan) {
                    $vlan = $this->vlans->create(
                        [
                            'site_id' => $site->id,
                            // The entry of the catalogue the VLAN follows, by its number.
                            'catalog_vlan_id' => isset($vlanPlan['catalog']) ? $entries[$vlanPlan['catalog']]->id : null,
                            'vid' => $vlanPlan['vid'],
                            'name' => $vlanPlan['name'],
                            'description' => $vlanPlan['description'] ?? null,
                            'status' => $vlanPlan['status'] ?? 'active',
                        ],
                        $this->subnetData($vlanPlan['subnet']),
                        $actor,
                    );
                    $counts['vlans']++;
                    $vlanIds[$plan['name']][$vlanPlan['vid']] = $vlan->id;

                    $this->fill($vlan->subnet()->firstOrFail(), $vlanPlan['subnet'], $actor, $counts);
                }
            }

            // Subnets that belong to no VLAN: Internet access, link between
            // the sites, tunnel, loopback. Each one has its kind, and its
            // site when it has one.
            foreach ($this->standaloneSubnetPlans() as $plan) {
                $subnet = $this->subnets->create([
                    ...$this->subnetData($plan),
                    'kind' => $plan['kind'],
                    'site_id' => isset($plan['site']) ? $sites[$plan['site']]->id : null,
                    'status' => 'active',
                ], $actor);

                $this->fill($subnet, $plan, $actor, $counts);
            }

            // Where things are: the buildings of each site, their floors
            // and their rooms. A room is known by its floor and its name.
            $rooms = [];
            $floors = [];

            foreach ($this->placePlans() as $siteName => $buildingPlans) {
                foreach ($buildingPlans as $buildingPlan) {
                    $building = $this->places->createBuilding($sites[$siteName], [
                        'name' => $buildingPlan['name'],
                        'code' => $buildingPlan['code'],
                        'description' => $buildingPlan['description'],
                    ], $actor);
                    $counts['buildings']++;

                    foreach ($buildingPlan['floors'] as [$level, $floorName, $roomPlans]) {
                        $floor = $this->places->createFloor($building, ['level' => $level, 'name' => $floorName], $actor);
                        $floors[$siteName][$floorName] = $floor;
                        $counts['floors']++;

                        foreach ($roomPlans as [$roomName, $technical, $description]) {
                            $rooms[$siteName][$floorName.' › '.$roomName] = $this->places->createRoom($floor, [
                                'name' => $roomName,
                                'is_technical' => $technical,
                                'description' => $description,
                            ], $actor);
                            $counts['rooms']++;
                        }
                    }
                }
            }

            // The devices, each one given the addresses that bear its name:
            // they become its interfaces, and keep their host name.
            $addresses = IpAddress::query()->whereNotNull('hostname')->orderBy('address')->get()->groupBy('hostname');
            $devices = [];

            foreach ($this->devicePlans() as $siteName => $devicePlans) {
                foreach ($devicePlans as $plan) {
                    $device = $this->devices->create([
                        'site_id' => $sites[$siteName]->id,
                        'room_id' => $plan['room'] === null ? null : $rooms[$siteName][$plan['room']]->id,
                        'name' => $plan['name'],
                        'type' => $plan['type'],
                        'manufacturer' => $plan['manufacturer'],
                        'model' => $plan['model'],
                        'serial_number' => $plan['serial_number'],
                        'status' => $plan['status'],
                        'description' => $plan['description'],
                    ], $actor);
                    $counts['devices']++;
                    $devices[$siteName][$plan['name']] = $device;

                    foreach ($plan['interfaces'] as $hostname) {
                        foreach ($addresses->get($hostname, []) as $address) {
                            $this->ipAddresses->attach($address, $device, $actor);
                        }
                    }
                }
            }

            // What the switches and the firewall are made of: the generic
            // templates, then the members of each device, whose ports are
            // copied from their template.
            $counts['device_templates'] = $this->templates->addGenerics($actor);

            $templates = DeviceTemplate::query()->get()->keyBy('name');
            [$switch24, $switch48, $switch8, $firewall8] = array_column($this->templates->generics(), 'name');

            // The ports of each device that has some, by name: the links
            // are made between them.
            $portIds = [];

            foreach ($this->portPlans($switch24, $switch48, $switch8, $firewall8) as $siteName => $devicePlans) {
                foreach ($devicePlans as $deviceName => $plan) {
                    $device = $devices[$siteName][$deviceName];

                    foreach ($plan['members'] as [$position, $role, $template]) {
                        $this->members->add($device, [
                            'position' => $position,
                            'role' => $role,
                            'device_template_id' => $templates[$template]->id,
                            // A serial number that says what it is.
                            'serial_number' => 'DEMO-'.strtoupper(substr(md5($deviceName.'-'.$position), 0, 8)),
                        ], $actor);
                        $counts['device_members']++;
                    }

                    // The ports of the device, by name: a name made of
                    // digits only is a number once it is a key.
                    /** @var array<int|string, Port> $ports */
                    $ports = [];

                    foreach ($device->ports()->get() as $port) {
                        $ports[$port->name] = $port;
                    }

                    $counts['ports'] += count($ports);

                    $ids = fn (array $names): array => array_values(array_map(fn (string $name): int => $ports[$name]->id, $names));

                    // The same mode and the same VLANs for a series of
                    // ports, as an editor sets them from a selection.
                    foreach ($plan['carried'] as [$names, $mode, $untagged, $tagged]) {
                        $this->ports->setMode($device, $ids($names), $this->carriage($vlanIds, $siteName, $mode, $untagged, $tagged), $actor);
                    }

                    foreach ($plan['aggregates'] as [$name, $protocol, $names, $mode, $untagged, $tagged, $description]) {
                        $this->aggregates->create($device, [
                            'name' => $name,
                            'protocol' => $protocol,
                            'description' => $description,
                            'port_ids' => $ids($names),
                            ...$this->carriage($vlanIds, $siteName, $mode, $untagged, $tagged),
                        ], $actor);
                        $counts['port_aggregates']++;
                    }

                    // What is plugged into a port, and the ports that are
                    // shut down, with the reason.
                    foreach ($plan['described'] as $name => [$description, $enabled]) {
                        $this->describe($ports[$name], $description, $enabled, $actor);
                    }

                    foreach ($ports as $name => $port) {
                        $portIds[$deviceName][(string) $name] = $port->id;
                    }
                }
            }

            // The access points have one port, their uplink, added as an
            // editor adds a series of one port: the device gets its first
            // member with it. The port says what it leads to.
            foreach ($this->uplinkPlans() as $siteName => $uplinks) {
                foreach ($uplinks as $deviceName => $description) {
                    $device = $devices[$siteName][$deviceName];

                    $counts['ports'] += $this->members->addPorts($device, null, [
                        'name_pattern' => 'eth0',
                        'first_number' => 1,
                        'last_number' => 1,
                        'connector' => 'rj45',
                        'max_speed' => '1g',
                        'poe_type' => 'none',
                    ], $actor);
                    $counts['device_members']++;

                    $port = $device->ports()->sole();
                    $this->describe($port, $description, true, $actor);
                    $portIds[$deviceName]['eth0'] = $port->id;
                }
            }

            // What is plugged into what: each link goes from ports of a
            // device to as many ports of another one, as an editor links
            // them from the page of the first.
            $byName = [];

            foreach ($devices as $devicesOfSite) {
                $byName = [...$byName, ...$devicesOfSite];
            }

            foreach ($this->linkPlans() as [$from, $names, $to, $facing, $medium, $status, $description]) {
                $counts['links'] += $this->links->create(
                    $byName[$from],
                    array_map(fn (string $name): int => $portIds[$from][$name], $names),
                    $byName[$to]->id,
                    array_map(fn (string $name): int => $portIds[$to][$name], $facing),
                    ['medium' => $medium, 'status' => $status, 'description' => $description],
                    $actor,
                );
            }

            // The cabling: the patch panels of the rooms, then the wall
            // jacks of each floor labelled as series and cabled to a panel,
            // then the cords from the panels to the switches.
            $panels = [];

            foreach ($this->panelPlans() as $siteName => $panelPlans) {
                foreach ($panelPlans as [$room, $name, $portCount, $connector, $category, $description]) {
                    $panels[$siteName][$name] = $this->patchPanels->create($rooms[$siteName][$room], [
                        'name' => $name,
                        'port_count' => $portCount,
                        'connector' => $connector,
                        'category' => $category,
                        'status' => 'active',
                        'description' => $description,
                    ], $actor);
                    $counts['patch_panels']++;
                }
            }

            foreach ($this->jackPlans() as $siteName => $jackPlans) {
                foreach ($jackPlans as [$floorName, $prefix, $first, $last, $panel, $firstPort]) {
                    $counts['jacks'] += $this->jacks->createSeries($floors[$siteName][$floorName], [
                        'prefix' => $prefix,
                        'suffix' => '',
                        'first_number' => $first,
                        'last_number' => $last,
                        'digits' => 2,
                        'patch_panel_id' => $panel === null ? null : $panels[$siteName][$panel]->id,
                        'first_port' => $firstPort,
                        'status' => 'active',
                    ], $actor);
                }
            }

            foreach ($this->cordPlans() as [$siteName, $panel, $first, $last, $device, $firstPort, $color, $description]) {
                $counts['patch_cords'] += $this->cords->create(
                    $panels[$siteName][$panel],
                    array_values(PatchPanelPort::query()->where('patch_panel_id', $panels[$siteName][$panel]->id)->whereBetween('number', [$first, $last])->orderBy('number')->pluck('id')->all()),
                    $byName[$device]->id,
                    $portIds[$device][$firstPort],
                    ['color' => $color, 'description' => $description],
                    $actor,
                );
            }

            // The jacks whose cabling differs from what is declared around
            // them say so.
            foreach ($this->jackNotes() as [$siteName, $floorName, $label, $description]) {
                $jack = Jack::query()->where('floor_id', $floors[$siteName][$floorName]->id)->where('label', $label)->sole();

                $this->jacks->update($jack, [
                    'label' => $jack->label,
                    'location' => $jack->location,
                    'patch_panel_port_id' => $jack->patch_panel_port_id,
                    'status' => $jack->status->value,
                    'description' => $description,
                ], $actor);
            }

            // The workstations and the telephones plugged into the jacks,
            // as an editor plugs them from the window of an address.
            foreach ($this->plugPlans() as [$siteName, $floorName, $label, $ips]) {
                $jack = Jack::query()->where('floor_id', $floors[$siteName][$floorName]->id)->where('label', $label)->sole();

                foreach ($ips as $ip) {
                    $this->plug(IpAddress::query()->where('address', AsIpAddress::toBinary($ip))->sole(), $jack, $actor);
                }
            }

            // The devices plugged into the jacks by a jack cord, on the side
            // of the desk, as an editor plugs them from the line of a port.
            foreach ($this->jackCordPlans() as [$siteName, $floorName, $label, $device, $port, $color, $description]) {
                $jack = Jack::query()->where('floor_id', $floors[$siteName][$floorName]->id)->where('label', $label)->sole();

                $this->jackCords->create($jack->id, $portIds[$device][$port], ['color' => $color, 'description' => $description], $actor);
                $counts['jack_cords']++;
            }

            // The diagram of the workshop, placed by hand as an editor
            // places it, once its cabling is there: every device and panel
            // drawn but one device, which shows the row of those not placed.
            // The head office keeps the automatic layout.
            $workshop = $sites['Atelier'];
            $layout = [];

            foreach ($this->workshopLayout() as $name => $position) {
                $layout['device-'.$byName[$name]->id] = $position;
            }

            foreach ($this->workshopPanelLayout() as $name => $position) {
                $layout['patch_panel-'.$panels['Atelier'][$name]->id] = $position;
            }

            $counts['diagram_positions'] = $this->layouts->saveNodes(
                $workshop,
                DiagramView::Physical,
                $layout,
                $this->layouts->ofNodes($workshop, DiagramView::Physical, [])['version'],
                $actor,
            );

            return $counts;
        });
    }

    /**
     * A mode and its VLANs, as the services of ports and of aggregates
     * expect them. A VLAN of the site of the device is given by its number,
     * a VLAN of another site by its site and its number.
     *
     * @param  array<string, array<int, int>>  $vlanIds  The VLANs of each site, by number.
     * @param  list<int|array{0: string, 1: int}>  $tagged
     * @return array{mode: string|null, untagged_vlan_id: int|null, tagged_vlan_ids: list<int>}
     */
    private function carriage(array $vlanIds, string $site, ?string $mode, ?int $untagged, array $tagged): array
    {
        $id = fn (int|array $vlan): int => is_array($vlan) ? $vlanIds[$vlan[0]][$vlan[1]] : $vlanIds[$site][$vlan];

        return [
            'mode' => $mode,
            'untagged_vlan_id' => $untagged === null ? null : $id($untagged),
            'tagged_vlan_ids' => array_map($id, $tagged),
        ];
    }

    /**
     * Plug an address into a jack: the address keeps everything else.
     */
    private function plug(IpAddress $address, Jack $jack, Actor $actor): void
    {
        $this->ipAddresses->update($address, [
            'address' => $address->address,
            'hostname' => $address->hostname,
            'mac_address' => $address->mac_address,
            'device_type' => $address->device_type?->value,
            'assignment' => $address->assignment->value,
            'status' => $address->status->value,
            'description' => $address->description,
            'jack_id' => $jack->id,
        ], $actor);
    }

    /**
     * Say what is plugged into a port, and whether it is enabled: the port
     * keeps everything else, its mode and what it carries included.
     */
    private function describe(Port $port, string $description, bool $enabled, Actor $actor): void
    {
        // As it is stored now: its mode was set since it was read.
        $port->refresh()->load(['portVlans.vlan', 'aggregate']);

        $this->ports->update($port, [
            'name' => $port->name,
            'position' => $port->position,
            'connector' => $port->connector->value,
            'max_speed' => $port->max_speed->value,
            'poe_type' => $port->poe_type->value,
            'poe_label' => $port->poe_label,
            'enabled' => $enabled,
            'description' => $description,
            'mode' => $port->mode?->value,
            'untagged_vlan_id' => $port->untaggedVlan()?->id,
            'tagged_vlan_ids' => array_map(fn (Vlan $vlan): int => $vlan->id, $port->taggedVlans()),
        ], $actor);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{network: string, prefix_length: int, gateway: string|null, name: string|null, description: string|null}
     */
    private function subnetData(array $plan): array
    {
        $cidr = Ipv4::parseCidr($plan['cidr']) ?? ['address' => $plan['cidr'], 'prefix' => 24];

        return [
            'network' => $cidr['address'],
            'prefix_length' => $cidr['prefix'],
            'gateway' => $plan['gateway'] ?? null,
            'name' => $plan['name'] ?? null,
            'description' => $plan['description'] ?? null,
        ];
    }

    /**
     * @param  array{cidr: string, name: string, description: string, status?: string}  $plan
     * @return array{network: string, prefix_length: int, name: string, description: string, status: string}
     */
    private function blockData(array $plan): array
    {
        $cidr = Ipv4::parseCidr($plan['cidr']) ?? ['address' => $plan['cidr'], 'prefix' => 16];

        return [
            'network' => $cidr['address'],
            'prefix_length' => $cidr['prefix'],
            'name' => $plan['name'],
            'description' => $plan['description'],
            'status' => $plan['status'] ?? 'active',
        ];
    }

    /**
     * Add the ranges and the addresses of a subnet.
     *
     * @param  array<string, mixed>  $plan
     * @param  array{sites: int, catalog_vlans: int, address_blocks: int, addressing_rules: int, vlans: int, subnets: int, ip_ranges: int, ip_addresses: int, buildings: int, floors: int, rooms: int, devices: int, device_templates: int, device_members: int, ports: int, port_aggregates: int, links: int, patch_panels: int, jacks: int, patch_cords: int, jack_cords: int, diagram_positions: int}  $counts
     */
    private function fill(Subnet $subnet, array $plan, Actor $actor, array &$counts): void
    {
        $counts['subnets']++;

        $ranges = [
            ...array_map(fn (array $range): array => ['dhcp', $range[0], $range[1], null, $range[2]], $plan['dhcp'] ?? []),
            ...($plan['ranges'] ?? []),
        ];

        foreach ($ranges as [$kind, $start, $end, $name, $description]) {
            $this->ipRanges->create($subnet, [
                'kind' => $kind,
                'start_address' => $start,
                'end_address' => $end,
                'name' => $name,
                'description' => $description,
            ], $actor);
            $counts['ip_ranges']++;
        }

        $lines = [];

        foreach ($plan['addresses'] ?? [] as $address) {
            [$ip, $hostname, $type] = $address;
            $status = $address['status'] ?? $subnet->status->value;

            $lines[] = [
                'address' => $ip,
                'hostname' => $hostname,
                // Nothing is plugged in yet for what is only planned.
                'mac_address' => $status === 'planned' ? null : $this->macFor($ip),
                'device_type' => $type,
                'assignment' => $address['assignment'] ?? 'static',
                'status' => $status,
                'description' => $address['description'] ?? null,
            ];
        }

        if ($lines !== []) {
            $counts['ip_addresses'] += count($this->ipAddresses->createMany($subnet, $lines, $actor));
        }
    }

    /**
     * A MAC address derived from the IP address, in the range reserved for
     * locally administered addresses: it belongs to no manufacturer.
     */
    private function macFor(string $ip): string
    {
        return '02:00:'.implode(':', array_map(
            fn (string $part): string => sprintf('%02x', (int) $part),
            explode('.', $ip),
        ));
    }

    /**
     * A workstation or a phone whose address is reserved on the DHCP server.
     *
     * @return array{0: string, 1: string, 2: string, assignment: string}
     */
    private function reserved(string $ip, string $hostname, string $type): array
    {
        return [$ip, $hostname, $type, 'assignment' => 'dhcp_reservation'];
    }

    /**
     * The VLANs the company has standardised: number, name, description.
     * Telephony is left out on purpose: both sites have a VLAN 30 that can
     * be attached to an entry created by hand.
     *
     * @return list<array{0: int, 1: string, 2: string}>
     */
    private function catalogPlans(): array
    {
        return [
            [10, 'Bureautique', 'Postes de travail et imprimantes des bureaux.'],
            [20, 'Serveurs', 'Serveurs et stockage.'],
            [40, 'Wi-Fi invités', 'Accès Internet des visiteurs, isolé du reste.'],
            [50, 'Vidéosurveillance', 'Caméras et enregistreurs.'],
            [60, 'Production', 'Automates et machines. Aucun accès à Internet.'],
            [99, 'Administration réseau', 'Interfaces d\'administration des équipements.'],
        ];
    }

    /**
     * @return list<array{cidr: string, name: string, description: string}>
     */
    private function organisationBlockPlans(): array
    {
        return [
            [
                'cidr' => '10.0.0.0/8',
                'name' => 'Adressage interne',
                'description' => 'Tout le réseau interne de '.self::COMPANY.' : un /16 par site, les liaisons entre sites en 10.255.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sitePlans(): array
    {
        return [
            [
                'name' => 'Siège',
                'code' => 'SIE',
                'number' => 10,
                'description' => 'Bureaux et salle serveurs de '.self::COMPANY.', société fictive. Données de démonstration.',
                'blocks' => [
                    ['cidr' => '10.10.0.0/16', 'name' => 'Réseaux du siège', 'description' => 'Un /24 par VLAN : 10.10.<VLAN>.0/24.'],
                ],
                'vlans' => [
                    [
                        'vid' => 5,
                        'name' => 'Ancien réseau',
                        'description' => 'Réseau à plat d\'avant la segmentation, conservé pour mémoire.',
                        'status' => 'retired',
                        'subnet' => [
                            'cidr' => '192.168.1.0/24',
                            'gateway' => '192.168.1.1',
                            'description' => 'Ancien plan d\'adressage, antérieur au bloc 10.10.0.0/16 du siège : écart conservé pour la démonstration.',
                            'addresses' => [
                                ['192.168.1.1', 'routeur-ancien', 'router', 'description' => 'Ancienne box, débranchée'],
                                ['192.168.1.10', 'srv-ancien-01', 'server', 'description' => 'Remplacé par srv-fichiers-01'],
                                ['192.168.1.50', 'imp-ancienne', 'printer'],
                            ],
                        ],
                    ],
                    [
                        'vid' => 10,
                        'catalog' => 10,
                        'name' => 'Bureautique',
                        'description' => 'Postes de travail et imprimantes des bureaux.',
                        'subnet' => [
                            'cidr' => '10.10.10.0/24',
                            'gateway' => '10.10.10.254',
                            'dhcp' => [['10.10.10.100', '10.10.10.199', 'Postes de travail']],
                            'addresses' => [
                                ['10.10.10.20', 'imp-accueil', 'printer'],
                                ['10.10.10.21', 'imp-compta', 'printer'],
                                ['10.10.10.22', 'imp-direction', 'printer'],
                                ['10.10.10.23', 'imp-etage-1', 'printer'],
                                ['10.10.10.24', 'copieur-etage-2', 'printer', 'description' => 'Copieur multifonction'],
                                ['10.10.10.30', 'pc-admin-01', 'workstation', 'description' => 'Poste d\'administration du réseau'],
                                $this->reserved('10.10.10.110', 'pc-accueil-01', 'workstation'),
                                $this->reserved('10.10.10.111', 'pc-compta-01', 'workstation'),
                                $this->reserved('10.10.10.112', 'pc-compta-02', 'workstation'),
                                $this->reserved('10.10.10.113', 'pc-direction-01', 'laptop'),
                                $this->reserved('10.10.10.114', 'pc-rh-01', 'workstation'),
                                $this->reserved('10.10.10.115', 'pc-be-01', 'workstation'),
                                $this->reserved('10.10.10.116', 'pc-be-02', 'workstation'),
                                $this->reserved('10.10.10.117', 'pc-be-03', 'workstation'),
                                $this->reserved('10.10.10.118', 'pc-achats-01', 'workstation'),
                                $this->reserved('10.10.10.119', 'pc-achats-02', 'workstation'),
                                $this->reserved('10.10.10.120', 'pc-qualite-01', 'workstation'),
                                $this->reserved('10.10.10.121', 'portable-commercial-01', 'laptop'),
                                $this->reserved('10.10.10.122', 'portable-commercial-02', 'laptop'),
                                $this->reserved('10.10.10.123', 'portable-direction-02', 'laptop'),
                            ],
                        ],
                    ],
                    [
                        'vid' => 20,
                        'catalog' => 20,
                        'name' => 'Serveurs',
                        'description' => 'Serveurs et stockage de la salle informatique.',
                        'subnet' => [
                            'cidr' => '10.10.20.0/24',
                            'gateway' => '10.10.20.254',
                            'addresses' => [
                                ['10.10.20.10', 'srv-annuaire-01', 'server', 'description' => 'Contrôleur de domaine'],
                                ['10.10.20.11', 'srv-annuaire-02', 'server', 'description' => 'Contrôleur de domaine secondaire'],
                                ['10.10.20.12', 'srv-fichiers-01', 'server', 'description' => 'Partages bureautiques'],
                                ['10.10.20.13', 'srv-erp-01', 'server', 'description' => 'Gestion de production'],
                                ['10.10.20.14', 'srv-sauvegarde-01', 'server'],
                                ['10.10.20.15', 'srv-impression-01', 'server'],
                                ['10.10.20.16', 'srv-messagerie-01', 'server'],
                                ['10.10.20.20', 'hyp-01', 'server', 'description' => 'Hyperviseur'],
                                ['10.10.20.21', 'hyp-02', 'server', 'description' => 'Hyperviseur'],
                                ['10.10.20.30', 'nas-01', 'nas', 'description' => 'Sauvegardes'],
                                ['10.10.20.31', 'nas-02', 'nas', 'status' => 'planned', 'description' => 'Réplication, à installer'],
                                ['10.10.20.40', 'srv-intranet-01', 'server', 'status' => 'retired', 'description' => 'Arrêté, remplacé par srv-erp-01'],
                            ],
                        ],
                    ],
                    [
                        'vid' => 30,
                        'name' => 'Téléphonie',
                        'description' => 'Postes téléphoniques et autocommutateur.',
                        'subnet' => [
                            'cidr' => '10.10.30.0/24',
                            'gateway' => '10.10.30.254',
                            'dhcp' => [['10.10.30.50', '10.10.30.199', 'Postes téléphoniques']],
                            'addresses' => [
                                ['10.10.30.10', 'ipbx-01', 'server', 'description' => 'Autocommutateur'],
                                $this->reserved('10.10.30.50', 'tel-accueil', 'ip_phone'),
                                $this->reserved('10.10.30.51', 'tel-compta-1', 'ip_phone'),
                                $this->reserved('10.10.30.52', 'tel-compta-2', 'ip_phone'),
                                $this->reserved('10.10.30.53', 'tel-direction', 'ip_phone'),
                                $this->reserved('10.10.30.54', 'tel-rh', 'ip_phone'),
                                $this->reserved('10.10.30.55', 'tel-be-1', 'ip_phone'),
                                $this->reserved('10.10.30.56', 'tel-be-2', 'ip_phone'),
                                $this->reserved('10.10.30.57', 'tel-commercial-1', 'ip_phone'),
                                $this->reserved('10.10.30.58', 'tel-commercial-2', 'ip_phone'),
                                $this->reserved('10.10.30.59', 'tel-salle-reunion', 'ip_phone'),
                            ],
                        ],
                    ],
                    [
                        'vid' => 40,
                        'catalog' => 40,
                        'name' => 'Wi-Fi invités',
                        'description' => 'Accès Internet des visiteurs, isolé du reste.',
                        'subnet' => [
                            'cidr' => '10.10.40.0/24',
                            'gateway' => '10.10.40.254',
                            'dhcp' => [['10.10.40.10', '10.10.40.250', 'Visiteurs']],
                        ],
                    ],
                    [
                        'vid' => 50,
                        'catalog' => 50,
                        'name' => 'Vidéosurveillance',
                        'description' => 'Caméras et enregistreur.',
                        'subnet' => [
                            'cidr' => '10.10.50.0/24',
                            'gateway' => '10.10.50.254',
                            'addresses' => [
                                ['10.10.50.10', 'nvr-01', 'server', 'description' => 'Enregistreur vidéo'],
                                ['10.10.50.11', 'cam-entree', 'camera'],
                                ['10.10.50.12', 'cam-accueil', 'camera'],
                                ['10.10.50.13', 'cam-parking-1', 'camera'],
                                ['10.10.50.14', 'cam-parking-2', 'camera'],
                                ['10.10.50.15', 'cam-quai', 'camera'],
                                // Planned on a jack whose port does not carry
                                // the VLAN of the cameras: a deviation.
                                ['10.10.50.16', 'cam-couloir-1', 'camera', 'status' => 'planned', 'description' => 'À poser, sur la prise B.10, brassée vers un port sans le VLAN des caméras : écart « VLAN non porté » conservé pour la démonstration.'],
                            ],
                        ],
                    ],
                    [
                        'vid' => 99,
                        'catalog' => 99,
                        'name' => 'Administration réseau',
                        'description' => 'Interfaces d\'administration des équipements.',
                        'subnet' => [
                            'cidr' => '10.10.99.0/24',
                            'gateway' => '10.10.99.254',
                            // A subnet cut into ranges: where the next
                            // device gets its address, and what is kept.
                            'ranges' => [
                                ['static', '10.10.99.1', '10.10.99.99', 'Équipements réseau', 'Switchs, bornes Wi-Fi et onduleurs.'],
                                ['reserved', '10.10.99.200', '10.10.99.249', 'Extension', 'Gardée pour le futur bâtiment.'],
                            ],
                            'addresses' => [
                                ['10.10.99.11', 'sw-coeur-01', 'switch'],
                                ['10.10.99.12', 'sw-coeur-02', 'switch'],
                                ['10.10.99.13', 'sw-etage-1', 'switch'],
                                ['10.10.99.14', 'sw-etage-2', 'switch'],
                                ['10.10.99.15', 'sw-serveurs', 'switch'],
                                ['10.10.99.31', 'ap-accueil', 'access_point'],
                                ['10.10.99.32', 'ap-etage-1', 'access_point'],
                                ['10.10.99.33', 'ap-etage-2', 'access_point'],
                                ['10.10.99.34', 'ap-salle-reunion', 'access_point'],
                                ['10.10.99.50', 'onduleur-01', 'other', 'description' => 'Onduleur de la baie'],
                                ['10.10.99.254', 'fw-siege', 'firewall', 'description' => 'Pare-feu, passerelle des VLAN du siège'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Atelier',
                'code' => 'ATE',
                'number' => 20,
                'description' => 'Site de production de '.self::COMPANY.', société fictive. Données de démonstration.',
                'blocks' => [
                    ['cidr' => '10.20.0.0/16', 'name' => 'Réseaux de l\'atelier', 'description' => 'Un /24 par VLAN : 10.20.<VLAN>.0/24.'],
                ],
                'vlans' => [
                    [
                        'vid' => 10,
                        'catalog' => 10,
                        'name' => 'Bureautique',
                        'description' => 'Postes des bureaux de l\'atelier.',
                        'subnet' => [
                            'cidr' => '10.20.10.0/24',
                            'gateway' => '10.20.10.254',
                            'dhcp' => [['10.20.10.100', '10.20.10.199', 'Postes de travail']],
                            'addresses' => [
                                ['10.20.10.20', 'imp-atelier', 'printer'],
                                $this->reserved('10.20.10.110', 'pc-chef-atelier-01', 'workstation'),
                                $this->reserved('10.20.10.111', 'pc-methodes-01', 'workstation'),
                                $this->reserved('10.20.10.112', 'pc-expedition-01', 'workstation'),
                                $this->reserved('10.20.10.113', 'pc-qualite-02', 'workstation'),
                            ],
                        ],
                    ],
                    [
                        'vid' => 30,
                        'name' => 'Téléphonie',
                        'subnet' => [
                            'cidr' => '10.20.30.0/24',
                            'gateway' => '10.20.30.254',
                            'dhcp' => [['10.20.30.50', '10.20.30.199', 'Postes téléphoniques']],
                            'addresses' => [
                                $this->reserved('10.20.30.50', 'tel-chef-atelier', 'ip_phone'),
                                $this->reserved('10.20.30.51', 'tel-expedition', 'ip_phone'),
                                $this->reserved('10.20.30.52', 'tel-quai', 'ip_phone'),
                                $this->reserved('10.20.30.53', 'tel-methodes', 'ip_phone'),
                                $this->reserved('10.20.30.54', 'tel-qualite', 'ip_phone'),
                                $this->reserved('10.20.30.55', 'tel-maintenance', 'ip_phone'),
                            ],
                        ],
                    ],
                    [
                        'vid' => 60,
                        'catalog' => 60,
                        'name' => 'Production',
                        'description' => 'Automates et machines. Aucun accès à Internet.',
                        'subnet' => [
                            'cidr' => '10.20.60.0/24',
                            'gateway' => '10.20.60.254',
                            'addresses' => [
                                ['10.20.60.10', 'automate-ligne-1', 'other', 'description' => 'Automate'],
                                ['10.20.60.11', 'automate-ligne-2', 'other', 'description' => 'Automate'],
                                ['10.20.60.12', 'automate-ligne-3', 'other', 'status' => 'planned', 'description' => 'Automate de la future ligne'],
                                ['10.20.60.20', 'pupitre-ligne-1', 'workstation', 'description' => 'Pupitre opérateur'],
                                ['10.20.60.21', 'pupitre-ligne-2', 'workstation', 'description' => 'Pupitre opérateur'],
                                ['10.20.60.30', 'trefileuse-01', 'other', 'description' => 'Machine à commande numérique'],
                                ['10.20.60.31', 'trefileuse-02', 'other', 'description' => 'Machine à commande numérique'],
                                ['10.20.60.32', 'etiqueteuse-01', 'printer'],
                                ['10.20.60.33', 'palettiseur-01', 'other'],
                                ['10.20.60.40', 'srv-supervision-01', 'server', 'description' => 'Supervision de la production'],
                                ['10.20.60.41', 'pc-maintenance-01', 'laptop'],
                                ['10.20.60.42', 'pc-maintenance-02', 'laptop', 'status' => 'planned'],
                                ['10.20.60.43', 'borne-pointage-01', 'other'],
                                // An interface of a server of the head
                                // office, here on the network of the
                                // workshop: the demonstration of a deviation.
                                ['10.20.60.50', 'srv-sauvegarde-01', 'server', 'description' => 'Seconde interface du serveur de sauvegarde du siège, branchée sur le réseau de production de l\'atelier : écart « équipement d\'un autre site », conservé pour la démonstration.'],
                            ],
                        ],
                    ],
                    [
                        'vid' => 70,
                        'name' => 'Extension atelier',
                        'description' => 'Réservé pour la future ligne de production.',
                        'status' => 'planned',
                        'subnet' => [
                            'cidr' => '10.20.70.0/24',
                            'gateway' => '10.20.70.254',
                        ],
                    ],
                    [
                        // Numbered 150 where the catalogue says 50: the
                        // demonstration of a deviation.
                        'vid' => 150,
                        'catalog' => 50,
                        'name' => 'Vidéosurveillance',
                        'description' => 'Caméras de l\'atelier. Numéroté 150 par l\'installateur au lieu du 50 du catalogue : écart de VID conservé pour la démonstration.',
                        'subnet' => [
                            'cidr' => '10.20.50.0/24',
                            'gateway' => '10.20.50.254',
                            // The rule expects 10.20.150.0/24 for the VLAN
                            // 150: the demonstration of that deviation.
                            'description' => 'Adressé d\'après le numéro 50 du catalogue, alors que le VLAN porte le 150 : écart avec la règle d\'adressage, conservé pour la démonstration.',
                            'ranges' => [
                                ['static', '10.20.50.10', '10.20.50.99', 'Caméras', null],
                            ],
                            'addresses' => [
                                ['10.20.50.10', 'nvr-atelier', 'server', 'description' => 'Enregistreur vidéo'],
                                ['10.20.50.11', 'cam-atelier-1', 'camera'],
                                ['10.20.50.12', 'cam-quai-atelier', 'camera'],
                            ],
                        ],
                    ],
                    [
                        'vid' => 99,
                        'catalog' => 99,
                        'name' => 'Administration réseau',
                        'description' => 'Interfaces d\'administration des équipements.',
                        'subnet' => [
                            'cidr' => '10.20.99.0/24',
                            'gateway' => '10.20.99.254',
                            'addresses' => [
                                ['10.20.99.11', 'sw-atelier-01', 'switch'],
                                ['10.20.99.12', 'sw-atelier-02', 'switch'],
                                ['10.20.99.13', 'sw-quai', 'switch'],
                                ['10.20.99.31', 'ap-atelier-1', 'access_point'],
                                ['10.20.99.32', 'ap-atelier-2', 'access_point'],
                                ['10.20.99.33', 'ap-quai', 'access_point'],
                                ['10.20.99.50', 'onduleur-atelier', 'other'],
                                ['10.20.99.254', 'fw-atelier', 'firewall', 'description' => 'Pare-feu, passerelle des VLAN de l\'atelier'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                // A site that is being planned: numbered, with its block,
                // and no VLAN yet. The standard is waiting to be applied.
                'name' => 'Dépôt',
                'code' => 'DEP',
                'number' => 30,
                'status' => 'planned',
                'description' => 'Futur entrepôt de '.self::COMPANY.', société fictive. Sans aucun VLAN : pour essayer « Appliquer la norme ». Données de démonstration.',
                'blocks' => [
                    ['cidr' => '10.30.0.0/16', 'name' => 'Réseaux du dépôt', 'description' => 'Bloc attendu par la règle d\'adressage pour le site numéro 30.', 'status' => 'planned'],
                ],
                'vlans' => [],
            ],
        ];
    }

    /**
     * The buildings of each site, each with its floors (level, name) and
     * the rooms of each floor (name, technical or not, description).
     *
     * @return array<string, list<array{name: string, code: string, description: string, floors: list<array{0: int, 1: string, 2: list<array{0: string, 1: bool, 2: string|null}>}>}>>
     */
    private function placePlans(): array
    {
        return [
            'Siège' => [
                [
                    'name' => 'Bâtiment principal',
                    'code' => 'A',
                    'description' => 'Bureaux et salle informatique.',
                    'floors' => [
                        [0, 'Rez-de-chaussée', [
                            ['Accueil', false, null],
                            ['Local technique', true, 'Baie 42U : cœur de réseau, pare-feu et serveurs.'],
                        ]],
                        // Each floor has its own technical room: a
                        // cupboard of the corridor with a wall rack, which
                        // keeps the switch of the floor and its panel.
                        [1, '1er étage', [
                            ['Bureaux', false, 'Comptabilité, achats et bureau d\'études.'],
                            ['Salle de réunion', false, null],
                            ['Placard technique', true, 'Sous-répartiteur de l\'étage : baie murale dans le placard du couloir.'],
                        ]],
                        [2, '2e étage', [
                            ['Bureaux', false, 'Ressources humaines et commerce.'],
                            ['Direction', false, null],
                            ['Placard technique', true, 'Sous-répartiteur de l\'étage : baie murale dans le placard du couloir.'],
                        ]],
                    ],
                ],
            ],
            'Atelier' => [
                [
                    'name' => 'Halle de production',
                    'code' => 'P',
                    'description' => 'Lignes de tréfilage, quai et bureaux en mezzanine.',
                    'floors' => [
                        [0, 'Rez-de-chaussée', [
                            ['Hall de production', false, 'Lignes de tréfilage.'],
                            ['Quai d\'expédition', false, null],
                            ['Local technique', true, 'Baie murale 12U.'],
                        ]],
                        [1, 'Mezzanine', [
                            ['Bureaux', false, 'Chef d\'atelier, méthodes et qualité.'],
                        ]],
                    ],
                ],
            ],
        ];
    }

    /**
     * One device of the demonstration.
     *
     * @param  string|null  $room  Its floor and its room, "Rez-de-chaussée › Local technique", or null when its place is not known.
     * @param  array{model?: string, interfaces?: list<string>, status?: string, description?: string, members?: bool}  $more  Its interfaces are the addresses that bear its name, unless other host names are given. A device that gets members has no serial number of its own: each member has one.
     * @return array{name: string, type: string, room: string|null, manufacturer: string|null, model: string|null, serial_number: string|null, status: string, description: string|null, interfaces: list<string>}
     */
    private function device(string $name, string $type, ?string $room, array $more = []): array
    {
        $model = $more['model'] ?? null;

        return [
            'name' => $name,
            'type' => $type,
            'room' => $room,
            // An invented manufacturer, and a serial number that says what
            // it is.
            'manufacturer' => $model === null ? null : 'Trois Brins Matériel',
            'model' => $model,
            'serial_number' => $model === null || ($more['members'] ?? false) ? null : 'DEMO-'.strtoupper(substr(md5($name), 0, 8)),
            'status' => $more['status'] ?? 'active',
            'description' => $more['description'] ?? null,
            'interfaces' => $more['interfaces'] ?? [$name],
        ];
    }

    /**
     * The devices of each site. Many host names are left without a device
     * on purpose: they are there to try "Create devices from host names".
     *
     * @return array<string, list<array{name: string, type: string, room: string|null, manufacturer: string|null, model: string|null, serial_number: string|null, status: string, description: string|null, interfaces: list<string>}>>
     */
    private function devicePlans(): array
    {
        $rack = 'Rez-de-chaussée › Local technique';

        return [
            'Siège' => [
                // One device, an interface in each network it routes.
                $this->device('fw-siege', 'firewall', $rack, [
                    'model' => 'PF-200',
                    'members' => true,
                    'interfaces' => ['fw-siege', 'fw-siege-wan', 'fw-siege-vpn', 'fw-siege-lo0'],
                    'description' => 'Pare-feu du siège : une adresse par réseau où il a une interface.',
                ]),
                // The two switches everything else is linked to.
                $this->device('sw-coeur-01', 'switch', $rack, ['model' => 'SW-48', 'members' => true]),
                $this->device('sw-coeur-02', 'switch', $rack, ['model' => 'SW-48', 'members' => true]),
                // A stack of two boxes, managed as one switch.
                $this->device('sw-serveurs', 'switch', $rack, [
                    'model' => 'SW-24',
                    'members' => true,
                    'description' => 'Stack de deux membres : les serveurs y sont raccordés sur les deux.',
                ]),
                // The switches of the floors, each in the technical room of
                // its floor, with the panel of its jacks.
                $this->device('sw-etage-1', 'switch', '1er étage › Placard technique', ['model' => 'SW-24', 'members' => true, 'description' => 'Dans la baie murale du sous-répartiteur, avec le panneau PB-B.']),
                // Declared without its address: "Create devices from host
                // names" proposes to attach it.
                $this->device('sw-etage-2', 'switch', '2e étage › Placard technique', [
                    'model' => 'SW-24',
                    'members' => true,
                    'interfaces' => [],
                    'description' => 'Déclaré sans son adresse : « Créer des équipements depuis les noms d\'hôte » propose de la lui rattacher.',
                ]),
                $this->device('hyp-01', 'server', $rack, ['model' => 'R-2U', 'description' => 'Hyperviseur']),
                $this->device('hyp-02', 'server', $rack, ['model' => 'R-2U', 'description' => 'Hyperviseur']),
                $this->device('srv-annuaire-01', 'server', $rack, ['description' => 'Machine virtuelle']),
                $this->device('srv-fichiers-01', 'server', $rack, ['description' => 'Machine virtuelle']),
                $this->device('srv-erp-01', 'server', $rack, ['description' => 'Machine virtuelle']),
                // Its second interface is on the network of the workshop:
                // the demonstration of a deviation.
                $this->device('srv-sauvegarde-01', 'server', $rack, ['model' => 'R-1U', 'description' => 'Sauvegarde les deux sites : une interface au siège, une à l\'atelier.']),
                $this->device('nas-01', 'nas', $rack, ['model' => 'N-8']),
                $this->device('nas-02', 'nas', $rack, ['model' => 'N-8', 'status' => 'planned', 'description' => 'Réplication, à installer']),
                $this->device('onduleur-01', 'other', $rack, ['model' => 'OND-3000']),
                $this->device('ap-accueil', 'access_point', 'Rez-de-chaussée › Accueil', ['model' => 'AP-6']),
                $this->device('ap-etage-1', 'access_point', '1er étage › Bureaux', ['model' => 'AP-6']),
                $this->device('ap-etage-2', 'access_point', '2e étage › Bureaux', ['model' => 'AP-6']),
                $this->device('ap-salle-reunion', 'access_point', '1er étage › Salle de réunion', ['model' => 'AP-6']),
                // A small switch under the table of the meeting room, plugged
                // into a wall jack.
                $this->device('sw-salle-reunion', 'switch', '1er étage › Salle de réunion', ['model' => 'SW-8', 'members' => true, 'interfaces' => [], 'description' => 'Sous la table de la salle de réunion, branché sur la prise B.15.']),
                $this->device('imp-accueil', 'printer', 'Rez-de-chaussée › Accueil', ['model' => 'L-A4']),
                $this->device('imp-compta', 'printer', '1er étage › Bureaux', ['model' => 'L-A4']),
                $this->device('imp-direction', 'printer', '2e étage › Direction', ['model' => 'L-A4']),
            ],
            'Atelier' => [
                $this->device('fw-atelier', 'firewall', $rack, [
                    'model' => 'PF-100',
                    'interfaces' => ['fw-atelier', 'fw-atelier-vpn'],
                    'description' => 'Pare-feu de l\'atelier.',
                ]),
                // The switch of the quay is linked to it.
                $this->device('sw-atelier-01', 'switch', $rack, ['model' => 'SW-24', 'members' => true]),
                $this->device('sw-atelier-02', 'switch', $rack, ['model' => 'SW-24']),
                // A switch is not always kept in a technical room.
                $this->device('sw-quai', 'switch', 'Rez-de-chaussée › Quai d\'expédition', ['model' => 'SW-8', 'members' => true, 'description' => 'Dans le coffret du quai.']),
                $this->device('ap-atelier-1', 'access_point', 'Rez-de-chaussée › Hall de production', ['model' => 'AP-6']),
                $this->device('ap-atelier-2', 'access_point', 'Rez-de-chaussée › Hall de production', ['model' => 'AP-6']),
                $this->device('ap-quai', 'access_point', 'Rez-de-chaussée › Quai d\'expédition', ['model' => 'AP-6']),
                $this->device('imp-atelier', 'printer', 'Mezzanine › Bureaux', ['model' => 'L-A4']),
                $this->device('srv-supervision-01', 'server', $rack, ['model' => 'R-1U', 'description' => 'Supervision de la production']),
                $this->device('nvr-atelier', 'server', $rack, ['description' => 'Enregistreur vidéo']),
                // A device whose place is not known.
                $this->device('onduleur-atelier', 'other', null),
            ],
        ];
    }

    /**
     * What the switches and the firewall are made of, site by site: the
     * members of each device with the template their ports are copied
     * from, what series of ports carry, the aggregates of ports, and what
     * is plugged into a few ports. A port that is shut down says why in
     * its description.
     *
     * The other devices have no member yet: they are there to try "Choose
     * a template" and "Add ports". What is plugged into what is told by
     * linkPlans().
     *
     * @return array<string, array<string, array{
     *     members: list<array{0: int, 1: string, 2: string}>,
     *     carried: list<array{0: list<string>, 1: string, 2: int|null, 3: list<int|array{0: string, 1: int}>}>,
     *     aggregates: list<array{0: string, 1: string, 2: list<string>, 3: string|null, 4: int|null, 5: list<int|array{0: string, 1: int}>, 6: string}>,
     *     described: array<int|string, array{0: string, 1: bool}>
     * }>>
     */
    private function portPlans(string $switch24, string $switch48, string $switch8, string $firewall8): array
    {
        // Every network of the head office: what its core carries.
        $office = [10, 20, 30, 40, 50];

        // The ports "<prefix><first>" to "<prefix><last>".
        $series = fn (string $prefix, int $first, int $last): array => array_map(fn (int $number): string => $prefix.$number, range($first, $last));

        return [
            'Siège' => [
                'fw-siege' => [
                    'members' => [[1, 'primary', $firewall8]],
                    'carried' => [
                        // Every network it routes, towards each of the
                        // two core switches.
                        [['port2', 'port4'], 'trunk', null, [...$office, 99]],
                    ],
                    'aggregates' => [],
                    'described' => [
                        'port1' => ['Accès Internet', true],
                        'port2' => ['Vers sw-coeur-01 : les réseaux du siège', true],
                        'port3' => ['Lien vers l\'atelier', true],
                        'port4' => ['Vers sw-coeur-02 : les réseaux du siège', true],
                        'port7' => ['Désactivé : non utilisé', false],
                        'port8' => ['Désactivé : non utilisé', false],
                    ],
                ],
                'sw-coeur-01' => [
                    'members' => [[1, 'primary', $switch48]],
                    'carried' => [
                        [['1/0/1'], 'trunk', null, [...$office, 99]],
                        [['1/1/3'], 'trunk', 99, [10, 30, 40]],
                        [['1/1/4'], 'trunk', 99, $office],
                    ],
                    'aggregates' => [
                        // The other end of the uplink of the stack.
                        ['Po1', 'lacp', ['1/1/1', '1/1/2'], 'trunk', 99, $office, 'Vers sw-serveurs : un lien vers chacun de ses membres'],
                    ],
                    'described' => [
                        '1/0/1' => ['Vers fw-siege', true],
                        '1/1/3' => ['Vers sw-etage-1', true],
                        '1/1/4' => ['Vers sw-coeur-02', true],
                    ],
                ],
                'sw-coeur-02' => [
                    'members' => [[1, 'primary', $switch48]],
                    'carried' => [
                        [['1/0/1'], 'trunk', null, [...$office, 99]],
                        // The VLAN of the guests is missing here: the link
                        // of this port shows it as a deviation.
                        [['1/1/1'], 'trunk', 99, [10, 30]],
                        [['1/1/2'], 'trunk', 99, [10, 30, 40]],
                        // The native VLAN is not the one of the other core
                        // switch: a deviation too.
                        [['1/1/4'], 'trunk', 5, $office],
                        // A fibre to the workshop, under study: the VLAN 60
                        // of the workshop, carried by a switch of the head
                        // office, and the VLAN 10 of the head office, which
                        // the link would merge with the one of the workshop.
                        [['1/1/3'], 'trunk', null, [10, ['Atelier', 60]]],
                    ],
                    'aggregates' => [],
                    'described' => [
                        '1/0/1' => ['Vers fw-siege', true],
                        '1/1/1' => ['Vers sw-etage-2', true],
                        '1/1/2' => ['Vers sw-etage-1 : second lien, prévu', true],
                        '1/1/3' => ['Vers sw-atelier-01 : fibre directe, à l\'étude', true],
                        '1/1/4' => ['Vers sw-coeur-01', true],
                    ],
                ],
                'sw-serveurs' => [
                    'members' => [[1, 'primary', $switch24], [2, 'secondary', $switch24]],
                    'carried' => [
                        // The servers, on both members.
                        [[...$series('1/0/', 1, 8), ...$series('2/0/', 1, 8)], 'access', 20, []],
                        // The hypervisors carry the networks of their
                        // virtual machines.
                        [[...$series('1/0/', 9, 12), ...$series('2/0/', 9, 12)], 'trunk', 99, [10, 20]],
                        [['1/0/24', '2/0/24'], 'access', 99, []],
                    ],
                    'aggregates' => [
                        // One link on each member: the stack keeps its
                        // uplink when a member fails.
                        ['Po1', 'lacp', ['1/1/1', '2/1/1'], 'trunk', 99, [10, 20, 30, 40, 50], 'Vers sw-coeur-01 : un lien par membre'],
                        ['Po2', 'lacp', ['1/0/13', '2/0/13'], 'access', 20, [], 'nas-01 : un lien par membre'],
                    ],
                    'described' => [
                        '1/0/1' => ['srv-annuaire-01', true],
                        '2/0/1' => ['srv-fichiers-01', true],
                        '1/0/9' => ['hyp-01', true],
                        '2/0/9' => ['hyp-02', true],
                        '1/0/24' => ['onduleur-01', true],
                        '1/0/23' => ['Désactivé : ancien serveur de test, retiré', false],
                        '2/0/23' => ['Désactivé : en attente de nas-02', false],
                    ],
                ],
                'sw-etage-1' => [
                    'members' => [[1, 'primary', $switch24]],
                    'carried' => [
                        // A telephone on each desk, a workstation behind it.
                        [$series('1/0/', 1, 16), 'access', 10, [30]],
                        [$series('1/0/', 17, 20), 'access', 10, []],
                        // The access points carry the guests as well.
                        [['1/0/21', '1/0/22'], 'trunk', 99, [10, 40]],
                        [['1/1/1', '1/1/2'], 'trunk', 99, [10, 30, 40]],
                    ],
                    'aggregates' => [],
                    'described' => [
                        '1/0/17' => ['imp-compta', true],
                        '1/0/18' => ['sw-salle-reunion', true],
                        '1/0/21' => ['ap-etage-1', true],
                        '1/0/22' => ['ap-salle-reunion', true],
                        '1/1/1' => ['Vers sw-coeur-01', true],
                        '1/1/2' => ['Vers sw-coeur-02 : second lien, prévu', true],
                        '1/0/23' => ['Désactivé : prise du couloir condamnée', false],
                        '1/0/24' => ['Désactivé : non utilisé', false],
                    ],
                ],
                'sw-salle-reunion' => [
                    'members' => [[1, 'primary', $switch8]],
                    'carried' => [],
                    'aggregates' => [],
                    'described' => [
                        '8' => ['Vers la prise B.15, puis sw-etage-1', true],
                    ],
                ],
                'sw-etage-2' => [
                    'members' => [[1, 'primary', $switch24]],
                    'carried' => [
                        [$series('1/0/', 1, 12), 'access', 10, [30]],
                        [$series('1/0/', 13, 16), 'access', 10, []],
                        [['1/0/21'], 'trunk', 99, [10, 40]],
                        [['1/1/1'], 'trunk', 99, [10, 30, 40]],
                    ],
                    'aggregates' => [],
                    'described' => [
                        '1/0/13' => ['imp-direction', true],
                        '1/0/21' => ['ap-etage-2', true],
                        '1/1/1' => ['Vers sw-coeur-02', true],
                        '1/0/24' => ['Désactivé : non utilisé', false],
                    ],
                ],
            ],
            'Atelier' => [
                'sw-atelier-01' => [
                    'members' => [[1, 'primary', $switch24]],
                    'carried' => [
                        // The desks of the mezzanine, then the posts of the
                        // production hall: patched from the panel of the
                        // technical room.
                        [$series('1/0/', 1, 6), 'access', 10, [30]],
                        [$series('1/0/', 7, 10), 'access', 60, []],
                        [['1/0/24'], 'trunk', 99, [10, 60, 150]],
                        // The other end of the fibre under study.
                        [['1/1/1'], 'trunk', null, [10, 60]],
                    ],
                    'aggregates' => [],
                    'described' => [
                        '1/0/9' => ['ap-atelier-1', true],
                        '1/0/10' => ['ap-atelier-2', true],
                        '1/0/24' => ['Vers sw-quai', true],
                        '1/1/1' => ['Vers sw-coeur-02 : fibre directe, à l\'étude', true],
                    ],
                ],
                'sw-quai' => [
                    'members' => [[1, 'primary', $switch8]],
                    'carried' => [
                        [$series('', 1, 4), 'access', 60, []],
                        [['5'], 'access', 150, []],
                        [['8'], 'trunk', 99, [10, 60, 150]],
                    ],
                    'aggregates' => [],
                    'described' => [
                        '5' => ['Caméra du quai', true],
                        '6' => ['Désactivé : non utilisé', false],
                        '8' => ['Vers sw-atelier-01', true],
                    ],
                ],
            ],
        ];
    }

    /**
     * What is plugged into what: the ports of a device, the device they
     * are linked to and as many of its ports, then the medium, the status
     * and the description of each link.
     *
     * Two of them differ at their two ends on purpose, and say so in their
     * description: a VLAN one side does not tag, and two native VLANs.
     *
     * @return list<array{0: string, 1: list<string>, 2: string, 3: list<string>, 4: string|null, 5: string, 6: string|null}>
     */
    private function linkPlans(): array
    {
        return [
            // The firewall, to each of the two core switches.
            ['fw-siege', ['port2'], 'sw-coeur-01', ['1/0/1'], 'copper', 'active', null],
            ['fw-siege', ['port4'], 'sw-coeur-02', ['1/0/1'], 'copper', 'active', null],
            ['sw-coeur-01', ['1/1/4'], 'sw-coeur-02', ['1/1/4'], 'direct_attach', 'active', 'sw-coeur-02 a gardé l\'ancien VLAN 5 comme VLAN natif : écart conservé pour la démonstration.'],
            // The stack, by a link from each of its members: its
            // aggregate faces the one of the core switch.
            ['sw-serveurs', ['1/1/1', '2/1/1'], 'sw-coeur-01', ['1/1/1', '1/1/2'], 'direct_attach', 'active', null],
            // The uplinks of the floors.
            ['sw-etage-1', ['1/1/1'], 'sw-coeur-01', ['1/1/3'], 'fiber_multimode', 'active', null],
            ['sw-etage-2', ['1/1/1'], 'sw-coeur-02', ['1/1/1'], 'fiber_multimode', 'active', 'Le VLAN 40 des invités n\'est pas étiqueté côté sw-coeur-02 : écart conservé pour la démonstration.'],
            ['sw-etage-1', ['1/1/2'], 'sw-coeur-02', ['1/1/2'], 'fiber_multimode', 'planned', 'Second lien montant du 1er étage : fibre à tirer.'],
            // The access points and the switch of the meeting room, each
            // through its jack and the panel of its floor. The cabling of
            // the first two confirms their links.
            ['sw-etage-1', ['1/0/21'], 'ap-etage-1', ['eth0'], 'copper', 'active', null],
            ['sw-etage-1', ['1/0/18'], 'sw-salle-reunion', ['8'], 'copper', 'active', null],
            ['sw-etage-1', ['1/0/22'], 'ap-salle-reunion', ['eth0'], 'copper', 'active', 'La prise B.14 est brassée vers 1/0/22, mais le cordon de la borne n\'y est pas encore déclaré : écart conservé pour la démonstration.'],
            ['sw-etage-2', ['1/0/21'], 'ap-etage-2', ['eth0'], 'copper', 'active', 'Déclarée sur 1/0/21, alors que la prise C.10 de la borne est brassée vers 1/0/16 : écart conservé pour la démonstration.'],
            // The uplink of the quay, at the workshop.
            ['sw-quai', ['8'], 'sw-atelier-01', ['1/0/24'], 'copper', 'active', null],
            ['sw-atelier-01', ['1/0/10'], 'ap-atelier-2', ['eth0'], 'copper', 'retired', 'Retirée quand la borne a été déplacée, mais ses cordons sont encore en place par la prise H.04 : écart conservé pour la démonstration.'],
            // Between the two sites, under study.
            ['sw-coeur-02', ['1/1/3'], 'sw-atelier-01', ['1/1/1'], 'fiber_singlemode', 'planned', 'Fibre directe siège – atelier, à l\'étude. Le VLAN 60 de l\'atelier passe aux deux bouts, sans écart. Le VLAN 10 du siège d\'un côté, celui de l\'atelier de l\'autre : la liaison fusionnerait deux réseaux, écart conservé pour la démonstration.'],
        ];
    }

    /**
     * Where the devices of the diagram of the workshop are placed by hand,
     * on the grid of 8: the core switch of the head office at the edge, at
     * the top; the technical room, then the quay and the hall below it. The
     * access point of the quay is left out: it lines up below the others,
     * marked as not placed.
     *
     * @return array<string, array{x: int, y: int}>
     */
    private function workshopLayout(): array
    {
        return [
            'sw-coeur-02' => ['x' => 96, 'y' => 0],
            'fw-atelier' => ['x' => 704, 'y' => 200],
            'sw-atelier-01' => ['x' => 96, 'y' => 304],
            'sw-atelier-02' => ['x' => 704, 'y' => 304],
            'sw-quai' => ['x' => 96, 'y' => 520],
            'ap-atelier-1' => ['x' => 600, 'y' => 520],
            'ap-atelier-2' => ['x' => 800, 'y' => 520],
        ];
    }

    /**
     * Where the patch panels of the diagram of the workshop are placed by
     * hand: the panel of the technical room above the switch and the access
     * points it is cabled to, so that its cords leave it downwards.
     *
     * @return array<string, array{x: int, y: int}>
     */
    private function workshopPanelLayout(): array
    {
        return [
            'PB-A' => ['x' => 512, 'y' => 200],
        ];
    }

    /**
     * The patch panels of each site: the room that keeps it, its name, its
     * number of ports, its connector, its category and its description. A
     * panel in each technical room, the switch of the floor next to it, and
     * a fibre panel for the risers of the head office.
     *
     * @return array<string, list<array{0: string, 1: string, 2: int, 3: string, 4: string|null, 5: string}>>
     */
    private function panelPlans(): array
    {
        return [
            'Siège' => [
                ['Rez-de-chaussée › Local technique', 'PB-A', 24, 'rj45', '6A', 'Panneau cuivre de la baie : les prises du rez-de-chaussée.'],
                ['Rez-de-chaussée › Local technique', 'PB-FO', 12, 'lc', null, 'Panneau fibre de la baie : les rocades vers les étages, sans prise.'],
                ['1er étage › Placard technique', 'PB-B', 24, 'rj45', '6A', 'Panneau de la baie murale du sous-répartiteur : les prises du 1er étage.'],
                ['2e étage › Placard technique', 'PB-C', 24, 'rj45', '6A', 'Panneau de la baie murale du sous-répartiteur : les prises du 2e étage.'],
            ],
            'Atelier' => [
                ['Rez-de-chaussée › Local technique', 'PB-A', 24, 'rj45', '6', 'Panneau de la baie murale : les prises de la mezzanine et du hall.'],
            ],
        ];
    }

    /**
     * The wall jacks of each floor, labelled as series: the floor, the
     * start of the label, the first and the last number, and the panel the
     * series is cabled to with its first port, if any.
     *
     * @return array<string, list<array{0: string, 1: string, 2: int, 3: int, 4: string|null, 5: int|null}>>
     */
    private function jackPlans(): array
    {
        return [
            'Siège' => [
                ['Rez-de-chaussée', 'R.', 1, 6, 'PB-A', 1],
                // Laid, not yet cabled to the panel.
                ['Rez-de-chaussée', 'R.', 7, 8, null, null],
                ['1er étage', 'B.', 1, 16, 'PB-B', 1],
                ['2e étage', 'C.', 1, 12, 'PB-C', 1],
            ],
            'Atelier' => [
                ['Mezzanine', 'M.', 1, 6, 'PB-A', 1],
                ['Rez-de-chaussée', 'H.', 1, 4, 'PB-A', 7],
            ],
        ];
    }

    /**
     * The cords: the site, the panel and its ports from a first to a last
     * one, the switch and the first of its ports they go to, in the order
     * of the switch, the colour and the description of the cords.
     *
     * The jacks of the ground floor of the head office are cabled to their
     * panel and not patched yet; the last ports of the panels of the
     * floors are free. One cord goes from the technical room to the switch
     * of the first floor, and says so.
     *
     * @return list<array{0: string, 1: string, 2: int, 3: int, 4: string, 5: string, 6: string|null, 7: string|null}>
     */
    private function cordPlans(): array
    {
        return [
            ['Siège', 'PB-B', 1, 12, 'sw-etage-1', '1/0/1', 'Bleu', null],
            // The access points, the switch of the meeting room and the
            // printer of the accounts.
            ['Siège', 'PB-B', 13, 14, 'sw-etage-1', '1/0/21', 'Bleu', null],
            ['Siège', 'PB-B', 15, 15, 'sw-etage-1', '1/0/18', 'Bleu', null],
            ['Siège', 'PB-B', 16, 16, 'sw-etage-1', '1/0/17', 'Bleu', null],
            // The access point of the second floor, on another port than
            // its link says.
            ['Siège', 'PB-C', 10, 10, 'sw-etage-2', '1/0/16', 'Bleu', null],
            ['Siège', 'PB-C', 1, 8, 'sw-etage-2', '1/0/1', 'Bleu', null],
            // A port of the switch without the VLAN of the telephones: the
            // jack it serves says so.
            ['Siège', 'PB-C', 9, 9, 'sw-etage-2', '1/0/14', 'Bleu', null],
            // The risers: fibres of the panel of the technical room, patched
            // to the uplinks of the core switches. No jack is at the other
            // end: they carry links.
            ['Siège', 'PB-FO', 1, 1, 'sw-coeur-01', '1/1/3', 'Turquoise', 'Rocade vers sw-etage-1'],
            ['Siège', 'PB-FO', 2, 3, 'sw-coeur-02', '1/1/1', 'Turquoise', 'Rocades vers sw-etage-2 et sw-etage-1'],
            ['Siège', 'PB-A', 24, 24, 'sw-etage-1', '1/0/20', 'Rouge', 'Cordon de dépannage tiré du local technique du rez-de-chaussée jusqu\'au switch du 1er étage, rangé dans le local technique de son étage : écart « cordon entre deux locaux » conservé pour la démonstration.'],
            ['Atelier', 'PB-A', 1, 10, 'sw-atelier-01', '1/0/1', 'Gris', null],
        ];
    }

    /**
     * The jacks that say why their cabling differs from what is declared
     * around them: the site, the floor, the label and the description.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function jackNotes(): array
    {
        return [
            ['Siège', 'Rez-de-chaussée', 'R.05', 'L\'imprimante de l\'accueil y est branchée alors que la prise n\'est pas brassée : écart « prise non brassée » conservé pour la démonstration.'],
            ['Siège', '2e étage', 'C.09', 'Le téléphone de la direction y est branché, mais la prise est brassée vers un port sans le VLAN de la téléphonie : écart « VLAN non porté » conservé pour la démonstration.'],
            ['Siège', '1er étage', 'B.14', 'Brassée vers sw-etage-1 1/0/22, relié à la borne ap-salle-reunion, dont le cordon n\'est pas encore déclaré ici : écart « relié sans branchement » conservé pour la démonstration.'],
            ['Atelier', 'Rez-de-chaussée', 'H.03', 'La borne ap-atelier-1 y est branchée et la prise est brassée vers sw-atelier-01, sans liaison déclarée : écart « câblage sans liaison » conservé pour la démonstration.'],
        ];
    }

    /**
     * The addresses plugged into the jacks: the site, the floor, the label
     * of the jack, and the addresses of what is plugged into it, often a
     * telephone with a workstation behind it.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: list<string>}>
     */
    private function plugPlans(): array
    {
        return [
            ['Siège', 'Rez-de-chaussée', 'R.05', ['10.10.10.20']],
            ['Siège', '1er étage', 'B.01', ['10.10.10.111', '10.10.30.51']],
            ['Siège', '1er étage', 'B.02', ['10.10.10.112', '10.10.30.52']],
            ['Siège', '1er étage', 'B.05', ['10.10.10.115', '10.10.30.55']],
            ['Siège', '1er étage', 'B.06', ['10.10.10.116', '10.10.30.56']],
            ['Siège', '1er étage', 'B.08', ['10.10.10.118']],
            ['Siège', '2e étage', 'C.01', ['10.10.10.114', '10.10.30.54']],
            ['Siège', '2e étage', 'C.03', ['10.10.10.113']],
            ['Siège', '2e étage', 'C.05', ['10.10.10.121', '10.10.30.57']],
            ['Siège', '2e étage', 'C.09', ['10.10.30.53']],
            ['Siège', '1er étage', 'B.10', ['10.10.50.16']],
            ['Atelier', 'Mezzanine', 'M.01', ['10.20.10.110', '10.20.30.50']],
            ['Atelier', 'Mezzanine', 'M.02', ['10.20.10.111', '10.20.30.53']],
            ['Atelier', 'Mezzanine', 'M.03', ['10.20.10.113', '10.20.30.54']],
            ['Atelier', 'Rez-de-chaussée', 'H.01', ['10.20.60.20']],
            ['Atelier', 'Rez-de-chaussée', 'H.02', ['10.20.60.21']],
            // The printer of the accounts, a device whose ports are not
            // documented: plugged in by its address.
            ['Siège', '1er étage', 'B.16', ['10.10.10.21']],
        ];
    }

    /**
     * The devices that have one port, their uplink "eth0", site by site,
     * with what the port leads to.
     *
     * @return array<string, array<string, string>>
     */
    private function uplinkPlans(): array
    {
        return [
            'Siège' => [
                'ap-etage-1' => 'Vers la prise B.13, puis sw-etage-1',
                'ap-etage-2' => 'Vers la prise C.10, puis sw-etage-2',
                'ap-salle-reunion' => 'Vers sw-etage-1, cordon de la prise B.14 à déclarer',
            ],
            'Atelier' => [
                'ap-atelier-1' => 'Vers la prise H.03, puis sw-atelier-01',
                'ap-atelier-2' => 'Vers la prise H.04, puis sw-atelier-01',
            ],
        ];
    }

    /**
     * The devices plugged into a jack by a jack cord: the site, the floor
     * and the label of the jack, the device and its port, the colour and
     * the description of the cord. The access point of the meeting room is
     * not plugged in yet, though its link and the cord of its jack are
     * there; the one of the second floor is plugged into a jack patched to
     * another port than its link says.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string|null, 6: string|null}>
     */
    private function jackCordPlans(): array
    {
        return [
            ['Siège', '1er étage', 'B.13', 'ap-etage-1', 'eth0', 'Blanc', 'Au plafond du plateau'],
            ['Siège', '1er étage', 'B.15', 'sw-salle-reunion', '8', 'Blanc', null],
            ['Siège', '2e étage', 'C.10', 'ap-etage-2', 'eth0', 'Blanc', 'Au plafond du plateau'],
            ['Atelier', 'Rez-de-chaussée', 'H.03', 'ap-atelier-1', 'eth0', 'Gris', null],
            ['Atelier', 'Rez-de-chaussée', 'H.04', 'ap-atelier-2', 'eth0', 'Gris', null],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function standaloneSubnetPlans(): array
    {
        return [
            [
                // Public addresses given by the carrier, taken here from a
                // range set aside for documentation.
                'cidr' => '203.0.113.8/29',
                'kind' => 'external',
                'site' => 'Siège',
                'gateway' => '203.0.113.9',
                'name' => 'Accès Internet du siège',
                'description' => 'Adresses publiques fournies par l\'opérateur (plage de documentation). Hors du bloc du siège par nature : pour un sous-réseau externe, ce n\'est pas signalé.',
                'addresses' => [
                    ['203.0.113.9', 'routeur-operateur-siege', 'router', 'description' => 'Routeur de l\'opérateur'],
                    ['203.0.113.10', 'fw-siege-wan', 'firewall'],
                ],
            ],
            [
                // A link between two routers: both addresses of a /31 are
                // usable, and there is neither gateway nor range.
                'cidr' => '10.255.0.0/31',
                'kind' => 'point_to_point',
                'name' => 'Liaison siège – atelier',
                'description' => 'Fibre louée entre les deux sites.',
                'addresses' => [
                    ['10.255.0.0', 'rtr-siege-liaison', 'router'],
                    ['10.255.0.1', 'rtr-atelier-liaison', 'router'],
                ],
            ],
            [
                'cidr' => '172.16.255.0/31',
                'kind' => 'tunnel',
                'name' => 'Tunnel VPN siège – atelier',
                'description' => 'Secours de la liaison louée, par Internet.',
                'addresses' => [
                    ['172.16.255.0', 'fw-siege-vpn', 'firewall'],
                    ['172.16.255.1', 'fw-atelier-vpn', 'firewall'],
                ],
            ],
            [
                // The address of a router itself.
                'cidr' => '10.10.255.1/32',
                'kind' => 'loopback',
                'site' => 'Siège',
                'name' => 'Loopback du pare-feu du siège',
                'addresses' => [
                    ['10.10.255.1', 'fw-siege-lo0', 'firewall'],
                ],
            ],
        ];
    }
}
