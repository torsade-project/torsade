<?php

// torsade/app/Http/Presenters/NetworkPresenter.php

namespace App\Http\Presenters;

use App\Models\AddressBlock;
use App\Models\Building;
use App\Models\CatalogVlan;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\DeviceTemplate;
use App\Models\DeviceTemplatePortGroup;
use App\Models\Floor;
use App\Models\IpAddress;
use App\Models\IpRange;
use App\Models\Jack;
use App\Models\Link;
use App\Models\PatchPanel;
use App\Models\PatchPanelPort;
use App\Models\Port;
use App\Models\PortAggregate;
use App\Models\Room;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\Vlan;
use App\Services\Network\Deviations;
use App\Services\Network\VlanCarriers;
use App\Support\Ipv4;
use Illuminate\Support\Collection;

/**
 * What the pages receive about network objects. Each shape is an explicit
 * list of values: a model is never sent to the browser as a whole.
 */
final class NetworkPresenter
{
    /**
     * A site, with its blocks in CIDR notation when they are loaded: the
     * window of a site compares them with the block the addressing rule
     * expects.
     *
     * @return array{id: int, name: string, code: string|null, number: int|null, status: string, status_label: string, description: string|null, vlans_count: int, blocks: list<string>}
     */
    public static function site(Site $site): array
    {
        return [
            'id' => $site->id,
            'name' => $site->name,
            'code' => $site->code,
            'number' => $site->number,
            'status' => $site->status->value,
            'status_label' => __($site->status->label()),
            'description' => $site->description,
            'vlans_count' => (int) ($site->vlans_count ?? 0),
            'blocks' => $site->relationLoaded('addressBlocks')
                ? array_values($site->addressBlocks->map(fn (AddressBlock $block): string => $block->cidr())->all())
                : [],
        ];
    }

    /**
     * A VLAN with its site, the entry of the catalogue it follows and how it
     * differs from it, and, when it has one, the summary of its subnet. The
     * subnet must be loaded with its "ip_addresses_count".
     *
     * @return array<string, mixed>
     */
    public static function vlan(Vlan $vlan, Deviations $deviations): array
    {
        return [
            'id' => $vlan->id,
            'vid' => $vlan->vid,
            'name' => $vlan->name,
            'description' => $vlan->description,
            'status' => $vlan->status->value,
            'status_label' => __($vlan->status->label()),
            'site' => ['id' => $vlan->site->id, 'name' => $vlan->site->name],
            'catalog' => $vlan->catalogVlan === null ? null : self::catalogOption($vlan->catalogVlan),
            'deviations' => $deviations->ofVlan($vlan),
            'subnet' => $vlan->subnet === null ? null : self::subnet($vlan->subnet, withVlan: false),
        ];
    }

    /**
     * The ports and the aggregates that carry a VLAN, device by device, as
     * the page of the VLAN lists them.
     *
     * @param  list<array{device: Device, carriers: list<array{kind: 'port'|'aggregate', name: string, member: int|null, role: string}>}>  $groups
     * @return list<array<string, mixed>>
     */
    public static function vlanCarriers(array $groups): array
    {
        return array_map(fn (array $group): array => [
            'device' => ['id' => $group['device']->id, 'name' => $group['device']->name],
            'carriers' => array_map(fn (array $carrier): array => [
                ...$carrier,
                'role_label' => __(VlanCarriers::label($carrier['role'])),
            ], $group['carriers']),
        ], $groups);
    }

    /**
     * An entry of the catalogue, as a choice in a list.
     *
     * @return array{id: int, vid: int, name: string}
     */
    public static function catalogOption(CatalogVlan $entry): array
    {
        return ['id' => $entry->id, 'vid' => $entry->vid, 'name' => $entry->name];
    }

