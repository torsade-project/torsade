<?php

// torsade/app/Services/Network/PhysicalDiagram.php

namespace App\Services\Network;

use App\Enums\DeviceType;
use App\Enums\LinkMedium;
use App\Enums\Status;
use App\Models\Device;
use App\Models\Jack;
use App\Models\Link;
use App\Models\PatchPanel;
use App\Models\PatchPanelPort;
use App\Models\Port;
use App\Models\Room;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The physical diagram of a site: its devices, where they are kept, the
 * ports that are linked or cabled, the links between them and the patch
 * panels they go through, worked out from what is declared and never
 * stored. The page lays it out and draws it.
 *
 * Drawn are the firewalls, the routers, the switches and the access points
 * of the site, any other device of the site that has a link, and, at the
 * edge, the devices of other sites linked to them. Then, step by step until
 * nothing changes, any device a cabled path joins to a device drawn, by one
 * of its ports or by its address: a device of another site plugged into a
 * jack of the site stands at the edge. What is retired is left out.
 *
 * A connection that has a cabled path, a link whose physical path it is, a
 * cabling without a link or a device cabled by its address, is drawn in
 * two segments: from the port of the device to the port of the panel, then
 * from the port of the panel to the device plugged in, the jack naming the
 * second one. The panels it goes through are drawn in the frame of their
 * room, with the ports used only. A link the cabling contradicts keeps its
 * direct line, its cabling drawn beside it. The links between two given
 * aggregates are one direct line, which leaves from a port named by the
 * aggregate on each side; the cabled path of each of them is told with it.
 *
 * Each connection is read from top to bottom, by the type of its devices
 * whatever their site: from a firewall or a router to a switch, from a
 * switch to any other device. A firewall of another site thus stands above
 * the switches of the site. Between two devices of the same rank, from the
 * one that has the more links to the other, all its links counted, those
 * that leave the diagram too, then by name.
 * Devices, panels, ports and links are given in the order of their names:
 * the same data always gives the same diagram.
 *
 * The number of queries does not depend on the number of devices, of
 * links, nor of jacks.
 *
 * @phpstan-type Deviation array{kind: string, reason: string, action?: array<string, int|string>}
 * @phpstan-type DiagramPort array{key: string, name: string}
 * @phpstan-type DiagramDevice array{key: string, id: int, name: string, type: string, type_label: string, end_device: bool, planned: bool, site: array{id: int, name: string}|null, members: int, place: string|null, ports: list<DiagramPort>, deviations: list<Deviation>}
 * @phpstan-type DiagramPanel array{key: string, id: int, name: string, planned: bool, place: string, ports: list<DiagramPort>}
 * @phpstan-type DiagramPlace array{key: string, kind: string, name: string, technical: bool, children: list<mixed>}
 * @phpstan-type DiagramLink array{key: string, connection: string, segment: string, source: string, source_port: string|null, target: string, target_port: string|null, ends: array{0: string, 1: string}, label: string|null, jack: string|null, through: string|null, paths: list<string>, links_count: int, medium: string|null, medium_label: string|null, fibre: bool, planned: bool, deviations: list<Deviation>}
 * @phpstan-type Diagram array{site: array{id: int, name: string, code: string|null}, devices: list<DiagramDevice>, panels: list<DiagramPanel>, places: list<DiagramPlace>, links: list<DiagramLink>, summary: array{devices: int, end_devices: int, undrawn: int, links: int, deviations: int}}
 * @phpstan-type End array{device: Device, port: DiagramPort|null}
 * @phpstan-type Common array{links_count: int, medium: string|null, medium_label: string|null, fibre: bool, planned: bool, deviations: list<Deviation>}
 * @phpstan-type DrawnLink array{key: string, connection: string, segment: string, source: string, source_port: string|null, target: string, target_port: string|null, ends: array{0: string, 1: string}, label: string|null, jack: string|null, through: string|null, paths: list<string>, links_count: int, medium: string|null, medium_label: string|null, fibre: bool, planned: bool, deviations: list<Deviation>, order: list<string>}
 */
