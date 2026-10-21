<?php

// torsade/app/Http/Controllers/Network/DeviceController.php

namespace App\Http\Controllers\Network;

use App\Casts\AsIpAddress;
use App\Enums\AggregateProtocol;
use App\Enums\DeviationKind;
use App\Enums\DeviceType;
use App\Enums\IpAssignment;
use App\Enums\LinkMedium;
use App\Enums\MemberRole;
use App\Enums\PoeType;
use App\Enums\PortConnector;
use App\Enums\PortSpeed;
use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Presenters\NetworkPresenter;
use App\Http\Requests\Network\DeviceCreateRequest;
use App\Http\Requests\Network\DeviceIndexRequest;
use App\Http\Requests\Network\DeviceRequest;
use App\Http\Requests\Network\DeviceShowRequest;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\DeviceTemplate;
use App\Models\DeviceTemplatePortGroup;
use App\Models\IpAddress;
use App\Models\Link;
use App\Models\Port;
use App\Models\PortAggregate;
use App\Models\Room;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\Vlan;
use App\Services\Network\CabledPaths;
use App\Services\Network\CablingDeviations;
use App\Services\Network\Deviations;
use App\Services\Network\Devices;
use App\Services\Network\LinkDeviations;
use App\Services\Network\OppositeAggregates;
use App\Support\Actor;
use App\Support\Ipv4;
use App\Support\PortNaming;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Devices. Each route of this controller is behind the device policy (see
 * routes/network.php): reading for every active account, writing for
 * editors and administrators.
 */
class DeviceController extends Controller
{
    /**
     * How many addresses the window "Attach an address" lists at most: a
     * search that finds more is to be made more precise.
     */
    public const int ATTACHABLE_LISTED = 20;

    /**
     * How many devices the window "Link…" lists at most: a search that
     * finds more is to be made more precise.
     */
    public const int LINKABLE_LISTED = 20;

    /**
     * The devices, filtered and searched by the server.
     *
     * The number of queries does not depend on the number of devices.
     */
    public function index(DeviceIndexRequest $request): Response
    {
        /** @var array{site?: int|string|null, type?: string|null, status?: string|null, search?: string|null} $filters */
        $filters = $request->validated();

        $search = trim((string) ($filters['search'] ?? ''));

        return Inertia::render('devices/Index', [
            'devices' => self::listed(
                Device::query()
                    ->when($filters['site'] ?? null, fn (Builder $query, int|string $site) => $query->where('site_id', $site))
                    ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
                    ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
                    ->when($search !== '', function (Builder $query) use ($search): void {
                        // What was typed is looked for as it is: "%" and "_"
                        // are characters like the others.
                        $like = '%'.addcslashes($search, '\\%_').'%';

                        $query->where(fn (Builder $query) => $query
                            ->where('name', 'like', $like)
                            ->orWhere('model', 'like', $like)
                            ->orWhere('serial_number', 'like', $like));
                    }),
            ),
            'filters' => [
                'site' => isset($filters['site']) ? (string) $filters['site'] : '',
                'type' => $filters['type'] ?? '',
                'status' => $filters['status'] ?? '',
                'search' => $search,
            ],
            'sites' => $this->sites(),
            'types' => DeviceType::options(),
            'statuses' => Status::options(),
        ]);
    }

    /**
     * The lines of a table of devices, by name: the list of all of them,
     * and the section of the page of a site.
     *
     * @param  Builder<Device>  $query
     * @return list<array<string, mixed>>
     */
    public static function listed(Builder $query): array
    {
        return array_values(
            $query
                ->with(['site', 'room.floor.building'])
                ->withCount('ipAddresses')
                ->orderBy('name')
                ->orderBy('id')
                ->get()
                ->map(fn (Device $device): array => NetworkPresenter::device($device))
                ->all(),
        );
    }

    public function create(DeviceCreateRequest $request): Response
    {
        $room = $request->validated('room') === null
            ? null
            : Room::query()->with('floor.building')->whereKey((int) $request->validated('room'))->first();

        return Inertia::render('devices/Form', [
            'device' => null,
            ...$this->formOptions(),
            // Coming from the page of a site, the site is already chosen;
            // from one of its rooms, the room is too.
            'site' => $room?->siteId() ?? ($request->validated('site') === null ? null : (int) $request->validated('site')),
            'room' => $room?->id,
        ]);
    }