    /**
     * An entry of the catalogue with the VLANs attached to it, one per site
     * at most, and how each one differs from it. The VLANs must be loaded.
     *
     * @return array<string, mixed>
     */
    public static function catalogEntry(CatalogVlan $entry, Deviations $deviations): array
    {
        return [
            'id' => $entry->id,
            'vid' => $entry->vid,
            'name' => $entry->name,
            'description' => $entry->description,
            'vlans' => array_values($entry->vlans
                ->map(function (Vlan $vlan) use ($entry, $deviations): array {
                    // The entry is already known: no query per VLAN.
                    $vlan->setRelation('catalogVlan', $entry);

                    return [
                        'id' => $vlan->id,
                        'site_id' => $vlan->site_id,
                        'vid' => $vlan->vid,
                        'name' => $vlan->name,
                        'status' => $vlan->status->value,
                        'status_label' => __($vlan->status->label()),
                        'deviations' => $deviations->ofVlan($vlan),
                    ];
                })
                ->all()),
        ];
    }

    /**
     * A block with what is derived from its address and prefix length. Its
     * site must be loaded.
     *
     * @return array<string, mixed>
     */
    public static function addressBlock(AddressBlock $block): array
    {
        return [
            'id' => $block->id,
            'cidr' => $block->cidr(),
            'network' => $block->network,
            'prefix_length' => $block->prefix_length,
            'mask' => Ipv4::mask($block->prefix_length),
            'last_address' => Ipv4::broadcast($block->network, $block->prefix_length),
            'size' => Ipv4::size($block->prefix_length),
            'name' => $block->name,
            'description' => $block->description,
            'status' => $block->status->value,
            'status_label' => __($block->status->label()),
            // Without a site, the block belongs to the organisation.
            'site' => $block->site === null ? null : ['id' => $block->site->id, 'name' => $block->site->name],
        ];
    }

    /**
     * A subnet with everything that is derived from its address and prefix
     * length. The model must be loaded with its "ip_addresses_count" and,
     * unless it is shown inside its VLAN, with its VLAN and its site.
     *
     * @return array<string, mixed>
     */
    public static function subnet(Subnet $subnet, bool $withVlan = true): array
    {
        [$first, $last] = Ipv4::usableRange($subnet->network, $subnet->prefix_length);

        $summary = [
            'id' => $subnet->id,
            'cidr' => $subnet->cidr(),
            'network' => $subnet->network,
            'prefix_length' => $subnet->prefix_length,
            'kind' => $subnet->kind->value,
            'kind_label' => __($subnet->kind->label()),
            'mask' => Ipv4::mask($subnet->prefix_length),
            'broadcast' => Ipv4::broadcast($subnet->network, $subnet->prefix_length),
            'first_usable' => $first,
            'last_usable' => $last,
            'gateway' => $subnet->gateway,
            'name' => $subnet->name,
            'description' => $subnet->description,
            'status' => $subnet->status->value,
            'status_label' => __($subnet->status->label()),
            // Occupancy: documented addresses out of usable addresses.
            'usable_count' => Ipv4::usableCount($subnet->prefix_length),
            'documented_count' => (int) ($subnet->ip_addresses_count ?? 0),
        ];

        if ($withVlan) {
            $summary['vlan'] = $subnet->vlan === null ? null : [
                'id' => $subnet->vlan->id,
                'vid' => $subnet->vlan->vid,
                'name' => $subnet->vlan->name,
                'site' => $subnet->vlan->site->name,
            ];

            // The site of its VLAN when it has one, its own otherwise.
            $summary['site'] = $subnet->site === null ? null : ['id' => $subnet->site->id, 'name' => $subnet->site->name];
        }

        return $summary;
    }

    /**
     * @return array{id: int, kind: string, kind_label: string, start_address: string, end_address: string, size: int, name: string|null, description: string|null}
     */
    public static function ipRange(IpRange $range): array
    {
        return [
            'id' => $range->id,
            'kind' => $range->kind->value,
            'kind_label' => __($range->kind->label()),
            'name' => $range->name,
            'start_address' => $range->start_address,
            'end_address' => $range->end_address,
            'size' => Ipv4::rangeSize($range->start_address, $range->end_address),
            'description' => $range->description,
        ];
    }