final class PhysicalDiagram
{
    /**
     * The types of device drawn whether they have a link or not: those
     * that make the network.
     */
    public const array DRAWN_TYPES = [DeviceType::Firewall, DeviceType::Router, DeviceType::Switch, DeviceType::AccessPoint];

    /**
     * The end devices: what the page can hide, with their connections, to
     * show the network alone.
     */
    public const array END_DEVICE_TYPES = [DeviceType::Workstation, DeviceType::Laptop, DeviceType::Printer, DeviceType::IpPhone, DeviceType::Camera];

    /**
     * The key of the frame of the devices kept in no room.
     */
    public const string UNPLACED = 'unplaced';

    /** A line from one port to the other. */
    public const string DIRECT = 'direct';

    /** The segment between the port of a device and the port of a panel. */
    public const string RACK = 'rack';

    /** The segment between the port of a panel and the device plugged in, named by the jack. */
    public const string OFFICE = 'office';

    public function __construct(
        private readonly LinkDeviations $linkDeviations,
        private readonly DiagramDeviceDeviations $deviceDeviations,
        private readonly CabledPaths $paths,
    ) {}

    /**
     * @return Diagram
     */
    public function of(Site $site): array
    {
        // Every device of the site, where it is kept and what it is made of.
        $siteDevices = Device::query()
            ->where('site_id', $site->id)
            ->with(['site', 'room.floor.building'])
            ->withCount('members')
            ->get()
            ->keyBy('id');

        // Every link one of whose ends is on the site, retired ones too:
        // whether an aggregate faces one same aggregate is told by all the
        // links between two devices.
        $links = Link::query()
            ->whereHas('ends.port', fn (Builder $query) => $query->whereIn('device_id', Device::query()->select('id')->where('site_id', $site->id)))
            ->with([
                ...LinkDeviations::RELATIONS,
                'ends.port.device' => fn ($query) => $query->withCount('members'),
                'ends.port.device.site',
            ])
            ->get();

        // A link may join two sites: the cabling of each end is read on its
        // own site.
        $map = $this->paths->of([
            $site->id,
            ...$links->flatMap(fn (Link $link): array => $link->ends->map(fn ($end): int => $end->port->device->site_id)->all())->all(),
        ]);

        $linkDeviations = $this->linkDeviations->ofLinks($links, cabling: $map);

        $lines = $this->lines($links, $linkDeviations);

        // The cabling drawn is the one of the panels of the site.
        $pairs = array_values(array_filter($map->pairs(), fn (array $pair): bool => self::onSite($pair['panel_port'], $site)));
        $addressed = array_values(array_filter($map->addressed(), fn (array $one): bool => self::onSite($one['panel_port'], $site)));

        // The devices at an end of a line, and how many links each has:
        // what orders two devices of the same rank.
        $linked = [];

        foreach ($lines as $line) {
            foreach ([$line['near'], $line['far']] as $end) {
                $linked[$end['device']->id] = true;
            }
        }

        $degree = $this->degrees(array_keys($linked));

        /** @var array<int, Device> $drawn */
        $drawn = [];

        foreach ($siteDevices as $device) {
            if ($device->status !== Status::Retired && (in_array($device->type, self::DRAWN_TYPES, true) || isset($linked[$device->id]))) {
                $drawn[$device->id] = $device;
            }
        }

        foreach ($lines as $line) {
            foreach ([$line['near'], $line['far']] as $end) {
                // A device of the site keeps the attributes read with it.
                $drawn[$end['device']->id] ??= $siteDevices->get($end['device']->id) ?? $end['device'];
            }
        }

        $drawn = $this->cabledTo($drawn, $pairs, $addressed, $siteDevices);

        /** @var array<int, array<string, DiagramPort>> $ports */
        $ports = [];
        /** @var array<int, PatchPanel> $panels */
        $panels = [];
        /** @var array<int, array<string, DiagramPort>> $panelPorts */
        $panelPorts = [];
        /** @var list<DrawnLink> $diagramLinks */
        $diagramLinks = [];

        // The links whose cabling is drawn instead of their direct line.
        $cabled = [];
        $aggregatedOf = [];

        foreach ($lines as $line) {
            foreach ($line['links'] as $link) {
                if ($line['aggregated']) {
                    $aggregatedOf[$link->id] = true;
                }
            }
        }

        $cablings = [];

        foreach ($pairs as $pair) {
            if ($pair['state'] === CablingMap::CONFIRMED) {
                $link = $pair['links'][0];

                // An aggregated link stays one line; a link to a device
                // that is not drawn is drawn nowhere.
                if (isset($aggregatedOf[$link->id]) || ! isset($drawn[$pair['a']->device_id], $drawn[$pair['b']->device_id])) {
                    continue;
                }

                $cabled[$link->id] = true;
                $cablings[] = ['key' => 'link-'.$link->id, 'pair' => $pair, 'link' => $link];

                continue;
            }

            if (isset($drawn[$pair['a']->device_id], $drawn[$pair['b']->device_id])) {
                $cablings[] = ['key' => 'cabling-'.$pair['jack']->id, 'pair' => $pair, 'link' => null];
            }
        }

        foreach ($lines as $line) {
            [$source, $target] = $this->downwards($line['near'], $line['far'], $degree);
            $first = $line['links'][0];

            if (! $line['aggregated'] && isset($cabled[$first->id])) {
                continue;
            }

            foreach ([$source, $target] as $end) {
                $ports[$end['device']->id][$end['port']['key']] = $end['port'];
            }

            $count = count($line['links']);

            $diagramLinks[] = [
                ...$this->ofLink($line['links']),
                'key' => $line['key'],
                'connection' => $line['key'],
                'segment' => self::DIRECT,
                'source' => 'device-'.$source['device']->id,
                'source_port' => $source['port']['key'],
                'target' => 'device-'.$target['device']->id,
                'target_port' => $target['port']['key'],
                'ends' => [self::endName($source), self::endName($target)],
                'label' => $line['aggregated']
                    ? trans_choice(':near ↔ :far · :count link|:near ↔ :far · :count links', $count, ['near' => $source['port']['name'], 'far' => $target['port']['name']])
                    : null,
                'jack' => null,
                'through' => null,
                // The cabled path of each link of an aggregated line.
                'paths' => $line['aggregated'] ? $this->pathsOf($line['links'], $source['device'], $map) : [],
                'links_count' => $count,
                'deviations' => $line['deviations'],
                // What orders the links: their two ends, by name.
                'order' => [$source['device']->name, $source['port']['name'], $target['device']->name, $target['port']['name']],
            ];
        }

        // The cablings, each in two segments through its panel.
        foreach ($cablings as $cabling) {
            $pair = $cabling['pair'];
            $link = $cabling['link'];
            $patched = ['device' => $drawn[$pair['a']->device_id], 'port' => self::port($pair['a'])];
            $plugged = ['device' => $drawn[$pair['b']->device_id], 'port' => self::port($pair['b'])];

            // The link as the lines read it, with its deviations.
            $line = $link === null ? null : array_find($lines, fn (array $one): bool => array_any($one['links'], fn (Link $other): bool => $other->id === $link->id));

            array_push($diagramLinks, ...$this->segments(
                $cabling['key'],
                $patched,
                $pair['panel_port'],
                $pair['jack'],
                [$plugged],
                $degree,
                [
                    ...$this->ofLink($line === null ? [] : $line['links']),
                    'deviations' => $line === null ? $map->ofPair($pair) : $line['deviations'],
                ],
                $ports,
                $panels,
                $panelPorts,
            ));
        }

        // The devices cabled by their address, from the port patched to
        // their jack.
        foreach ($addressed as $one) {
            if (! isset($drawn[$one['port']->device_id])) {
                continue;
            }

            $patched = ['device' => $drawn[$one['port']->device_id], 'port' => self::port($one['port'])];
            $devices = array_values(array_filter(
                array_map(fn (Device $device): ?Device => $drawn[$device->id] ?? null, $one['devices']),
            ));

            usort($devices, fn (Device $a, Device $b): int => strnatcasecmp($a->name, $b->name) ?: $a->id <=> $b->id);

            array_push($diagramLinks, ...$this->segments(
                'addressed-'.$one['jack']->id,
                $patched,
                $one['panel_port'],
                $one['jack'],
                array_map(fn (Device $device): array => ['device' => $device, 'port' => null], $devices),
                $degree,
                [...$this->ofLink([]), 'deviations' => []],
                $ports,
                $panels,
                $panelPorts,
            ));
        }

        usort($diagramLinks, fn (array $a, array $b): int => $this->byNames($a['order'], $b['order']) ?: strcmp($a['key'], $b['key']));

        $deviceDeviations = $this->deviceDeviations->of(array_values(array_filter($drawn, fn (Device $device): bool => $device->site_id === $site->id)));

        $devices = array_values($drawn);

        usort($devices, fn (Device $a, Device $b): int => strnatcasecmp($a->name, $b->name) ?: $a->id <=> $b->id);

        $diagramDevices = array_map(function (Device $device) use ($site, $ports, $deviceDeviations): array {
            $own = $device->site_id === $site->id;

            return [
                'key' => 'device-'.$device->id,
                'id' => $device->id,
                'name' => $device->name,
                'type' => $device->type->value,
                'type_label' => (string) __($device->type->label()),
                'end_device' => in_array($device->type, self::END_DEVICE_TYPES, true),
                'planned' => $device->status === Status::Planned,
                'site' => $own ? null : ['id' => $device->site->id, 'name' => $device->site->name],
                'members' => (int) ($device->members_count ?? 0),
                'place' => $own ? ($device->room_id === null ? self::UNPLACED : 'room-'.$device->room_id) : null,
                'ports' => self::sorted($ports[$device->id] ?? []),
                'deviations' => $deviceDeviations[$device->id] ?? [],
            ];
        }, $devices);

        $diagramPanels = array_values(array_map(fn (PatchPanel $panel): array => [
            'key' => 'patch_panel-'.$panel->id,
            'id' => $panel->id,
            'name' => $panel->name,
            'planned' => $panel->status === Status::Planned,
            'place' => 'room-'.$panel->room_id,
            'ports' => self::sorted($panelPorts[$panel->id] ?? []),
        ], $panels));

        usort($diagramPanels, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']) ?: $a['id'] <=> $b['id']);

        $undrawn = $siteDevices->filter(fn (Device $device): bool => $device->status !== Status::Retired && ! isset($drawn[$device->id]))->count();
        $ends = array_filter($diagramDevices, fn (array $device): bool => $device['end_device']);

        return [
            'site' => ['id' => $site->id, 'name' => $site->name, 'code' => $site->code],
            'devices' => $diagramDevices,
            'panels' => $diagramPanels,
            'places' => $this->places($diagramDevices, $siteDevices, $panels),
            'links' => array_map(function (array $link): array {
                unset($link['order']);

                return $link;
            }, $diagramLinks),
            'summary' => [
                'devices' => count($diagramDevices) - count($ends),
                'end_devices' => count($ends),
                'undrawn' => $undrawn,
                'links' => count($lines),
                'deviations' => array_sum(array_map(fn (array $device): int => count($device['deviations']), $diagramDevices))
                    + self::linkDeviationsCount($diagramLinks),
            ],
        ];
    }

    /**
     * How many deviations the links drawn tell: a cabling that contradicts
     * a link tells what the direct line of the link already tells, and only
     * that line counts it. The page lists them the same way.
     *
     * @param  list<DrawnLink>  $links
     */
    private static function linkDeviationsCount(array $links): int
    {
        $direct = [];
        $count = 0;

        foreach ($links as $link) {
            if ($link['segment'] === self::DIRECT) {
                foreach ($link['deviations'] as $deviation) {
                    $direct[$deviation['kind'].'|'.$deviation['reason']] = true;
                }

                $count += count($link['deviations']);
            }
        }

        foreach ($links as $link) {
            if ($link['segment'] !== self::DIRECT) {
                $count += count(array_filter($link['deviations'], fn (array $deviation): bool => ! isset($direct[$deviation['kind'].'|'.$deviation['reason']])));
            }
        }

        return $count;
    }

    /**
     * The devices drawn, with every device a cabled path joins to one of
     * them, step by step until nothing changes: two ports cabled to each
     * other, a port and the devices cabled to it by their address.
     *
     * @param  array<int, Device>  $drawn
     * @param  list<array{a: Port, b: Port, panel_port: PatchPanelPort, jack: Jack, state: string, links: list<Link>}>  $pairs
     * @param  list<array{port: Port, panel_port: PatchPanelPort, jack: Jack, devices: list<Device>}>  $addressed
     * @param  Collection<int, Device>  $siteDevices
     * @return array<int, Device>
     */
    private function cabledTo(array $drawn, array $pairs, array $addressed, Collection $siteDevices): array
    {
        $groups = [
            ...array_map(fn (array $pair): array => [$pair['a']->device, $pair['b']->device], $pairs),
            ...array_map(fn (array $one): array => [$one['port']->device, ...$one['devices']], $addressed),
        ];

        $others = [];

        do {
            $grown = false;

            foreach ($groups as $group) {
                if (! array_any($group, fn (Device $device): bool => isset($drawn[$device->id]))) {
                    continue;
                }

                foreach ($group as $device) {
                    if (! isset($drawn[$device->id])) {
                        $drawn[$device->id] = $siteDevices->get($device->id) ?? $device;
                        $grown = true;

                        if (! $siteDevices->has($device->id)) {
                            $others[] = $drawn[$device->id];
                        }
                    }
                }
            }
        } while ($grown);

        // A device of another site plugged into a jack of the site, by its
        // address: drawn at the edge, with its site.
        if ($others !== []) {
            (new Collection($others))->loadMissing('site')->loadCount('members');
        }

        return $drawn;
    }

    /**
     * The segments of a cabling: from the port patched to the port of the
     * panel, then from the port of the panel to each device plugged in,
     * named by the jack. They are read from top to bottom as a link is.
     * The deviations go on the first segment, the jack on the others.
     *
     * @param  array{device: Device, port: DiagramPort}  $patched
     * @param  list<End>  $plugged
     * @param  array<int, int>  $degree
     * @param  Common  $common
     * @param  array<int, array<string, DiagramPort>>  $ports
     * @param  array<int, PatchPanel>  $panels
     * @param  array<int, array<string, DiagramPort>>  $panelPorts
     * @return list<DrawnLink>
     */
    private function segments(string $key, array $patched, PatchPanelPort $panelPort, Jack $jack, array $plugged, array $degree, array $common, array &$ports, array &$panels, array &$panelPorts): array
    {
        if ($plugged === []) {
            return [];
        }

        $panel = $panelPort->panel;
        $panels[$panel->id] = $panel;
        $panelPortKey = 'panel-port-'.$panelPort->id;
        $panelPorts[$panel->id][$panelPortKey] = ['key' => $panelPortKey, 'name' => (string) $panelPort->number];
        $panelKey = 'patch_panel-'.$panel->id;
        $ports[$patched['device']->id][$patched['port']['key']] = $patched['port'];

        foreach ($plugged as $end) {
            if ($end['port'] !== null) {
                $ports[$end['device']->id][$end['port']['key']] = $end['port'];
            }
        }

        // The patched side above, unless the device plugged in stands
        // higher.
        [$upper] = $this->downwards($patched, $plugged[0], $degree);
        $above = $upper === $patched;

        $ends = [self::endName($patched), implode(', ', array_map(fn (array $end): string => self::endName($end), $plugged))];
        $through = CablingMap::through($panelPort, $jack);
        $order = $above
            ? [$patched['device']->name, $patched['port']['name'], $plugged[0]['device']->name, $plugged[0]['port']['name'] ?? '']
            : [$plugged[0]['device']->name, $plugged[0]['port']['name'] ?? '', $patched['device']->name, $patched['port']['name']];

        $device = ['device-'.$patched['device']->id, $patched['port']['key']];
        $panelEnd = [$panelKey, $panelPortKey];
        $shared = [...$common, 'connection' => $key, 'ends' => $ends, 'through' => $through, 'order' => $order];

        $segments = [self::segment($key.'-rack', self::RACK, $above ? $device : $panelEnd, $above ? $panelEnd : $device, null, $shared)];

        foreach ($plugged as $end) {
            $office = ['device-'.$end['device']->id, $end['port']['key'] ?? null];
            $segmentKey = count($plugged) === 1 && $end['port'] !== null ? $key.'-office' : $key.'-office-'.$end['device']->id;

            $segments[] = self::segment($segmentKey, self::OFFICE, $above ? $panelEnd : $office, $above ? $office : $panelEnd, $jack->label, [...$shared, 'deviations' => []]);
        }

        return $segments;
    }

    /**
     * One segment of a cabling, from a node and its port to another one.
     * The office segments go to a device cabled by its address without a
     * port: they end on its edge.
     *
     * @param  array{0: string, 1: string|null}  $from
     * @param  array{0: string, 1: string|null}  $to
     * @param  array{links_count: int, medium: string|null, medium_label: string|null, fibre: bool, planned: bool, deviations: list<Deviation>, connection: string, ends: array{0: string, 1: string}, through: string, order: list<string>}  $shared
     * @return DrawnLink
     */
    private static function segment(string $key, string $kind, array $from, array $to, ?string $jack, array $shared): array
    {
        return [
            'key' => $key,
            'connection' => $shared['connection'],
            'segment' => $kind,
            'source' => $from[0],
            'source_port' => $from[1],
            'target' => $to[0],
            'target_port' => $to[1],
            'ends' => $shared['ends'],
            'label' => null,
            'jack' => $jack,
            'through' => $shared['through'],
            'paths' => [],
            'links_count' => $shared['links_count'],
            'medium' => $shared['medium'],
            'medium_label' => $shared['medium_label'],
            'fibre' => $shared['fibre'],
            'planned' => $shared['planned'],
            'deviations' => $shared['deviations'],
            'order' => $shared['order'],
        ];
    }

    /**
     * What a line or a segment tells of its links: how many, their medium,
     * whether they are planned. Nothing for a cabling without a link.
     *
     * @param  list<Link>  $links
     * @return array{links_count: int, medium: string|null, medium_label: string|null, fibre: bool, planned: bool}
     */
    private function ofLink(array $links): array
    {
        $first = $links[0] ?? null;

        return [
            'links_count' => count($links),
            'medium' => $first?->medium?->value,
            'medium_label' => $first?->medium === null ? null : (string) __($first->medium->label()),
            'fibre' => in_array($first?->medium, [LinkMedium::FiberMultimode, LinkMedium::FiberSinglemode], true),
            'planned' => $links !== [] && array_all($links, fn (Link $link): bool => $link->status === Status::Planned),
        ];
    }

    /**
     * The cabled path of each link of an aggregated line, its ports from
     * the side of the device above: "1/0/23 ↔ 1/0/1 through PB-B 13 › B.13".
     *
     * @param  list<Link>  $links
     * @return list<string>
     */
    private function pathsOf(array $links, Device $above, CablingMap $map): array
    {
        $paths = [];

        foreach ($links as $link) {
            $path = $map->pathOf($link);

            if ($path === null) {
                continue;
            }

            [$near, $far] = $link->portsFrom($above->id);

            $paths[] = (string) __(':near ↔ :far through :path', [
                'near' => $near->name,
                'far' => $far->name,
                'path' => $path['panel_port']['name'].' › '.$path['jack']['label'],
            ]);
        }

        return $paths;
    }

    /**
     * Whether a port of a panel is on the site: the cords of its panel are.
     */
    private static function onSite(PatchPanelPort $panelPort, Site $site): bool
    {
        return $panelPort->panel->room->floor->building->site_id === $site->id;
    }

    /**
     * @return DiagramPort
     */
    private static function port(Port $port): array
    {
        return ['key' => 'port-'.$port->id, 'name' => $port->name];
    }

    /**
     * An end as a tooltip names it: its device, then its port, if any.
     *
     * @param  array{device: Device, port: DiagramPort|null}  $end
     */
    private static function endName(array $end): string
    {
        return $end['port'] === null ? $end['device']->name : $end['device']->name.' › '.$end['port']['name'];
    }

    /**
     * Ports in the order of their names.
     *
     * @param  array<string, DiagramPort>  $ports
     * @return list<DiagramPort>
     */
    private static function sorted(array $ports): array
    {
        $sorted = array_values($ports);

        usort($sorted, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']) ?: strcmp($a['key'], $b['key']));

        return $sorted;
    }

    /**
     * The links to draw, not retired and between two devices that are not
     * either: the links between the ports of two aggregates are one line,
     * any other link a line of its own. Each line has its two ends, a
     * device and a port, and the deviations of its links, each once.
     *
     * @param  Collection<int, Link>  $links
     * @param  array<int, list<Deviation>>  $deviations
     * @return list<array{key: string, aggregated: bool, links: list<Link>, near: array{device: Device, port: DiagramPort}, far: array{device: Device, port: DiagramPort}, deviations: list<Deviation>}>
     */
    private function lines(Collection $links, array $deviations): array
    {
        $lines = [];

        $ordered = $links->sortBy(fn (Link $link): int => $link->id);

        foreach ($ordered as $link) {
            [$near, $far] = $link->portsFrom();

            if ($link->status === Status::Retired || $near->device->status === Status::Retired || $far->device->status === Status::Retired) {
                continue;
            }

            $aggregated = $near->aggregate !== null && $far->aggregate !== null;

            // The same line whichever end its links start from.
            if ($aggregated && $near->aggregate->id > $far->aggregate->id) {
                [$near, $far] = [$far, $near];
            }

            $key = $aggregated
                ? 'aggregated-'.$near->aggregate?->id.'-'.$far->aggregate?->id
                : 'link-'.$link->id;

            $lines[$key] ??= [
                'key' => $key,
                'aggregated' => $aggregated,
                'links' => [],
                'near' => ['device' => $near->device, 'port' => $this->end($near, $aggregated)],
                'far' => ['device' => $far->device, 'port' => $this->end($far, $aggregated)],
                'deviations' => [],
            ];

            $lines[$key]['links'][] = $link;

            foreach ($deviations[$link->id] ?? [] as $deviation) {
                if (! in_array($deviation, $lines[$key]['deviations'], true)) {
                    $lines[$key]['deviations'][] = $deviation;
                }
            }
        }

        return array_values($lines);
    }

    /**
     * The port an end of a line leaves from: the port itself, or its
     * aggregate for an aggregated link.
     *
     * @return DiagramPort
     */
    private function end(Port $port, bool $aggregated): array
    {
        $aggregate = $port->aggregate;

        return $aggregated && $aggregate !== null
            ? ['key' => 'aggregate-'.$aggregate->id, 'name' => $aggregate->name]
            : ['key' => 'port-'.$port->id, 'name' => $port->name];
    }

    /**
     * The two ends of a connection, the one above first.
     *
     * @template TEnd of array{device: Device, port: DiagramPort|null}
     *
     * @param  TEnd  $one
     * @param  TEnd  $other
     * @param  array<int, int>  $degree
     * @return array{0: TEnd, 1: TEnd}
     */
    private function downwards(array $one, array $other, array $degree): array
    {
        $a = $one['device'];
        $b = $other['device'];

        $rank = [self::rank($a), self::rank($b)];

        if ($rank[0] !== $rank[1]) {
            return $rank[0] < $rank[1] ? [$one, $other] : [$other, $one];
        }

        $first = ($degree[$b->id] ?? 0) <=> ($degree[$a->id] ?? 0)
            ?: strnatcasecmp($a->name, $b->name)
            ?: $a->id <=> $b->id;

        return $first <= 0 ? [$one, $other] : [$other, $one];
    }

    /**
     * How many links each of these devices has, all of them, those that
     * leave the diagram too, retired ones left out. A port has one link at
     * most: these are its ports that are linked.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function degrees(array $ids): array
    {
        return Device::query()
            ->whereKey($ids)
            ->withCount(['ports as links_count' => fn (Builder $query) => $query->whereHas(
                'linkEnd.link',
                fn (Builder $link) => $link->where('status', '!=', Status::Retired->value),
            )])
            ->get()
            ->mapWithKeys(fn (Device $device): array => [$device->id => (int) $device->getAttribute('links_count')])
            ->all();
    }

    /**
     * How high a device stands: the firewalls and the routers, then the
     * switches, then any other device.
     */
    private static function rank(Device $device): int
    {
        return match ($device->type) {
            DeviceType::Firewall, DeviceType::Router => 0,
            DeviceType::Switch => 1,
            default => 2,
        };
    }

    /**
     * The places of what is drawn of the site, its devices and its panels:
     * each building, its floors and their rooms, only those that keep
     * something drawn, by name, the floors by level; then the frame of the
     * devices kept in no room, if any.
     *
     * @param  list<DiagramDevice>  $devices
     * @param  Collection<int, Device>  $siteDevices
     * @param  array<int, PatchPanel>  $panels
     * @return list<DiagramPlace>
     */
    private function places(array $devices, Collection $siteDevices, array $panels): array
    {
        /** @var array<int, Room> $rooms */
        $rooms = [];
        $unplaced = false;

        foreach ($devices as $device) {
            if ($device['site'] !== null) {
                continue;
            }

            $room = $siteDevices->get($device['id'])?->room;

            if ($room === null) {
                $unplaced = true;

                continue;
            }

            $rooms[$room->id] = $room;
        }

        foreach ($panels as $panel) {
            $rooms[$panel->room_id] ??= $panel->room;
        }

        $buildings = [];

        foreach ($rooms as $room) {
            $floor = $room->floor;
            $building = $floor->building;

            $buildings[$building->id] ??= ['building' => $building, 'floors' => []];
            $buildings[$building->id]['floors'][$floor->id] ??= ['floor' => $floor, 'rooms' => []];
            $buildings[$building->id]['floors'][$floor->id]['rooms'][] = $room;
        }

        uasort($buildings, fn (array $a, array $b): int => strnatcasecmp($a['building']->name, $b['building']->name) ?: $a['building']->id <=> $b['building']->id);

        $places = [];

        foreach ($buildings as $entry) {
            $floors = $entry['floors'];

            uasort($floors, fn (array $a, array $b): int => $a['floor']->level <=> $b['floor']->level ?: $a['floor']->id <=> $b['floor']->id);

            $places[] = [
                'key' => 'building-'.$entry['building']->id,
                'kind' => 'building',
                'name' => $entry['building']->name,
                'technical' => false,
                'children' => array_values(array_map(function (array $floorEntry): array {
                    $floorRooms = $floorEntry['rooms'];

                    usort($floorRooms, fn (Room $a, Room $b): int => strnatcasecmp($a->name, $b->name) ?: $a->id <=> $b->id);

                    return [
                        'key' => 'floor-'.$floorEntry['floor']->id,
                        'kind' => 'floor',
                        'name' => $floorEntry['floor']->name,
                        'technical' => false,
                        'children' => array_map(fn (Room $room): array => [
                            'key' => 'room-'.$room->id,
                            'kind' => 'room',
                            'name' => $room->name,
                            'technical' => $room->is_technical,
                            'children' => [],
                        ], $floorRooms),
                    ];
                }, $floors)),
            ];
        }

        if ($unplaced) {
            $places[] = ['key' => self::UNPLACED, 'kind' => 'unplaced', 'name' => (string) __('Without a room'), 'technical' => false, 'children' => []];
        }

        return $places;
    }

    /**
     * Two lists of names compared one by one, as names are sorted: "sw-2"
     * before "sw-10", whatever the case.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function byNames(array $a, array $b): int
    {
        foreach ($a as $index => $name) {
            $compared = strnatcasecmp($name, $b[$index] ?? '');

            if ($compared !== 0) {
                return $compared;
            }
        }

        return 0;
    }
}
