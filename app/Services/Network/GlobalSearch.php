<?php

// torsade/app/Services/Network/GlobalSearch.php

namespace App\Services\Network;

use App\Casts\AsIpAddress;
use App\Enums\Status;
use App\Http\Presenters\NetworkPresenter;
use App\Models\AddressBlock;
use App\Models\Building;
use App\Models\CatalogVlan;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\DeviceTemplate;
use App\Models\Floor;
use App\Models\IpAddress;
use App\Models\Jack;
use App\Models\PatchPanel;
use App\Models\Port;
use App\Models\Room;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\User;
use App\Models\Vlan;
use App\Support\Ipv4;
use App\Support\SearchInput;
use App\Support\SearchText;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as BaseCollection;

/**
 * The search of the whole documentation, from one field: what was typed is
 * first read as an address, a prefix, a VLAN number, a MAC address or a
 * device followed by a port (SearchInput), each form giving results of its
 * own, then looked for as text in the names and descriptions of the
 * network objects. Accounts and the activity log are never searched.
 *
 * Results come in groups, one per kind of object. In a group: an exact
 * match first, then a match at the start of a value, then a match
 * anywhere; the objects that are not retired before the retired ones;
 * then the natural order of the objects. A group that holds an exact match
 * comes before those that do not, then the groups of the forms recognised,
 * then the others in a fixed order.
 *
 * Ordinary queries with bindings: the volumes of a small organisation do
 * not call for a search engine. The number of queries does not depend on
 * the volume: two per group (the count and the page), and those that load
 * what the results show.
 */
final class GlobalSearch
{
    /**
     * The groups, in the order they come in when none of them holds an
     * exact match nor results of a recognised form.
     */
    public const array GROUPS = ['sites', 'places', 'vlans', 'catalog', 'blocks', 'subnets', 'addresses', 'devices', 'ports', 'jacks', 'panels', 'templates'];

    /**
     * Results of each group in the search window, and on each page of a
     * group on the page of results.
     */
    public const int WINDOW_LIMIT = 5;

    public const int PAGE_LIMIT = 25;

    /**
     * At most, blocks and subnets together, around a prefix that none of
     * them is equal to.
     */
    public const int AROUND_PREFIX = 50;

    /**
     * Where the text lies in a value: equal to it, at its start, anywhere.
     */
    private const int EXACT = 0;

    private const int START = 1;

    private const int ANYWHERE = 2;

    /**
     * The results for what was typed: every group, or only the one whose
     * next results are asked for.
     *
     * @return array{query: string, notice: string|null, undocumented: array<string, mixed>|null, groups: list<array{key: string, label: string, total: int, exact: bool, items: list<array<string, mixed>>}>, total: int}
     */
    public function search(SearchInput $input, User $user, int $limit, ?string $only = null, int $offset = 0): array
    {
        $context = new SearchContext($input, $limit, $offset);

        $groups = [];

        foreach (self::GROUPS as $index => $key) {
            if ($only !== null && $only !== $key) {
                continue;
            }

            $group = match ($key) {
                'sites' => $this->sites($context),
                'places' => $this->places($context),
                'vlans' => $this->vlans($context),
                'catalog' => $this->catalog($context),
                'blocks' => $this->blocks($context),
                'subnets' => $this->subnets($context),
                'addresses' => $this->addresses($context),
                'devices' => $this->devices($context),
                'ports' => $this->ports($context),
                'jacks' => $this->jacks($context),
                'panels' => $this->panels($context),
                default => $this->templates($context),
            };

            if ($group !== null && $group['total'] > 0) {
                $groups[] = ['key' => $key, 'index' => $index, ...$group];
            }
        }

        usort($groups, fn (array $a, array $b): int => [! $a['exact'], ! $a['recognised'], $a['index']] <=> [! $b['exact'], ! $b['recognised'], $b['index']]);

        return [
            'query' => $input->text,
            'notice' => $input->prefixWasBroughtBack()
                ? (string) __('Looked for at its network address: :cidr', ['cidr' => (string) $input->prefixCidr()])
                : null,
            'undocumented' => $only === null ? $this->undocumented($input, $user) : null,
            'groups' => array_map(fn (array $group): array => [
                'key' => $group['key'],
                'label' => (string) __($this->groupLabel($group['key'])),
                'total' => $group['total'],
                'exact' => $group['exact'],
                'items' => $group['items'],
            ], $groups),
            'total' => array_sum(array_column($groups, 'total')),
        ];
    }

    private function groupLabel(string $key): string
    {
        return match ($key) {
            'sites' => 'Sites',
            'places' => 'Places',
            'vlans' => 'VLANs',
            'catalog' => 'VLAN catalogue',
            'blocks' => 'Address blocks',
            'subnets' => 'Subnets',
            'addresses' => 'IP addresses',
            'devices' => 'Devices',
            'ports' => 'Ports',
            'jacks' => 'Jacks',
            'panels' => 'Patch panels',
            default => 'Device templates',
        };
    }