    /**
     * An address in the table of its subnet, with the device it is an
     * interface of, the jack it is plugged into and how it differs from what
     * is expected of it. Its subnet with its site, its device with its site,
     * and the relations of ADDRESS_JACK must be loaded.
     *
     * @return array<string, mixed>
     */
    public static function ipAddress(IpAddress $address, ?string $gateway, Deviations $deviations): array
    {
        return [
            'id' => $address->id,
            'address' => $address->address,
            'hostname' => $address->hostname,
            'mac_address' => $address->mac_address,
            'device_type' => $address->device_type?->value,
            'device_type_label' => $address->device_type === null ? null : __($address->device_type->label()),
            'assignment' => $address->assignment->value,
            'assignment_label' => __($address->assignment->label()),
            'status' => $address->status->value,
            // "Active", not "actif": the status agrees with the address.
            'status_label' => __($address->status->label(feminine: true)),
            'description' => $address->description,
            // The address of the router is flagged where it is documented.
            'is_gateway' => $gateway !== null && $address->address === $gateway,
            'device' => $address->device === null ? null : [
                'id' => $address->device->id,
                'name' => $address->device->name,
                'site' => $address->device->site->name,
            ],
            'jack' => self::addressJack($address),
            'deviations' => $deviations->ofAddress($address),
        ];
    }

    /**
     * An address as an interface of its device: where it is, rather than
     * what holds it. Its subnet, with its VLAN and its site, its device with
     * its site, and the relations of ADDRESS_JACK must be loaded.
     *
     * @return array<string, mixed>
     */
    public static function deviceAddress(IpAddress $address, Deviations $deviations): array
    {
        $subnet = $address->subnet;

        return [
            'id' => $address->id,
            'address' => $address->address,
            'hostname' => $address->hostname,
            'status' => $address->status->value,
            // "Active", not "actif": the status agrees with the address.
            'status_label' => __($address->status->label(feminine: true)),
            'subnet' => [
                'id' => $subnet->id,
                'cidr' => $subnet->cidr(),
                'site' => $subnet->site?->name,
            ],
            'vlan' => $subnet->vlan === null ? null : [
                'id' => $subnet->vlan->id,
                'vid' => $subnet->vlan->vid,
                'name' => $subnet->vlan->name,
            ],
            'jack' => self::addressJack($address),
            'deviations' => $deviations->ofAddress($address),
        ];
    }

    /**
     * A room as a place is always written: with its path. Its floor and
     * the building of that floor must be loaded.
     *
     * @return array{id: int, name: string, path: string, is_technical: bool}
     */
    public static function place(Room $room): array
    {
        return [
            'id' => $room->id,
            'name' => $room->name,
            'path' => $room->path(),
            'is_technical' => $room->is_technical,
        ];
    }

    /**
     * A building with its floors, lowest first, and the rooms of each, by
     * name: the places of a site as a tree. The floors must be loaded with
     * their rooms and their "jacks_count", and each room with its
     * "devices_count" and its patch panels, each with its "occupied_count".
     *
     * @return array<string, mixed>
     */
    public static function building(Building $building): array
    {
        return [
            'id' => $building->id,
            'name' => $building->name,
            'code' => $building->code,
            'description' => $building->description,
            'floors' => array_values($building->floors
                ->sortBy(fn (Floor $floor): int => $floor->level)
                ->map(fn (Floor $floor): array => [
                    'id' => $floor->id,
                    'level' => $floor->level,
                    'name' => $floor->name,
                    'description' => $floor->description,
                    'jacks_count' => (int) ($floor->getAttribute('jacks_count') ?? 0),
                    'rooms' => array_values($floor->rooms
                        ->sortBy(fn (Room $room): string => $room->name, SORT_NATURAL | SORT_FLAG_CASE)
                        ->map(fn (Room $room): array => [
                            'id' => $room->id,
                            'name' => $room->name,
                            'is_technical' => $room->is_technical,
                            'description' => $room->description,
                            'devices_count' => (int) ($room->devices_count ?? 0),
                            'panels' => $room->relationLoaded('patchPanels')
                                ? array_values($room->patchPanels
                                    ->sortBy(fn (PatchPanel $panel): string => $panel->name, SORT_NATURAL | SORT_FLAG_CASE)
                                    ->map(fn (PatchPanel $panel): array => self::patchPanelSummary($panel))
                                    ->all())
                                : [],
                        ])
                        ->all()),
                ])
                ->all()),
        ];
    }