    public function store(DeviceRequest $request, Devices $devices): RedirectResponse
    {
        $device = $devices->create($request->deviceData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Device created.')]);

        return to_route('devices.show', $device);
    }

    /**
     * A device: where it is, its members and their ports, its addresses,
     * its links, and what differs from what is expected of them.
     *
     * The number of queries does not depend on the number of members, of
     * ports, of addresses nor of links.
     */
    public function show(DeviceShowRequest $request, Device $device, Deviations $deviations, LinkDeviations $linkDeviations, CablingDeviations $cabling, CabledPaths $paths, OppositeAggregates $opposites): Response
    {
        $device->load(['site', 'room.floor.building'])->loadCount('ipAddresses');

        // Its links, in the order of its ports, and where their two ends
        // do not agree, or the cabling with them. A link may join two
        // sites: the cabling of each end is read on its own site.
        $links = $this->linksOf($device);
        $map = $paths->of([
            $device->site_id,
            ...$links->flatMap(fn (Link $link): array => $link->ends->map(fn ($end): int => $end->port->device->site_id)->all())->all(),
        ]);
        $differing = $linkDeviations->ofLinks($links, $device->id, $map);
        $lines = NetworkPresenter::linkLines($links, $differing, $device->id, $map->paths());

        // What each linked port of the device faces.
        $facing = [];

        foreach ($links as $link) {
            [$near, $far] = $link->portsFrom($device->id);

            $facing[$near->id] = ['id' => $link->id, 'device' => ['id' => $far->device->id, 'name' => $far->device->name], 'port' => $far->name];
        }

        $aggregateDeviations = $linkDeviations->ofAggregates($links, $device->id);

        $aggregateModels = $device->aggregates()
            ->with('portVlans.vlan.site')
            ->get()
            ->each(fn (PortAggregate $aggregate) => $aggregate->setRelation('device', $device));

        $aggregates = $aggregateModels->map(fn (PortAggregate $aggregate): array => NetworkPresenter::aggregate($aggregate, $aggregateDeviations[$aggregate->id] ?? []));

        $addresses = $device->ipAddresses()
            ->with(['subnet.vlan.site', 'subnet.site', ...NetworkPresenter::ADDRESS_JACK])
            ->orderBy('address')
            ->get()
            ->each(fn (IpAddress $address) => $address->setRelation('device', $device));

        $members = $device->members()
            ->with(['template', 'room.floor.building'])
            ->withCount('ports')
            ->get();

        // Its ports, each with how its cabling differs from what is
        // declared around it.
        $ports = $device->ports()
            ->with([...CablingDeviations::PORT, 'jackCord.jack.floor.building'])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->each(fn (Port $port) => $port->setRelation('device', $device));

        $portDeviations = [];

        foreach ($ports as $port) {
            $portDeviations[$port->id] = [...$cabling->ofPort($port), ...$map->ofPort($port)];
        }

        // A switch has one table of VLANs: what its ports and its
        // aggregates carry under one number is one network.
        $merged = $deviations->ofSwitch($device, [...$ports->all(), ...$aggregateModels->all()]);

        // What its ports and its aggregates carry, read above: an address of
        // another site is on a network the device is plugged into.
        $device->setRelation('carriedByPorts', new Collection($ports->flatMap(fn (Port $port) => $port->portVlans->all())->all()));
        $device->setRelation('carriedByAggregates', new Collection($aggregateModels->flatMap(fn (PortAggregate $aggregate) => $aggregate->portVlans->all())->all()));

        return Inertia::render('devices/Show', [
            'device' => NetworkPresenter::device($device),
            'members' => $members->map(fn (DeviceMember $member): array => NetworkPresenter::member($member)),
            // What the window of a member chooses among: the templates,
            // with what names their ports would get, the roles, and the
            // rooms of the site of the device.
            'templates' => $this->templates(),
            'memberRoles' => MemberRole::options(),
            'rooms' => $this->roomsOf($device),
            'memberLimits' => ['min' => DeviceMember::MIN_POSITION, 'max' => DeviceMember::MAX_POSITION],
            // The ports of every member, in the order of each member: the
            // page groups them.
            'ports' => $ports->map(fn (Port $port): array => NetworkPresenter::port($port, $facing[$port->id] ?? null, $portDeviations[$port->id])),
            // Its aggregates of ports, by name: their ports are those of
            // the list above that name them.
            'aggregates' => $aggregates,
            'aggregateProtocols' => AggregateProtocol::options(),
            // The aggregate to create in front of another one, asked from
            // its deviation: the window of a new aggregate opens with it.
            'aggregateDraft' => $this->aggregateDraft($request, $device, $opposites),
            // The link to create between two ports cabled to each other,
            // asked from its deviation: the window "Link…" of the port
            // opens with the port in front chosen.
            'linkDraft' => $this->linkDraft($request, $device),
            // Its links, the aggregated ones as one line each.
            'links' => $lines,
            'linkMedia' => LinkMedium::options(),
            // What differs about its VLANs, its links and its aggregates,
            // object by object, for the list of the deviations of the page.
            'linkDeviations' => [
                ...($merged === [] ? [] : [['key' => 'vlans', 'label' => __('VLANs'), 'section' => 'ports', 'deviations' => $merged]]),
                ...$this->portDeviationItems($ports, $portDeviations),
                ...$this->linkDeviationItems($lines, $aggregates->all(), array_merge(...array_values($portDeviations))),
            ],
            // The VLANs a port of the device can carry, site by site.
            'vlans' => $this->vlanChoices($device),
            'portOptions' => [
                'connectors' => PortConnector::options(),
                'speeds' => PortSpeed::options(),
                'poeTypes' => PoeType::options(),
                'limits' => [
                    'name' => Port::MAX_NAME_LENGTH,
                    'position' => Port::MAX_POSITION,
                    'first_number' => PortNaming::MAX_FIRST_NUMBER,
                    'quantity' => PortNaming::MAX_QUANTITY,
                ],
            ],
            'addresses' => $addresses->map(fn (IpAddress $address): array => NetworkPresenter::deviceAddress($address, $deviations)),
            // The jacks whose label holds what is typed in the field "Jack"
            // of the window of a new address.
            'jackChoices' => Inertia::optional(fn (): array => JackController::plugChoices($request->jackSite(), $request->jackSearch())),
            // What the window of a new address offers: every subnet, those
            // of the site of the device first.
            'subnets' => $this->subnets($device),
            'deviceTypes' => DeviceType::options(),
            'assignments' => IpAssignment::options(),
            // The statuses offered for an IP address or for a link, agreed
            // with them.
            'statuses' => Status::options(feminine: true),
            // The addresses that can be attached, looked for only when the
            // window that lists them asks.
            'attachable' => Inertia::optional(fn (): array => $this->attachable($request->search())),
            // The devices a port can be linked to, and the free ports of
            // the one chosen: looked for when the window "Link…" asks.
            'linkable' => Inertia::optional(fn (): array => $this->linkable($device, $request->search())),
            'oppositePorts' => Inertia::optional(fn (): ?array => $this->freePortsOf($device, $request->opposite())),
            // The jacks of the site a port can be plugged into, looked for
            // when the window "Plug into a jack" asks.
            'pluggableJacks' => Inertia::optional(fn (): array => JackController::pluggableJacks($device->site_id)),
        ]);
    }

    public function edit(Device $device): Response
    {
        return Inertia::render('devices/Form', [
            'device' => [
                'id' => $device->id,
                'site_id' => $device->site_id,
                'room_id' => $device->room_id,
                'name' => $device->name,
                'type' => $device->type->value,
                'manufacturer' => $device->manufacturer,
                'model' => $device->model,
                'serial_number' => $device->serial_number,
                'status' => $device->status->value,
                'description' => $device->description,
                // Once a device has members, each of them has its own
                // serial number: the form no longer asks for one.
                'has_members' => $device->members()->exists(),
            ],
            ...$this->formOptions(),
            'site' => null,
            'room' => null,
        ]);
    }

    public function update(DeviceRequest $request, Device $device, Devices $devices): RedirectResponse
    {
        $devices->update($device, $request->deviceData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Device updated.')]);

        return to_route('devices.show', $device);
    }

    public function destroy(Request $request, Device $device, Devices $devices): RedirectResponse
    {
        $devices->delete($device, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Device deleted.')]);

        return to_route('devices.index');
    }

    /**
     * What the form of a device chooses among: the sites, and every room
     * with its path and its site, the form showing those of the site
     * chosen.
     *
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'sites' => $this->sites(),
            'rooms' => array_values(
                Room::query()
                    ->with('floor.building')
                    ->get()
                    ->map(fn (Room $room): array => [...NetworkPresenter::place($room), 'site_id' => $room->siteId()])
                    ->sortBy('path', SORT_NATURAL | SORT_FLAG_CASE)
                    ->all(),
            ),
            'types' => DeviceType::options(),
            'statuses' => Status::options(),
        ];
    }

    /**
     * The templates a member can be made from, by name, each with the
     * groups that name its ports.
     *
     * @return list<array<string, mixed>>
     */
    private function templates(): array
    {
        return array_values(
            DeviceTemplate::query()
                ->with('portGroups')
                ->orderBy('name')
                ->get()
                ->map(fn (DeviceTemplate $template): array => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'ports_count' => (int) $template->portGroups->sum('quantity'),
                    'port_groups' => array_values($template->portGroups
                        ->map(fn (DeviceTemplatePortGroup $group): array => [
                            'quantity' => $group->quantity,
                            'name_pattern' => $group->name_pattern,
                            'first_number' => $group->first_number,
                        ])
                        ->all()),
                ])
                ->all(),
        );
    }

    /**
     * The VLANs a port or an aggregate of a device can carry, one group per
     * site: the site of the device first, even without a VLAN, then every
     * other site that has some, by name. The VLANs of each site by number.
     *
     * @return list<array{site: array{id: int, name: string}, vlans: list<array{id: int, vid: int, name: string}>}>
     */
    private function vlanChoices(Device $device): array
    {
        $groups = [];

        $sites = Site::query()
            ->with(['vlans' => fn ($query) => $query->orderBy('vid')])
            ->orderBy('name')
            ->get();

        foreach ($sites as $site) {
            $own = $site->id === $device->site_id;

            if (! $own && $site->vlans->isEmpty()) {
                continue;
            }

            $group = [
                'site' => ['id' => $site->id, 'name' => $site->name],
                'vlans' => array_values($site->vlans->map(fn (Vlan $vlan): array => ['id' => $vlan->id, 'vid' => $vlan->vid, 'name' => $vlan->name])->all()),
            ];

            if ($own) {
                array_unshift($groups, $group);
            } else {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /**
     * The rooms of the site of a device, by path: where one of its members
     * can be kept, apart from the device.
     *
     * @return list<array<string, mixed>>
     */
    private function roomsOf(Device $device): array
    {
        return array_values(
            Room::query()
                ->whereHas('floor.building', fn (Builder $query) => $query->where('site_id', $device->site_id))
                ->with('floor.building')
                ->get()
                ->map(fn (Room $room): array => NetworkPresenter::place($room))
                ->sortBy('path', SORT_NATURAL | SORT_FLAG_CASE)
                ->all(),
        );
    }

    /**
     * The window of the aggregate to create on this device in front of the
     * aggregate given in the address, for whoever may create one; nothing
     * when that aggregate is gone or does not call for one here.
     *
     * @return array<string, mixed>|null
     */
    private function aggregateDraft(DeviceShowRequest $request, Device $device, OppositeAggregates $opposites): ?array
    {
        $origin = $request->oppositeOf();

        if ($origin === null || ! $request->user()?->can('create', PortAggregate::class)) {
            return null;
        }

        $aggregate = PortAggregate::query()->find($origin);

        return $aggregate === null || $aggregate->device_id === $device->id ? null : $opposites->draft($aggregate, $device);
    }

    /**
     * The window "Link…" of a port of this device, with the port in front
     * chosen, for whoever may create a link; nothing when one of the two
     * ports is gone, is already linked, or is not where the address says.
     *
     * @return array{port_id: int, opposite: array{device: array{id: int, name: string, site: string}, port_id: int}}|null
     */
    private function linkDraft(DeviceShowRequest $request, Device $device): ?array
    {
        $from = $request->linkPort();
        $to = $request->linkTo();

        if ($from === null || $to === null || ! $request->user()?->can('create', Link::class)) {
            return null;
        }

        $port = Port::query()->where('device_id', $device->id)->whereKey($from)->doesntHave('linkEnd')->first();
        $opposite = Port::query()->where('device_id', '!=', $device->id)->whereKey($to)->doesntHave('linkEnd')->with('device.site')->first();

        return $port === null || $opposite === null ? null : [
            'port_id' => $port->id,
            'opposite' => [
                'device' => ['id' => $opposite->device->id, 'name' => $opposite->device->name, 'site' => $opposite->device->site->name],
                'port_id' => $opposite->id,
            ],
        ];
    }

    /**
     * The links of a device, in the order of its ports, read with what
     * their lines and their deviations need.
     *
     * @return Collection<int, Link>
     */
    private function linksOf(Device $device): Collection
    {
        return Link::query()
            ->whereHas('ends.port', fn (Builder $query) => $query->where('device_id', $device->id))
            ->with([...LinkDeviations::RELATIONS, 'ends.port.member', 'ends.port.device.site'])
            ->get()
            ->sortBy(function (Link $link) use ($device): array {
                [$near] = $link->portsFrom($device->id);

                return [$near->member->position, $near->position, $near->id];
            })
            ->values();
    }

    /**
     * What differs about the cabling of the ports of the device, for the
     * list of the deviations of its page: one item per port, in the order
     * of the ports.
     *
     * @param  Collection<int, Port>  $ports
     * @param  array<int, list<array<string, mixed>>>  $deviations  By the identifier of the port.
     * @return list<array{key: string, label: string, section: string, deviations: list<array<string, mixed>>}>
     */
    private function portDeviationItems(Collection $ports, array $deviations): array
    {
        $items = [];

        foreach ($ports as $port) {
            if (($deviations[$port->id] ?? []) !== []) {
                $items[] = ['key' => 'port-'.$port->id, 'label' => $port->name, 'section' => 'ports', 'deviations' => $deviations[$port->id]];
            }
        }

        return $items;
    }

    /**
     * What differs about the links and the aggregates of the device, for
     * the list of the deviations of its page.
     *
     * An aggregate tells what it faces once, and its links do not repeat
     * it. A port shut down under an active link is told on the page of the
     * device of that port. What is already told on a port of the device,
     * a linked port patched to a jack where nothing is plugged, is not
     * told again on its link.
     *
     * @param  list<array{key: string, aggregated: bool, links: list<array<string, mixed>>, deviations: list<array<string, mixed>>}>  $lines
     * @param  array<int, array<string, mixed>>  $aggregates
     * @param  list<array<string, mixed>>  $onPorts  What is told on the ports of the device.
     * @return list<array{key: string, label: string, section: string, deviations: list<array<string, mixed>>}>
     */
    private function linkDeviationItems(array $lines, array $aggregates, array $onPorts): array
    {
        $lines = array_map(fn (array $line): array => [
            ...$line,
            'deviations' => array_values(array_filter(
                $line['deviations'],
                fn (array $deviation): bool => ! in_array($deviation, $onPorts, true),
            )),
        ], $lines);

        $items = [];
        $told = [];

        foreach ($aggregates as $aggregate) {
            if ($aggregate['deviations'] === []) {
                continue;
            }

            $items[] = ['key' => 'aggregate-'.$aggregate['id'], 'label' => $aggregate['name'], 'section' => 'aggregates', 'deviations' => $aggregate['deviations']];
            $told = [...$told, ...$aggregate['deviations']];
        }

        foreach ($lines as $line) {
            $ownPortDisabled = array_any($line['links'], fn (array $link): bool => ! $link['near']['port']['enabled']);

            $deviations = array_values(array_filter(
                $line['deviations'],
                fn (array $deviation): bool => ! in_array($deviation, $told, true)
                    && ($deviation['kind'] !== DeviationKind::LinkOnDisabledPort->value || $ownPortDisabled),
            ));

            if ($deviations === []) {
                continue;
            }

            $first = $line['links'][0];

            $items[] = [
                'key' => $line['key'],
                'label' => $line['aggregated']
                    ? $first['near']['aggregate']['name'].' ↔ '.$first['far']['device']['name'].' › '.$first['far']['aggregate']['name']
                    : $first['near']['port']['name'].' ↔ '.$first['far']['device']['name'].' › '.$first['far']['port']['name'],
                'section' => 'links',
                'deviations' => $deviations,
            ];
        }

        return $items;
    }

    /**
     * The devices a port of the device can be linked to: every other
     * device, on every site, whose name holds what was typed, with how
     * many of its ports are free.
     *
     * @return array{items: list<array<string, mixed>>, more: bool}
     */
    private function linkable(Device $device, string $search): array
    {
        $found = Device::query()
            ->whereKeyNot($device->id)
            // What was typed is looked for as it is: "%" and "_" are
            // characters like the others.
            ->when($search !== '', fn (Builder $query) => $query->where('name', 'like', '%'.addcslashes($search, '\\%_').'%'))
            ->with('site')
            ->withCount(['ports as free_ports_count' => fn (Builder $query) => $query->doesntHave('linkEnd')])
            ->orderBy('name')
            ->orderBy('id')
            // One more than listed, to know whether there are more.
            ->limit(self::LINKABLE_LISTED + 1)
            ->get();

        return [
            'items' => array_values($found->take(self::LINKABLE_LISTED)->map(fn (Device $other): array => [
                'id' => $other->id,
                'name' => $other->name,
                'site' => $other->site->name,
                'type_label' => __($other->type->label()),
                'free_ports' => (int) $other->getAttribute('free_ports_count'),
            ])->all()),
            'more' => $found->count() > self::LINKABLE_LISTED,
        ];
    }

    /**
     * The ports of the opposite device chosen that no link uses yet, in
     * the order of the device, each with the summary of its configuration.
     * Null while no other device is chosen.
     *
     * @return array{device: array{id: int, name: string, site: string}, ports: list<array<string, mixed>>}|null
     */
    private function freePortsOf(Device $device, ?int $oppositeId): ?array
    {
        $opposite = $oppositeId === null || $oppositeId === $device->id
            ? null
            : Device::query()->with('site')->find($oppositeId);

        if ($opposite === null) {
            return null;
        }

        return [
            'device' => ['id' => $opposite->id, 'name' => $opposite->name, 'site' => $opposite->site->name],
            'ports' => array_values(
                $opposite->ports()
                    ->doesntHave('linkEnd')
                    ->with(['member', 'aggregate.portVlans.vlan.site', 'portVlans.vlan.site'])
                    ->get()
                    ->sortBy(fn (Port $port): array => [$port->member->position, $port->position, $port->id])
                    ->map(fn (Port $port): array => [
                        'id' => $port->id,
                        'name' => $port->name,
                        'member' => $port->member->position,
                        'enabled' => $port->enabled,
                        'aggregate' => $port->aggregate?->name,
                        // The mode and the VLANs of a port of an aggregate
                        // are those of the aggregate.
                        'summary' => ($port->aggregate ?? $port)->vlanSummary($opposite->site_id),
                    ])
                    ->all(),
            ),
        ];
    }

    /**
     * The addresses that belong to no device and match what was typed: the
     * beginning of an address, or a part of a host name. Every site is
     * looked into; an address of another site than the device can be
     * attached, and is then shown as a deviation.
     *
     * @return array{items: list<array<string, mixed>>, more: bool}
     */
    private function attachable(string $search): array
    {
        $ranges = Ipv4::startingWith($search);

        $found = IpAddress::query()
            ->whereNull('device_id')
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search, $ranges): void {
                $query->where('hostname', 'like', '%'.addcslashes($search, '\\%_').'%');

                foreach ($ranges as [$first, $last]) {
                    $query->orWhereBetween('address', [AsIpAddress::toBinary($first), AsIpAddress::toBinary($last)]);
                }
            }))
            ->with('subnet.site')
            ->orderBy('address')
            // One more than listed, to know whether there are more.
            ->limit(self::ATTACHABLE_LISTED + 1)
            ->get();

        return [
            'items' => array_values($found->take(self::ATTACHABLE_LISTED)->map(fn (IpAddress $address): array => [
                'id' => $address->id,
                'address' => $address->address,
                'hostname' => $address->hostname,
                'subnet' => $address->subnet->cidr(),
                'site' => $address->subnet->site?->name,
            ])->all()),
            'more' => $found->count() > self::ATTACHABLE_LISTED,
        ];
    }

    /**
     * Every subnet, as a choice in a list, with its usable addresses: the
     * window of a new address says where the address may lie.
     *
     * @return list<array<string, mixed>>
     */
    private function subnets(Device $device): array
    {
        return array_values(
            Subnet::query()
                ->with(['vlan', 'site'])
                ->orderBy('network')
                ->orderBy('prefix_length')
                ->get()
                ->map(function (Subnet $subnet): array {
                    [$first, $last] = Ipv4::usableRange($subnet->network, $subnet->prefix_length);

                    return [
                        'id' => $subnet->id,
                        'cidr' => $subnet->cidr(),
                        // What tells a subnet from another: its VLAN, or
                        // its own name.
                        'label' => $subnet->vlan?->label() ?? $subnet->name,
                        'first_usable' => $first,
                        'last_usable' => $last,
                        'site_id' => $subnet->site_id,
                        'site' => $subnet->site?->name,
                    ];
                })
                // Those of the site of the device first; the order of the
                // addresses is kept within each group.
                ->sortBy(fn (array $subnet): int => $subnet['site_id'] === $device->site_id ? 0 : 1)
                ->all(),
        );
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function sites(): array
    {
        return array_values(
            Site::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Site $site): array => ['id' => $site->id, 'name' => $site->name])
                ->all(),
        );
    }
}