    /**
     * An address that no record holds: the subnet it lies in, and, for
     * those who may write, the way to document it when a machine of that
     * subnet can hold it.
     *
     * @return array{address: string, subnet: array{id: int, cidr: string, name: string|null, url: string}|null, document_url: string|null}|null
     */
    private function undocumented(SearchInput $input, User $user): ?array
    {
        $address = $input->address;

        if ($address === null || IpAddress::query()->where('address', AsIpAddress::toBinary($address))->exists()) {
            return null;
        }

        $subnet = Subnet::query()->where($this->holding('subnets', $address, Ipv4::MAX_PREFIX))->first();

        return [
            'address' => $address,
            'subnet' => $subnet === null ? null : [
                'id' => $subnet->id,
                'cidr' => $subnet->cidr(),
                'name' => $subnet->name,
                'url' => route('subnets.show', $subnet),
            ],
            // The window of a new address opens filled in with it; the
            // form keeps its own refusals (gateway, DHCP range).
            'document_url' => $subnet !== null
                && $user->can('create', IpAddress::class)
                && Ipv4::isUsable($subnet->network, $subnet->prefix_length, $address)
                    ? route('subnets.show', ['subnet' => $subnet, 'address' => $address])
                    : null,
        ];
    }

    // ----- The groups ------------------------------------------------------

    /**
     * @return array{recognised: bool, total: int, exact: bool, items: list<array<string, mixed>>}|null
     */
    private function sites(SearchContext $context): ?array
    {
        $text = $context->text(['sites.name', 'sites.code']);

        if ($text === null) {
            return null;
        }

        [$total, $sites, $exact] = $this->fetch(
            Site::query()->where($text['where']),
            $this->rank($text['exact'], $text['start']),
            $context,
            order: ['NATURAL_SORT_KEY(sites.name)', 'sites.id'],
            status: 'sites.status',
        );

        return $this->group(false, $total, $exact, $sites->map(fn (Site $site): array => $this->item(
            $context,
            type: 'site',
            id: $site->id,
            label: $context->highlight($site->name),
            context: [$site->code === null ? null : $context->highlight($site->code)],
            excerpts: [],
            status: $site->status,
            url: route('sites.show', $site),
        )));
    }

    /**
     * Buildings, floors and rooms together, in the order of the group: by
     * rank, then by name in the natural order, buildings before floors and
     * floors before rooms for the same rank and name. The database orders
     * the three kinds at once, as it orders the names of each, and reads
     * the page alone; then each kind is read with what its line shows.
     *
     * @return array{recognised: bool, total: int, exact: bool, items: list<array<string, mixed>>}|null
     */
    private function places(SearchContext $context): ?array
    {
        $buildingText = $context->text(['buildings.name', 'buildings.code']);
        $floorText = $context->text(['floors.name']);
        $roomText = $context->text(['rooms.name']);

        if ($buildingText === null || $floorText === null || $roomText === null) {
            return null;
        }

        $buildings = Building::query()->where($buildingText['where']);
        $floors = Floor::query()->where($floorText['where']);
        $rooms = Room::query()->where($roomText['where']);

        $total = (clone $buildings)->count() + (clone $floors)->count() + (clone $rooms)->count();
        $limit = min($context->limit, $total - $context->offset);

        if ($limit <= 0) {
            return ['recognised' => false, 'total' => $total, 'exact' => false, 'items' => []];
        }

        $buildingRank = $this->rank($buildingText['exact'], $buildingText['start']);
        $floorRank = $this->rank($floorText['exact'], $floorText['start']);
        $roomRank = $this->rank($roomText['exact'], $roomText['start']);

        $rows = $buildings->toBase()
            ->selectRaw('0 AS kind, buildings.id, '.$buildingRank[0].' AS search_rank, NATURAL_SORT_KEY(buildings.name) AS name_key', $buildingRank[1])
            ->unionAll($floors->toBase()->selectRaw('1 AS kind, floors.id, '.$floorRank[0].' AS search_rank, NATURAL_SORT_KEY(floors.name) AS name_key', $floorRank[1]))
            ->unionAll($rooms->toBase()->selectRaw('2 AS kind, rooms.id, '.$roomRank[0].' AS search_rank, NATURAL_SORT_KEY(rooms.name) AS name_key', $roomRank[1]))
            ->orderBy('search_rank')
            ->orderBy('name_key')
            ->orderBy('kind')
            ->orderBy('id')
            ->offset($context->offset)
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => ['kind' => (int) $row->kind, 'id' => (int) $row->id, 'rank' => (int) $row->search_rank]);