    /**
     * A patch panel in a few words, as the room it is kept in lists it. Its
     * "occupied_count" must be loaded: its ports that have a jack or a cord.
     *
     * @return array<string, mixed>
     */
    public static function patchPanelSummary(PatchPanel $panel): array
    {
        return [
            'id' => $panel->id,
            'name' => $panel->name,
            'port_count' => $panel->port_count,
            'occupied' => (int) ($panel->getAttribute('occupied_count') ?? 0),
            'connector' => $panel->connector->value,
            'connector_label' => __($panel->connector->label()),
            'category' => $panel->category,
            'status' => $panel->status->value,
            'status_label' => __($panel->status->label()),
            'description' => $panel->description,
        ];
    }

    /**
     * A patch panel with the room it is kept in and its site. The room must
     * be loaded with its floor, the building of the floor and its site.
     *
     * @return array<string, mixed>
     */
    public static function patchPanel(PatchPanel $panel): array
    {
        return [
            ...self::patchPanelSummary($panel),
            'room' => self::place($panel->room),
            'site' => ['id' => $panel->room->floor->building->site->id, 'name' => $panel->room->floor->building->site->name],
        ];
    }

    /**
     * A port of a patch panel, as the page of its panel lists it: its jack,
     * with where the jack is, and its cord, with the port of the device it
     * goes to and what that port carries. The jack must be loaded with its
     * floor and the building of the floor; the cord with its port, the
     * device of the port, its aggregate and the VLANs they carry.
     *
     * @param  list<array{kind: string, reason: string}>  $deviations
     * @return array<string, mixed>
     */
    public static function panelPort(PatchPanelPort $port, array $deviations = []): array
    {
        $jack = $port->jack;
        $cord = $port->cord;

        return [
            'id' => $port->id,
            'number' => $port->number,
            'jack' => $jack === null ? null : [
                'id' => $jack->id,
                'label' => $jack->label,
                'place' => $jack->floor->path(),
                'location' => $jack->location,
                'status' => $jack->status->value,
                'status_label' => __($jack->status->label(feminine: true)),
            ],
            'cord' => $cord === null ? null : [
                'id' => $cord->id,
                'color' => $cord->color,
                'description' => $cord->description,
                'device' => ['id' => $cord->port->device->id, 'name' => $cord->port->device->name],
                'port' => ['id' => $cord->port->id, 'name' => $cord->port->name, 'enabled' => $cord->port->enabled],
                'configuration' => self::configurationOf($cord->port),
            ],
            'deviations' => $deviations,
        ];
    }

    /**
     * What the path of a jack needs loaded, from the jack.
     */
    public const array JACK_PATH = [
        'floor.building',
        'cord.port.device',
        'panelPort.panel',
        'panelPort.cord.port.device',
        'panelPort.cord.port.aggregate.portVlans.vlan.site',
        'panelPort.cord.port.portVlans.vlan.site',
    ];

    /**
     * What the jack of an address needs loaded, from the address.
     */
    public const array ADDRESS_JACK = [
        'jack.floor.building',
        'jack.cord.port.device',
        'jack.panelPort.panel',
        'jack.panelPort.cord.port.device',
        'jack.panelPort.cord.port.aggregate.portVlans.vlan.site',
        'jack.panelPort.cord.port.portVlans.vlan.site',
    ];

    /**
     * The jack an address is plugged into, with its path; null when it is
     * plugged into none. The relations of ADDRESS_JACK must be loaded.
     *
     * @return array{id: int, label: string, place: string, path: array<string, mixed>}|null
     */
    public static function addressJack(IpAddress $address): ?array
    {
        $jack = $address->jack;

        return $jack === null ? null : [
            'id' => $jack->id,
            'label' => $jack->label,
            'place' => $jack->floor->path(),
            'path' => self::jackPath($jack),
        ];
    }

    /**
     * The path of a jack towards a device: the port of a panel its
     * horizontal cable ends on, the cord that leaves that port, the port of
     * a device at the other end of the cord, and what that port carries. It
     * stops where nothing is declared. The device plugged into the jack, on
     * the side of the desk, comes before it. The relations of JACK_PATH must
     * be loaded.
     *
     * @return array{jack: array{id: int, label: string, place: string}, plugged: array{cord_id: int, device: array{id: int, name: string}, port: array{id: int, name: string}}|null, panel_port: array{id: int, name: string, panel_id: int}|null, device: array{id: int, name: string}|null, port: array{id: int, name: string}|null, configuration: string|null}
     */
    public static function jackPath(Jack $jack): array
    {
        $panelPort = $jack->panelPort;
        $port = $panelPort?->cord?->port;
        $plugged = $jack->cord;

        return [
            'jack' => ['id' => $jack->id, 'label' => $jack->label, 'place' => $jack->floor->path()],
            'plugged' => $plugged === null ? null : [
                'cord_id' => $plugged->id,
                'device' => ['id' => $plugged->port->device->id, 'name' => $plugged->port->device->name],
                'port' => ['id' => $plugged->port->id, 'name' => $plugged->port->name],
            ],
            'panel_port' => $panelPort === null ? null : ['id' => $panelPort->id, 'name' => $panelPort->name(), 'panel_id' => $panelPort->patch_panel_id],
            'device' => $port === null ? null : ['id' => $port->device->id, 'name' => $port->device->name],
            'port' => $port === null ? null : ['id' => $port->id, 'name' => $port->name],
            'configuration' => $port === null ? null : self::configurationOf($port),
        ];
    }

    /**
     * A jack as the list of jacks shows it: where it is, its path, the
     * addresses plugged into it and how its cabling differs from what is
     * declared. The relations of JACK_PATH must be loaded, the site of the
     * building and the addresses of the jack.
     *
     * @param  list<array<string, mixed>>  $deviations
     * @return array<string, mixed>
     */
    public static function jackLine(Jack $jack, array $deviations = []): array
    {
        $panelPort = $jack->panelPort;

        return [
            'id' => $jack->id,
            'label' => $jack->label,
            'location' => $jack->location,
            'status' => $jack->status->value,
            'status_label' => __($jack->status->label(feminine: true)),
            'description' => $jack->description,
            'site' => ['id' => $jack->floor->building->site->id, 'name' => $jack->floor->building->site->name],
            'panel_port' => $panelPort === null ? null : [
                'id' => $panelPort->id,
                'number' => $panelPort->number,
                'panel' => ['id' => $panelPort->panel->id, 'name' => $panelPort->panel->name],
            ],
            'path' => self::jackPath($jack),
            // The cord of the device plugged into the jack: what its window
            // changes.
            'cord' => $jack->cord === null ? null : [
                'id' => $jack->cord->id,
                'color' => $jack->cord->color,
                'description' => $jack->cord->description,
            ],
            'addresses' => array_values($jack->ipAddresses->map(fn (IpAddress $address): array => [
                'id' => $address->id,
                'address' => $address->address,
                'hostname' => $address->hostname,
                'subnet_id' => $address->subnet_id,
                'status' => $address->status->value,
            ])->all()),
            'deviations' => $deviations,
        ];
    }