        $ids = fn (int $kind): array => $rows->where('kind', $kind)->pluck('id')->all();
        $places = [
            Building::query()->with('site')->findMany($ids(0))->keyBy('id'),
            Floor::query()->with('building.site')->findMany($ids(1))->keyBy('id'),
            Room::query()->with('floor.building.site')->findMany($ids(2))->keyBy('id'),
        ];

        return [
            'recognised' => false,
            'total' => $total,
            'exact' => $context->offset === 0 && $rows->first()['rank'] === self::EXACT,
            'items' => array_values($rows
                ->map(fn (array $row): array => $this->placeItem($context, $places[$row['kind']]->get($row['id'])))
                ->all()),
        ];
    }

    /**
     * A place has no page of its own: it is shown on the page of its site,
     * at its line.
     *
     * @return array<string, mixed>
     */
    private function placeItem(SearchContext $context, Building|Floor|Room $place): array
    {
        return match (true) {
            $place instanceof Building => $this->item(
                $context,
                type: 'building',
                id: $place->id,
                label: $context->highlight($place->name),
                context: [$this->plain($place->site->name), $place->code === null ? null : $context->highlight($place->code)],
                excerpts: [],
                status: null,
                url: route('sites.show', $place->site_id).'#building-'.$place->id,
            ),
            $place instanceof Floor => $this->item(
                $context,
                type: 'floor',
                id: $place->id,
                label: $context->highlight($place->name),
                context: [$this->plain($place->building->site->name), $this->plain($place->building->name)],
                excerpts: [],
                status: null,
                url: route('sites.show', $place->building->site_id).'#floor-'.$place->id,
            ),
            default => $this->item(
                $context,
                type: 'room',
                id: $place->id,
                label: $context->highlight($place->name),
                context: [
                    $this->plain($place->floor->building->site->name),
                    $this->plain($place->floor->path()),
                    $place->is_technical ? $this->plain(__('Technical')) : null,
                ],
                excerpts: [],
                status: null,
                url: route('sites.show', $place->floor->building->site_id).'#room-'.$place->id,
            ),
        };
    }

    /**
     * @return array{recognised: bool, total: int, exact: bool, items: list<array<string, mixed>>}|null
     */
    private function vlans(SearchContext $context): ?array
    {
        $vid = $context->input->vid;
        $text = $context->text(['vlans.name', 'vlans.description']);

        if ($vid === null && $text === null) {
            return null;
        }

        [$total, $vlans, $exact] = $this->fetch(
            Vlan::query()
                ->where(function (Builder $query) use ($vid, $text): void {
                    if ($vid !== null) {
                        $query->orWhere('vlans.vid', $vid);
                    }

                    if ($text !== null) {
                        $query->orWhere($text['where']);
                    }
                })
                ->with('site'),
            $this->rank(
                [...($vid === null ? [] : [['vlans.vid = ?', [$vid]]]), ...($text['exact'] ?? [])],
                $text['start'] ?? [],
            ),
            $context,
            order: ['vlans.vid', 'NATURAL_SORT_KEY(vlans.name)', 'vlans.id'],
            status: 'vlans.status',
        );

        return $this->group($vid !== null, $total, $exact, $vlans->map(fn (Vlan $vlan): array => $this->item(
            $context,
            type: 'vlan',
            id: $vlan->id,
            label: [...$this->whole((string) $vlan->vid, $vlan->vid === $vid, mono: true), ...$this->plain(' — '), ...$context->highlight($vlan->name)],
            context: [$this->plain($vlan->site->name)],
            excerpts: [[__('Description'), $vlan->description]],
            status: $vlan->status,
            url: route('vlans.show', $vlan),
        )));
    }

    /**
     * @return array{recognised: bool, total: int, exact: bool, items: list<array<string, mixed>>}|null
     */
    private function catalog(SearchContext $context): ?array
    {
        $vid = $context->input->vid;
        $text = $context->text(['catalog_vlans.name', 'catalog_vlans.description']);

        if ($vid === null && $text === null) {
            return null;
        }

        [$total, $entries, $exact] = $this->fetch(
            CatalogVlan::query()->where(function (Builder $query) use ($vid, $text): void {
                if ($vid !== null) {
                    $query->orWhere('catalog_vlans.vid', $vid);
                }

                if ($text !== null) {
                    $query->orWhere($text['where']);
                }
            }),
            $this->rank(
                [...($vid === null ? [] : [['catalog_vlans.vid = ?', [$vid]]]), ...($text['exact'] ?? [])],
                $text['start'] ?? [],
            ),
            $context,
            order: ['catalog_vlans.vid', 'catalog_vlans.id'],
        );

        return $this->group($vid !== null, $total, $exact, $entries->map(fn (CatalogVlan $entry): array => $this->item(
            $context,
            type: 'catalog',
            id: $entry->id,
            label: [...$this->whole((string) $entry->vid, $entry->vid === $vid, mono: true), ...$this->plain(' — '), ...$context->highlight($entry->name)],
            context: [],
            excerpts: [[__('Description'), $entry->description]],
            status: null,
            url: route('catalog-vlans.show', $entry),
        )));
    }

    /**
     * @return array{recognised: bool, total: int, exact: bool, items: list<array<string, mixed>>}|null
     */
    private function blocks(SearchContext $context): ?array
    {
        $prefix = $this->prefixConditions($context, 'address_blocks');
        $text = $context->text(['address_blocks.name', 'address_blocks.description']);

        if ($prefix !== null) {
            [$where, $rank, $cap] = [$prefix['where'], $this->rank($prefix['exact'], []), $prefix['cap']];
        } elseif ($text !== null) {
            [$where, $rank, $cap] = [$text['where'], $this->rank($text['exact'], $text['start']), null];
        } else {
            return null;
        }

        [$total, $blocks, $exact] = $this->fetch(
            AddressBlock::query()->where($where)->with('site'),
            $rank,
            $context,
            order: ['address_blocks.network', 'address_blocks.prefix_length'],
            status: 'address_blocks.status',
            cap: $cap,
        );

        return $this->group($prefix !== null, $total, $exact, $blocks->map(fn (AddressBlock $block): array => $this->item(
            $context,
            type: 'block',
            id: $block->id,
            label: $this->whole($block->cidr(), $block->cidr() === $context->input->prefixCidr(), mono: true),
            context: [$this->plain($block->site->name ?? __('Organisation'))],
            excerpts: [[__('Name'), $block->name], [__('Description'), $block->description]],
            status: $block->status,
            url: route('address-blocks.show', $block),
        )));
    }

    /**
     * @return array{recognised: bool, total: int, exact: bool, items: list<array<string, mixed>>}|null
     */
    private function subnets(SearchContext $context): ?array
    {
        $input = $context->input;
        $prefix = $this->prefixConditions($context, 'subnets');
        $text = $context->text(['subnets.name', 'subnets.description']);

        if ($prefix !== null) {
            [$where, $rank, $cap] = [$prefix['where'], $this->rank($prefix['exact'], []), $prefix['cap']];
        } elseif ($input->addressRange !== null) {
            $bounds = array_map(AsIpAddress::toBinary(...), $input->addressRange);

            // Every subnet found starts as typed.
            [$where, $rank, $cap] = [
                fn (Builder $query) => $query->whereBetween('subnets.network', $bounds),
                ['1', []],
                null,
            ];
        } elseif ($text !== null) {
            [$where, $rank, $cap] = [$text['where'], $this->rank($text['exact'], $text['start']), null];
        } else {
            return null;
        }

        [$total, $subnets, $exact] = $this->fetch(
            Subnet::query()->where($where)->with(['vlan', 'site']),
            $rank,
            $context,
            order: ['subnets.network', 'subnets.prefix_length'],
            status: 'subnets.status',
            cap: $cap,
        );

        return $this->group($text === null, $total, $exact, $subnets->map(fn (Subnet $subnet): array => $this->item(
            $context,
            type: 'subnet',
            id: $subnet->id,
            label: $this->whole($subnet->cidr(), $subnet->cidr() === $input->prefixCidr(), mono: true),
            context: [
                $subnet->name === null ? null : $context->highlight($subnet->name),
                $subnet->vlan === null ? null : $this->plain(__('VLAN :label', ['label' => $subnet->vlan->label()])),
                $subnet->site === null ? null : $this->plain($subnet->site->name),
                $this->plain(__($subnet->kind->label())),
            ],
            excerpts: [[__('Description'), $subnet->description]],
            status: $subnet->status,
            url: route('subnets.show', $subnet),
        )));
    }

    /**
     * @return array{recognised: bool, total: int, exact: bool, items: list<array<string, mixed>>}|null
     */
    private function addresses(SearchContext $context): ?array
    {
        $input = $context->input;
        $text = $context->text(['ip_addresses.hostname', 'ip_addresses.description']);

        // What each recognised form looks for, and where its matches rank.
        $forms = [];
        $exact = $text['exact'] ?? [];
        $start = $text['start'] ?? [];

        if ($input->address !== null) {
            $binary = AsIpAddress::toBinary($input->address);
            $forms[] = fn (Builder $query) => $query->orWhere('ip_addresses.address', $binary);
            $exact[] = ['ip_addresses.address = ?', [$binary]];
        }

        if ($input->addressRange !== null) {
            $bounds = array_map(AsIpAddress::toBinary(...), $input->addressRange);
            $forms[] = fn (Builder $query) => $query->orWhereBetween('ip_addresses.address', $bounds);
            $start[] = ['ip_addresses.address BETWEEN ? AND ?', $bounds];
        }

        if ($input->mac !== null) {
            $mac = $input->mac;
            $forms[] = fn (Builder $query) => $query->orWhere('ip_addresses.mac_address', $mac);
            $exact[] = ['ip_addresses.mac_address = ?', [$mac]];
        }

        if ($input->macPrefix !== null) {
            $pattern = SearchText::startingWith($input->macPrefix);
            $forms[] = fn (Builder $query) => $query->orWhere('ip_addresses.mac_address', 'like', $pattern);
            $start[] = ['ip_addresses.mac_address LIKE ?', [$pattern]];
        }

        if ($forms === [] && $text === null) {
            return null;
        }

        [$total, $addresses, $hasExact] = $this->fetch(
            IpAddress::query()
                ->where(function (Builder $query) use ($forms, $text): void {
                    foreach ($forms as $form) {
                        $form($query);
                    }

                    if ($text !== null) {
                        $query->orWhere($text['where']);
                    }
                })
                ->with(['subnet', 'device']),
            $this->rank($exact, $start),
            $context,
            order: ['ip_addresses.address'],
            status: 'ip_addresses.status',
        );

        // The MAC address is shown when it was looked for.
        $showsMac = $input->mac !== null || $input->macPrefix !== null;

        return $this->group($forms !== [], $total, $hasExact, $addresses->map(fn (IpAddress $address): array => $this->item(
            $context,
            type: 'address',
            id: $address->id,
            label: $this->whole($address->address, $address->address === $input->address, mono: true),
            context: [
                $address->hostname === null ? null : $context->highlight($address->hostname),
                $showsMac && $address->mac_address !== null ? $this->whole($address->mac_address, $address->mac_address === $input->mac, mono: true) : null,
                $this->plain($address->subnet->cidr(), mono: true),
                $address->device === null ? null : $this->plain($address->device->name),
            ],
            excerpts: [[__('Description'), $address->description]],
            status: $address->status,
            url: route('subnets.show', $address->subnet_id).'#address-'.$address->id,
            feminine: true,
        )));
    }

    /**
     * @return array{recognised: bool, total: int, exact: bool, items: list<array<string, mixed>>}|null
     */
    private function devices(SearchContext $context): ?array
    {
        $text = $context->text(['devices.name', 'devices.manufacturer', 'devices.model', 'devices.serial_number', 'devices.description']);

        if ($text === null) {
            return null;
        }

        $typed = $context->input->text;
        // The serial numbers of the members of a stack count as those of
        // the device.
        $members = 'EXISTS (SELECT 1 FROM device_members WHERE device_members.device_id = devices.id AND device_members.serial_number ';

        [$total, $devices, $exact] = $this->fetch(
            Device::query()
                ->where(fn (Builder $query) => $query
                    ->where($text['where'])
                    ->orWhereHas('members', fn (Builder $query) => $query->where('serial_number', 'like', SearchText::containing($typed))))
                ->with(['site', 'room.floor.building', 'members']),
            $this->rank(
                [...$text['exact'], [$members.'= ?)', [$typed]]],
                [...$text['start'], [$members.'LIKE ?)', [SearchText::startingWith($typed)]]],
            ),
            $context,
            order: ['NATURAL_SORT_KEY(devices.name)', 'devices.site_id', 'devices.id'],
            status: 'devices.status',
        );

        return $this->group(false, $total, $exact, $devices->map(fn (Device $device): array => $this->item(
            $context,
            type: 'device',
            id: $device->id,
            label: $context->highlight($device->name),
            context: [
                $this->plain(__($device->type->label())),
                $this->plain($device->site->name),
                $device->room === null ? null : $this->plain($device->room->path()),
            ],
            excerpts: [
                [__('Manufacturer'), $device->manufacturer],
                [__('Model'), $device->model],
                [__('Serial number'), $device->serial_number],
                ...array_values($device->members->map(fn (DeviceMember $member): array => [__('Serial number'), $member->serial_number])->all()),
                [__('Description'), $device->description],
            ],
            status: $device->status,
            url: route('devices.show', $device),
        )));
    }

    /**
     * @return array{recognised: bool, total: int, exact: bool, items: list<array<string, mixed>>}|null
     */
    private function ports(SearchContext $context): ?array
    {
        $devicePort = $context->input->devicePort;
        $text = $context->text(['ports.description']);

        if ($devicePort === null && $text === null) {
            return null;
        }

        [$total, $ports, $exact] = $this->fetch(
            Port::query()
                ->select('ports.*')
                ->join('devices', 'devices.id', '=', 'ports.device_id')
                ->join('device_members', 'device_members.id', '=', 'ports.device_member_id')
                ->where(function (Builder $query) use ($devicePort, $text): void {
                    if ($devicePort !== null) {
                        $query->orWhere(fn (Builder $query) => $query
                            ->where('ports.name', $devicePort['port'])
                            ->where('devices.name', $devicePort['device']));
                    }

                    if ($text !== null) {
                        $query->orWhere($text['where']);
                    }
                })
                ->with(['device.site', 'aggregate.portVlans.vlan.site', 'portVlans.vlan.site']),
            $this->rank(
                [...($devicePort === null ? [] : [['ports.name = ? AND devices.name = ?', [$devicePort['port'], $devicePort['device']]]]), ...($text['exact'] ?? [])],
                $text['start'] ?? [],
            ),
            $context,
            order: ['NATURAL_SORT_KEY(devices.name)', 'devices.id', 'device_members.position', 'ports.position', 'ports.id'],
        );

        // The port typed after the name of its device.
        $isTyped = fn (Port $port): bool => $devicePort !== null
            && SearchText::equals($port->name, $devicePort['port'])
            && SearchText::equals($port->device->name, $devicePort['device']);

        return $this->group($ports->contains($isTyped), $total, $exact, $ports->map(fn (Port $port): array => $this->item(
            $context,
            type: 'port',
            id: $port->id,
            label: [...$this->whole($port->device->name, $isTyped($port)), ...$this->plain(' '), ...$this->whole($port->name, $isTyped($port), mono: true)],
            context: [
                $this->plain($port->device->site->name),
                ($summary = NetworkPresenter::configurationOf($port)) === null ? null : $this->plain($summary),
            ],
            excerpts: [[__('Description'), $port->description]],
            status: null,
            url: route('devices.show', $port->device_id).'#port-'.$port->id,
        )));
    }

    /**
     * @return array{recognised: bool, total: int, exact: bool, items: list<array<string, mixed>>}|null
     */
    private function jacks(SearchContext $context): ?array
    {
        $text = $context->text(['jacks.label', 'jacks.location', 'jacks.description']);

        if ($text === null) {
            return null;
        }

        [$total, $jacks, $exact] = $this->fetch(
            Jack::query()->where($text['where'])->with('floor.building.site'),
            $this->rank($text['exact'], $text['start']),
            $context,
            order: ['NATURAL_SORT_KEY(jacks.label)', 'jacks.floor_id', 'jacks.id'],
            status: 'jacks.status',
        );

        return $this->group(false, $total, $exact, $jacks->map(fn (Jack $jack): array => $this->item(
            $context,
            type: 'jack',
            id: $jack->id,
            label: $context->highlight($jack->label, mono: true),
            context: [
                $this->plain($jack->floor->building->site->name),
                $this->plain($jack->floor->path()),
                $jack->location === null ? null : $context->highlight($jack->location),
            ],
            excerpts: [[__('Description'), $jack->description]],
            status: $jack->status,
            // A jack has no page of its own: the list of jacks, on its
            // floor, at its line.
            url: route('jacks.index', ['floor' => $jack->floor_id]).'#jack-'.$jack->id,
            feminine: true,
        )));
    }

    /**
     * @return array{recognised: bool, total: int, exact: bool, items: list<array<string, mixed>>}|null
     */
    private function panels(SearchContext $context): ?array
    {
        $text = $context->text(['patch_panels.name', 'patch_panels.description']);

        if ($text === null) {
            return null;
        }

        [$total, $panels, $exact] = $this->fetch(
            PatchPanel::query()->where($text['where'])->with('room.floor.building.site'),
            $this->rank($text['exact'], $text['start']),
            $context,
            order: ['NATURAL_SORT_KEY(patch_panels.name)', 'patch_panels.id'],
            status: 'patch_panels.status',
        );

        return $this->group(false, $total, $exact, $panels->map(fn (PatchPanel $panel): array => $this->item(
            $context,
            type: 'panel',
            id: $panel->id,
            label: $context->highlight($panel->name),
            context: [$this->plain($panel->room->floor->building->site->name), $this->plain($panel->room->path())],
            excerpts: [[__('Description'), $panel->description]],
            status: $panel->status,
            url: route('patch-panels.show', $panel),
        )));
    }

    /**
     * @return array{recognised: bool, total: int, exact: bool, items: list<array<string, mixed>>}|null
     */
    private function templates(SearchContext $context): ?array
    {
        $text = $context->text(['device_templates.name', 'device_templates.manufacturer', 'device_templates.model']);

        if ($text === null) {
            return null;
        }

        [$total, $templates, $exact] = $this->fetch(
            DeviceTemplate::query()->where($text['where']),
            $this->rank($text['exact'], $text['start']),
            $context,
            order: ['NATURAL_SORT_KEY(device_templates.name)', 'device_templates.id'],
        );

        return $this->group(false, $total, $exact, $templates->map(fn (DeviceTemplate $template): array => $this->item(
            $context,
            type: 'template',
            id: $template->id,
            label: $context->highlight($template->name),
            context: [
                $this->plain(__($template->device_type->label())),
                $template->make() === null ? null : $context->highlight((string) $template->make()),
            ],
            excerpts: [],
            status: null,
            // A template is read in the list of templates, at its line: its
            // own page is the form that changes it.
            url: route('device-templates.index').'#template-'.$template->id,
        )));
    }

    // ----- Addresses and prefixes -----------------------------------------

    /**
     * What a typed address or prefix looks for among the subnets or the
     * blocks: those that hold the address; the one equal to the prefix,
     * or else those around it, fifty at most for blocks and subnets
     * together, the blocks first.
     *
     * @param  literal-string  $table
     * @return array{where: Closure(Builder<covariant Model>): void, exact: list<array{0: literal-string, 1: list<int|string>}>, cap: int|null}|null
     */
    private function prefixConditions(SearchContext $context, string $table): ?array
    {
        $input = $context->input;

        if ($input->address !== null) {
            return ['where' => $this->holding($table, $input->address, Ipv4::MAX_PREFIX), 'exact' => [], 'cap' => null];
        }

        if ($input->prefix === null) {
            return null;
        }

        ['network' => $network, 'prefix' => $length] = $input->prefix;
        $binary = AsIpAddress::toBinary($network);

        if ($context->prefixIsDeclared()) {
            return [
                'where' => function (Builder $query) use ($table, $binary, $length): void {
                    $query->where("{$table}.network", $binary)->where("{$table}.prefix_length", $length);
                },
                'exact' => [["{$table}.network = ? AND {$table}.prefix_length = ?", [$binary, $length]]],
                'cap' => null,
            ];
        }

        $cap = self::AROUND_PREFIX;

        // The blocks come first: the subnets have what they leave.
        if ($table === 'subnets') {
            $cap -= min(self::AROUND_PREFIX, AddressBlock::query()->where($this->aroundOf('address_blocks', $network, $length))->count());
        }

        return ['where' => $this->aroundOf($table, $network, $length), 'exact' => [], 'cap' => $cap];
    }

    /**
     * The blocks or subnets around a prefix: those that hold it, and those
     * it holds.
     *
     * @param  literal-string  $table
     * @return Closure(Builder<covariant Model>): void
     */
    private function aroundOf(string $table, string $network, int $length): Closure
    {
        $holding = $this->holding($table, $network, $length - 1);
        $inside = [AsIpAddress::toBinary($network), AsIpAddress::toBinary(Ipv4::broadcast($network, $length))];

        return function (Builder $query) use ($table, $holding, $inside, $length): void {
            $query->where(fn (Builder $query) => $query
                ->where($holding)
                ->orWhere(fn (Builder $query) => $query
                    ->whereBetween("{$table}.network", $inside)
                    ->where("{$table}.prefix_length", '>', $length)));
        };
    }

    /**
     * The prefixes that hold an address, up to a length: those whose
     * network address is the address cut to their length. The stored
     * addresses are binary: they are compared as they are, one pair of
     * values per length, with no arithmetic on them.
     *
     * @param  literal-string  $table
     * @return Closure(Builder<covariant Model>): void
     */
    private function holding(string $table, string $address, int $longest): Closure
    {
        return function (Builder $query) use ($table, $address, $longest): void {
            $query->where(function (Builder $query) use ($table, $address, $longest): void {
                for ($length = 1; $length <= $longest; $length++) {
                    $network = AsIpAddress::toBinary(Ipv4::network($address, $length));

                    $query->orWhere(fn (Builder $query) => $query
                        ->where("{$table}.network", $network)
                        ->where("{$table}.prefix_length", $length));
                }

                // Up to no length at all, nothing holds the address.
                if ($longest < 1) {
                    $query->whereNull("{$table}.id");
                }
            });
        };
    }

    // ----- Ranking and reading ---------------------------------------------

    /**
     * Where a match ranks, as an SQL expression: 0 for an exact match, 1 at
     * the start of a value, 2 anywhere. The conditions are written in this
     * class, with placeholders: what was typed is only ever bound.
     *
     * @param  list<array{0: literal-string, 1: list<int|string>}>  $exact
     * @param  list<array{0: literal-string, 1: list<int|string>}>  $start
     * @return array{0: literal-string, 1: list<int|string>}
     */
    private function rank(array $exact, array $start): array
    {
        $sql = '';
        $bindings = [];

        foreach ([self::EXACT => $exact, self::START => $start] as $rank => $conditions) {
            if ($conditions === []) {
                continue;
            }

            $sql .= ' WHEN '.implode(' OR ', array_map(fn (array $condition): string => '('.$condition[0].')', $conditions)).' THEN '.$rank;

            foreach ($conditions as [, $values]) {
                array_push($bindings, ...$values);
            }
        }

        return $sql === '' ? [(string) self::ANYWHERE, []] : ['CASE'.$sql.' ELSE '.self::ANYWHERE.' END', $bindings];
    }

    /**
     * Count what the query finds, then read one page of it: ranked, the
     * objects that are not retired before the others, then in the given
     * order.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array{0: literal-string, 1: list<int|string>}  $rank
     * @param  list<literal-string>  $order  Columns, or expressions of this class.
     * @param  literal-string|null  $status  The column of the status, when the objects have one.
     * @param  int|null  $cap  At most, whatever the query finds.
     * @return array{0: int, 1: Collection<int, TModel>, 2: bool}
     */
    private function fetch(Builder $query, array $rank, SearchContext $context, array $order, ?string $status = null, ?int $cap = null): array
    {
        $total = (clone $query)->count();

        if ($cap !== null) {
            $total = min($total, max(0, $cap));
        }

        $limit = min($context->limit, $total - $context->offset);

        if ($limit <= 0) {
            return [$total, $query->getModel()->newCollection(), false];
        }

        if ($query->getQuery()->columns === null) {
            $query->select($query->getModel()->getTable().'.*');
        }

        $query->selectRaw($rank[0].' AS search_rank', $rank[1])->orderBy('search_rank');

        if ($status !== null) {
            $query->orderByRaw($status.' = ?', [Status::Retired->value]);
        }

        foreach ($order as $expression) {
            $query->orderByRaw($expression);
        }

        $models = $query->offset($context->offset)->limit($limit)->get();

        // Whether the group holds an exact match is read on its first page.
        $first = $models->first();
        $exact = $context->offset === 0 && $first !== null && (int) $first->getAttribute('search_rank') === self::EXACT;

        return [$total, $models, $exact];
    }

    /**
     * @param  BaseCollection<int, array<string, mixed>>  $items
     * @return array{recognised: bool, total: int, exact: bool, items: list<array<string, mixed>>}
     */
    private function group(bool $recognised, int $total, bool $exact, BaseCollection $items): array
    {
        return ['recognised' => $recognised, 'total' => $total, 'exact' => $exact, 'items' => array_values($items->all())];
    }

    // ----- What a result shows ---------------------------------------------

    /**
     * A result: what it is, its label and its context in parts that say
     * which ones match, where it leads. When the text matched none of what
     * is shown, the first other value that holds it is shown too, around
     * the match, with the name of its field.
     *
     * @param  list<array{text: string, match: bool, mono: bool}>  $label
     * @param  list<list<array{text: string, match: bool, mono: bool}>|null>  $context
     * @param  list<array{0: string, 1: string|null}>  $excerpts  Name of the field, value.
     * @return array<string, mixed>
     */
    private function item(
        SearchContext $search,
        string $type,
        int $id,
        array $label,
        array $context,
        array $excerpts,
        ?Status $status,
        string $url,
        bool $feminine = false,
    ): array {
        $parts = array_values(array_filter($context, fn (?array $part): bool => $part !== null && $part !== []));

        $matches = array_any(
            [$label, ...$parts],
            fn (array $segments): bool => array_any($segments, fn (array $segment): bool => $segment['match']),
        );

        $excerpt = null;

        if (! $matches && $search->searchesText()) {
            foreach ($excerpts as [$field, $value]) {
                if ($value !== null && SearchText::contains($value, $search->input->text)) {
                    $excerpt = ['field' => $field, 'segments' => $search->excerpt($value)];

                    break;
                }
            }
        }

        // Only a planned or a retired object says its status.
        $shown = $status !== null && $status !== Status::Active;

        return [
            'key' => $type.'-'.$id,
            'type' => $type,
            'label' => $label,
            'context' => $parts,
            'excerpt' => $excerpt,
            'status' => $shown ? $status->value : null,
            'status_label' => $shown ? __($status->label($feminine)) : null,
            'url' => $url,
        ];
    }

    /**
     * A value shown as it is, matching nothing.
     *
     * @return list<array{text: string, match: bool, mono: bool}>
     */
    private function plain(string $value, bool $mono = false): array
    {
        return [['text' => $value, 'match' => false, 'mono' => $mono]];
    }

    /**
     * A value of a recognised form: it matches as a whole when it is equal
     * to what was typed, not at all otherwise.
     *
     * @return list<array{text: string, match: bool, mono: bool}>
     */
    private function whole(string $value, bool $equal, bool $mono = false): array
    {
        return [['text' => $value, 'match' => $equal, 'mono' => $mono]];
    }
}