    /**
     * What a port of a device carries, in a few words: its own mode and
     * VLANs, or those of its aggregate, "via Po1: Trunk, native 99: 10, 20".
     * Null when it has no mode. Its device and its aggregate must be
     * loaded, and the VLANs the port or the aggregate carries, each with its
     * site.
     */
    public static function configurationOf(Port $port): ?string
    {
        $aggregate = $port->aggregate;

        if ($aggregate === null) {
            return $port->vlanSummary();
        }

        $summary = $aggregate->vlanSummary($port->device->site_id);

        return $summary === null
            ? __('via :name: no mode', ['name' => $aggregate->name])
            : __('via :name: :summary', ['name' => $aggregate->name, 'summary' => $summary]);
    }

    /**
     * A device with its site, the room it is kept in and how many
     * addresses it has. Its site and its room, with the floor and the
     * building of that room, must be loaded, and its "ip_addresses_count".
     *
     * @return array<string, mixed>
     */
    public static function device(Device $device): array
    {
        return [
            'id' => $device->id,
            'name' => $device->name,
            'type' => $device->type->value,
            'type_label' => __($device->type->label()),
            'manufacturer' => $device->manufacturer,
            'model' => $device->model,
            'serial_number' => $device->serial_number,
            'description' => $device->description,
            'status' => $device->status->value,
            'status_label' => __($device->status->label()),
            'site' => ['id' => $device->site->id, 'name' => $device->site->name],
            'room' => $device->room === null ? null : self::place($device->room),
            'addresses_count' => (int) ($device->ip_addresses_count ?? 0),
        ];
    }

    /**
     * A member of a device, as the page of the device lists it. Its
     * template and its room must be loaded, and its "ports_count".
     *
     * @return array<string, mixed>
     */
    public static function member(DeviceMember $member): array
    {
        return [
            'id' => $member->id,
            'position' => $member->position,
            'role' => $member->role->value,
            'role_label' => __($member->role->label()),
            'template' => $member->template === null ? null : ['id' => $member->template->id, 'name' => $member->template->name],
            'serial_number' => $member->serial_number,
            // Empty: the member is where its device is.
            'room' => $member->room === null ? null : self::place($member->room),
            'description' => $member->description,
            'ports_count' => (int) ($member->ports_count ?? 0),
        ];
    }

    /**
     * A port of a device, as the table of its page shows it. Its device and
     * its aggregate must be loaded, and the VLANs it carries, each with its
     * VLAN and the site of the VLAN. What it is patched to is told when its
     * cord is loaded, with the port of the panel, its panel and its jack;
     * the jack it is plugged into, when its jack cord is loaded with the
     * jack, its floor and the building of the floor.
     *
     * @param  array{id: int, device: array{id: int, name: string}, port: string}|null  $link  What the port is linked to, if it is.
     * @param  list<array{kind: string, reason: string}>  $deviations  How its cabling differs from what is declared around it.
     * @return array<string, mixed>
     */
    public static function port(Port $port, ?array $link = null, array $deviations = []): array
    {
        return [
            'id' => $port->id,
            'member_id' => $port->device_member_id,
            'name' => $port->name,
            'position' => $port->position,
            'connector' => $port->connector->value,
            'connector_label' => __($port->connector->label()),
            'max_speed' => $port->max_speed->value,
            'max_speed_label' => __($port->max_speed->label()),
            'poe_type' => $port->poe_type->value,
            'poe_type_label' => __($port->poe_type->label()),
            'poe_label' => $port->poe_label,
            'enabled' => $port->enabled,
            'mode' => $port->mode?->value,
            // "Access 10, voice 30": null without a mode.
            'summary' => $port->vlanSummary(),
            'untagged_vlan_id' => $port->untaggedVlan()?->id,
            'tagged_vlan_ids' => array_map(fn (Vlan $vlan): int => $vlan->id, $port->taggedVlans()),
            'aggregate' => $port->aggregate === null ? null : ['id' => $port->aggregate->id, 'name' => $port->aggregate->name],
            'description' => $port->description,
            'link' => $link,
            'cord' => $port->relationLoaded('patchCord') ? self::cordOf($port) : null,
            'jack_cord' => $port->relationLoaded('jackCord') ? self::jackCordOf($port) : null,
            'deviations' => $deviations,
        ];
    }

    /**
     * What a port of a device is patched to: the port of a panel at the
     * other end of its cord, and the jack of that port when it has one,
     * "patched to B.15". Null without a cord.
     *
     * @return array{id: int, panel_port: array{id: int, name: string, panel_id: int}, jack: array{id: int, label: string}|null}|null
     */
    private static function cordOf(Port $port): ?array
    {
        $cord = $port->patchCord;

        if ($cord === null) {
            return null;
        }

        $jack = $cord->panelPort->jack;

        return [
            'id' => $cord->id,
            'panel_port' => ['id' => $cord->panelPort->id, 'name' => $cord->panelPort->name(), 'panel_id' => $cord->panelPort->patch_panel_id],
            'jack' => $jack === null ? null : ['id' => $jack->id, 'label' => $jack->label],
        ];
    }

    /**
     * The jack a port of a device is plugged into, "plugged into B.07", with
     * the whole place of the jack and the cord itself. Null without a jack
     * cord.
     *
     * @return array{id: int, color: string|null, description: string|null, jack: array{id: int, label: string, path: string, floor_id: int}}|null
     */
    private static function jackCordOf(Port $port): ?array
    {
        $cord = $port->jackCord;

        return $cord === null ? null : [
            'id' => $cord->id,
            'color' => $cord->color,
            'description' => $cord->description,
            'jack' => [
                'id' => $cord->jack->id,
                'label' => $cord->jack->label,
                'path' => $cord->jack->path(),
                'floor_id' => $cord->jack->floor_id,
            ],
        ];
    }

    /**
     * An aggregate of ports of a device, as the page of the device lists
     * it. Its device must be loaded, and the VLANs it carries, each with its
     * VLAN and the site of the VLAN. Its ports are those of the table of
     * ports that name it.
     *
     * @param  list<array{kind: string, reason: string}>  $deviations  Where its links do not lead to one same aggregate of one device.
     * @return array<string, mixed>
     */
    public static function aggregate(PortAggregate $aggregate, array $deviations = []): array
    {
        return [
            'id' => $aggregate->id,
            'name' => $aggregate->name,
            'protocol' => $aggregate->protocol->value,
            'protocol_label' => __($aggregate->protocol->label()),
            'mode' => $aggregate->mode?->value,
            'summary' => $aggregate->vlanSummary(),
            'untagged_vlan_id' => $aggregate->untaggedVlan()?->id,
            'tagged_vlan_ids' => array_map(fn (Vlan $vlan): int => $vlan->id, $aggregate->taggedVlans()),
            'description' => $aggregate->description,
            'deviations' => $deviations,
        ];
    }

    /**
     * A link with its two ends, the one of the device whose page shows it
     * first, and its cabled path when the cabling confirms it: "through
     * PB-B 12 › B.07". Its ends must be loaded with their port, and each
     * port with its device, the site of its device and its aggregate.
     *
     * @param  list<array<string, mixed>>  $deviations
     * @param  int|null  $from  The device whose page shows the link, if any.
     * @param  array{port_id: int, panel_port: array<string, mixed>, jack: array<string, mixed>}|null  $path
     * @return array<string, mixed>
     */
    public static function link(Link $link, array $deviations = [], ?int $from = null, ?array $path = null): array
    {
        [$near, $far] = $link->portsFrom($from);

        return [
            'id' => $link->id,
            'medium' => $link->medium?->value,
            'medium_label' => $link->medium === null ? null : __($link->medium->label()),
            'status' => $link->status->value,
            // A link is feminine in French.
            'status_label' => __($link->status->label(feminine: true)),
            'description' => $link->description,
            'near' => self::linkEnd($near),
            'far' => self::linkEnd($far),
            'path' => $path,
            'deviations' => $deviations,
        ];
    }

    /**
     * One end of a link: a port, with its device and its aggregate.
     *
     * @return array{device: array{id: int, name: string, site: array{id: int, name: string}}, port: array{id: int, name: string, enabled: bool}, aggregate: array{id: int, name: string}|null}
     */
    public static function linkEnd(Port $port): array
    {
        return [
            'device' => [
                'id' => $port->device->id,
                'name' => $port->device->name,
                'site' => ['id' => $port->device->site->id, 'name' => $port->device->site->name],
            ],
            'port' => ['id' => $port->id, 'name' => $port->name, 'enabled' => $port->enabled],
            'aggregate' => $port->aggregate === null ? null : ['id' => $port->aggregate->id, 'name' => $port->aggregate->name],
        ];
    }

    /**
     * Links as the lines of a table. The links between the ports of two
     * aggregates are one aggregated link, shown as one line that holds
     * them; any other link is a line of its own.
     *
     * The links must be given in the order of the table, each loaded as
     * link() needs it.
     *
     * @param  Collection<int, Link>  $links
     * @param  array<int, list<array<string, mixed>>>  $deviations  The deviations of each link, by its identifier.
     * @param  int|null  $from  The device whose page shows the links, if any.
     * @param  array<int, array{port_id: int, panel_port: array<string, mixed>, jack: array<string, mixed>}>  $paths  The cabled path of each link the cabling confirms, by its identifier.
     * @return list<array{key: string, aggregated: bool, links: list<array<string, mixed>>, deviations: list<array<string, mixed>>}>
     */
    public static function linkLines(Collection $links, array $deviations, ?int $from = null, array $paths = []): array
    {
        $lines = [];

        foreach ($links as $link) {
            $shown = self::link($link, $deviations[$link->id] ?? [], $from, $paths[$link->id] ?? null);
            $aggregated = $shown['near']['aggregate'] !== null && $shown['far']['aggregate'] !== null;

            $key = $aggregated
                ? 'aggregated-'.$shown['near']['aggregate']['id'].'-'.$shown['far']['aggregate']['id']
                : 'link-'.$link->id;

            $lines[$key] ??= ['key' => $key, 'aggregated' => $aggregated, 'links' => [], 'deviations' => []];
            $lines[$key]['links'][] = $shown;

            // What differs is told once for the line, whichever of its
            // links it is told by.
            foreach ($shown['deviations'] as $deviation) {
                if (! in_array($deviation, $lines[$key]['deviations'], true)) {
                    $lines[$key]['deviations'][] = $deviation;
                }
            }
        }

        return array_values($lines);
    }

    /**
     * A device template with its groups of ports, in their order, and how
     * many members of devices were made from it. Its groups must be loaded,
     * and its "members_count".
     *
     * @return array<string, mixed>
     */
    public static function deviceTemplate(DeviceTemplate $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'device_type' => $template->device_type->value,
            'device_type_label' => __($template->device_type->label()),
            'manufacturer' => $template->manufacturer,
            'model' => $template->model,
            'poe_budget_watts' => $template->poe_budget_watts,
            'description' => $template->description,
            'ports_count' => (int) $template->portGroups->sum('quantity'),
            'members_count' => (int) ($template->members_count ?? 0),
            'port_groups' => array_values($template->portGroups
                ->map(fn (DeviceTemplatePortGroup $group): array => [
                    'quantity' => $group->quantity,
                    'name_pattern' => $group->name_pattern,
                    'first_number' => $group->first_number,
                    'connector' => $group->connector->value,
                    'max_speed' => $group->max_speed->value,
                    'poe_type' => $group->poe_type->value,
                    'poe_label' => $group->poe_label,
                ])
                ->all()),
        ];
    }

    /**
     * The prefix lengths a subnet can have, each with its mask:
     * "/24 — 255.255.255.0".
     *
     * @return list<array{value: int, label: string}>
     */
    public static function prefixOptions(): array
    {
        return array_map(
            fn (int $prefix): array => ['value' => $prefix, 'label' => '/'.$prefix.' — '.Ipv4::mask($prefix)],
            range(1, Ipv4::MAX_PREFIX),
        );
    }
}
