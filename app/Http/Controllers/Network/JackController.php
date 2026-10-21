<?php

// torsade/app/Http/Controllers/Network/JackController.php

namespace App\Http\Controllers\Network;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Presenters\NetworkPresenter;
use App\Http\Requests\Network\JackAttachRequest;
use App\Http\Requests\Network\JackIndexRequest;
use App\Http\Requests\Network\JackRequest;
use App\Http\Requests\Network\JackSeriesRequest;
use App\Models\Building;
use App\Models\Device;
use App\Models\Floor;
use App\Models\Jack;
use App\Models\PatchPanel;
use App\Models\PatchPanelPort;
use App\Models\Port;
use App\Models\Site;
use App\Services\Network\CabledPaths;
use App\Services\Network\CablingDeviations;
use App\Services\Network\Jacks;
use App\Support\Actor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The wall jacks, created on a floor from the page of their site, and
 * cabled to a port of a panel there or from the page of the panel; listed
 * on a page of their own with the path of each one. Each route of this
 * controller is behind the jack policy (see routes/network.php).
 */
class JackController extends Controller
{
    /**
     * Every jack, with where it is, its path towards a device and the
     * addresses plugged into it: by site, building, floor and label, a
     * number read as a number.
     *
     * The number of queries does not depend on the number of jacks.
     */
    public function index(JackIndexRequest $request, CablingDeviations $cabling, CabledPaths $paths): Response
    {
        $site = $request->site();
        $building = $request->building();
        $floor = $request->floor();
        $status = $request->status();
        $search = $request->search();
        $differing = $request->onlyDeviations();

        $jacks = Jack::query()
            ->with([
                ...NetworkPresenter::JACK_PATH,
                ...CablingDeviations::JACK,
                'floor.building.site',
                'ipAddresses' => fn ($query) => $query->orderBy('address'),
            ])
            ->when($site !== null, fn (Builder $query) => $query->whereHas('floor.building', fn (Builder $query) => $query->where('site_id', $site)))
            ->when($building !== null, fn (Builder $query) => $query->whereHas('floor', fn (Builder $query) => $query->where('building_id', $building)))
            ->when($floor !== null, fn (Builder $query) => $query->where('floor_id', $floor))
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($search !== '', function (Builder $query) use ($search): void {
                // What was typed is looked for as it is: "%" and "_" are
                // characters like the others.
                $like = '%'.addcslashes($search, '\\%_').'%';

                $query->where(fn (Builder $query) => $query
                    ->where('label', 'like', $like)
                    ->orWhere('location', 'like', $like));
            })
            ->get()
            ->sortBy([
                fn (Jack $a, Jack $b): int => strnatcasecmp($a->floor->building->site->name, $b->floor->building->site->name),
                fn (Jack $a, Jack $b): int => strnatcasecmp($a->floor->building->name, $b->floor->building->name),
                fn (Jack $a, Jack $b): int => $a->floor->level <=> $b->floor->level,
                fn (Jack $a, Jack $b): int => strnatcasecmp($a->label, $b->label),
                fn (Jack $a, Jack $b): int => $a->id <=> $b->id,
            ]);

        // What the cabled paths tell about each jack, read on the site
        // filtered, or on every site.
        $map = $paths->of($site === null ? null : [$site]);

        $lines = array_values(array_filter(
            $jacks->map(fn (Jack $jack): array => NetworkPresenter::jackLine($jack, [...$cabling->ofJack($jack), ...$map->ofJack($jack)]))->all(),
            fn (array $line): bool => ! $differing || $line['deviations'] !== [],
        ));

        return Inertia::render('jacks/Index', [
            'jacks' => $lines,
            'total' => Jack::query()->count(),
            'filters' => [
                'site' => $site === null ? '' : (string) $site,
                'building' => $building === null ? '' : (string) $building,
                'floor' => $floor === null ? '' : (string) $floor,
                'status' => $status ?? '',
                'deviations' => $differing ? '1' : '',
                'search' => $search,
            ],
            'places' => self::places(),
            // The statuses of a jack, agreed with it.
            'statuses' => Status::options(feminine: true),
            // The panels of the site of a jack with their free ports, looked
            // for when its window asks.
            'panelChoices' => Inertia::optional(fn (): array => $request->panelsSite() === null ? [] : self::panelChoices($request->panelsSite())),
            // What the window "Plug a device" chooses among: the devices of
            // the site of the jack, then the free ports of the one chosen.
            'plugDevices' => Inertia::optional(fn (): array => $request->plugSite() === null ? [] : self::plugDevices($request->plugSite())),
            'plugPorts' => Inertia::optional(fn (): ?array => $request->plugSite() === null || $request->plugDevice() === null ? null : self::plugPorts($request->plugDevice(), $request->plugSite())),
        ]);
    }

    public function store(JackRequest $request, Floor $floor, Jacks $jacks): RedirectResponse
    {
        $jacks->create($floor, $request->jackData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Jack created.')]);

        return to_route('sites.show', $floor->building->site_id);
    }

    /**
     * Jacks labelled as a series on a floor, and cabled in order to the
     * ports of a panel when one is given: all of them, or none.
     */
    public function storeSeries(JackSeriesRequest $request, Floor $floor, Jacks $jacks): RedirectResponse
    {
        $created = $jacks->createSeries($floor, $request->seriesData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count jack created.|:count jacks created.', $created)]);

        return to_route('sites.show', $floor->building->site_id);
    }

    /**
     * A jack is changed from the list of jacks: the answer goes back to
     * where it was asked, filters included.
     */
    public function update(JackRequest $request, Jack $jack, Jacks $jacks): RedirectResponse
    {
        $jacks->update($jack, $request->jackData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Jack updated.')]);

        return back();
    }

    public function destroy(Request $request, Jack $jack, Jacks $jacks): RedirectResponse
    {
        $jacks->delete($jack, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Jack deleted.')]);

        return back();
    }

    /**
     * A jack cabled to a port of a panel, from the page of the panel.
     */
    public function attach(JackAttachRequest $request, PatchPanelPort $patchPanelPort, Jacks $jacks): RedirectResponse
    {
        $jacks->attach($patchPanelPort, $request->jackId(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Jack cabled to the port.')]);

        return to_route('patch-panels.show', $patchPanelPort->patch_panel_id);
    }

    /**
     * The jack of a port of a panel detached from it: the jack stays on its
     * floor, the port is free.
     */
    public function detach(Request $request, PatchPanelPort $patchPanelPort, Jacks $jacks): RedirectResponse
    {
        $jacks->detach($patchPanelPort, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Jack detached from the port.')]);

        return to_route('patch-panels.show', $patchPanelPort->patch_panel_id);
    }

    /**
     * How many jacks the field "Jack" of the window of an address lists at
     * most: a search that finds more is to be made more precise.
     */
    public const int PLUG_LISTED = 20;

    /**
     * The jacks an address can be plugged into, whose label holds what was
     * typed: those of a site, or of every site for an address whose subnet
     * has none. By site, building, floor and label, a number read as a
     * number, twenty at most.
     *
     * @return array{items: list<array{id: int, label: string, place: string, site: string}>, more: bool}
     */
    public static function plugChoices(?int $siteId, string $search): array
    {
        $jacks = Jack::query()
            ->with('floor.building.site')
            ->when($siteId !== null, fn (Builder $query) => $query->whereHas('floor.building', fn (Builder $query) => $query->where('site_id', $siteId)))
            ->when($search !== '', fn (Builder $query) => $query->where('label', 'like', '%'.addcslashes($search, '\\%_').'%'))
            ->get()
            ->sortBy([
                fn (Jack $a, Jack $b): int => strnatcasecmp($a->floor->building->site->name, $b->floor->building->site->name),
                fn (Jack $a, Jack $b): int => strnatcasecmp($a->floor->building->name, $b->floor->building->name),
                fn (Jack $a, Jack $b): int => $a->floor->level <=> $b->floor->level,
                fn (Jack $a, Jack $b): int => strnatcasecmp($a->label, $b->label),
            ])
            ->values();

        return [
            'items' => array_values($jacks->take(self::PLUG_LISTED)->map(fn (Jack $jack): array => [
                'id' => $jack->id,
                'label' => $jack->label,
                'place' => $jack->floor->path(),
                'site' => $jack->floor->building->site->name,
            ])->all()),
            'more' => $jacks->count() > self::PLUG_LISTED,
        ];
    }

    /**
     * The devices of a site that can be plugged into one of its jacks: those
     * that are not retired, by name, each with where it is kept and how many
     * of its ports have no connector yet.
     *
     * @return list<array{id: int, name: string, place: string|null, free_ports: int}>
     */
    public static function plugDevices(int $siteId): array
    {
        return array_values(
            Device::query()
                ->where('site_id', $siteId)
                ->where('status', '!=', Status::Retired->value)
                ->with('room.floor.building')
                ->withCount(['ports as free_ports_count' => fn (Builder $query) => $query->doesntHave('patchCord')->doesntHave('jackCord')])
                ->get()
                ->sortBy(fn (Device $device): string => $device->name, SORT_NATURAL | SORT_FLAG_CASE)
                ->map(fn (Device $device): array => [
                    'id' => $device->id,
                    'name' => $device->name,
                    'place' => $device->room?->path(),
                    'free_ports' => (int) $device->getAttribute('free_ports_count'),
                ])
                ->all(),
        );
    }

    /**
     * The ports of a device of a site that have no connector, neither a
     * patch cord nor a jack cord, in the order of the device, member then
     * place, each with what it carries. Null for a device of another site
     * or a retired one: it is plugged into none of the jacks of the site.
     *
     * @return array{device: array{id: int, name: string}, ports: list<array{id: int, name: string, member: int, configuration: string|null}>}|null
     */
    public static function plugPorts(int $deviceId, int $siteId): ?array
    {
        $device = Device::query()
            ->whereKey($deviceId)
            ->where('site_id', $siteId)
            ->where('status', '!=', Status::Retired->value)
            ->first();

        if ($device === null) {
            return null;
        }

        return [
            'device' => ['id' => $device->id, 'name' => $device->name],
            'ports' => array_values(
                Port::query()
                    ->where('device_id', $device->id)
                    ->doesntHave('patchCord')
                    ->doesntHave('jackCord')
                    ->with(['member', 'aggregate.portVlans.vlan.site', 'portVlans.vlan.site'])
                    ->get()
                    // What each port carries is told from the site of its
                    // device.
                    ->each(fn (Port $port) => $port->setRelation('device', $device))
                    ->sortBy([
                        fn (Port $a, Port $b): int => $a->member->position <=> $b->member->position,
                        fn (Port $a, Port $b): int => $a->position <=> $b->position,
                        fn (Port $a, Port $b): int => $a->id <=> $b->id,
                    ])
                    ->map(fn (Port $port): array => [
                        'id' => $port->id,
                        'name' => $port->name,
                        'member' => $port->member->position,
                        'configuration' => NetworkPresenter::configurationOf($port),
                    ])
                    ->all(),
            ),
        ];
    }

    /**
     * The jacks of a site a port of a device can be plugged into: those
     * that are not retired and have no jack cord, by building (by name),
     * floor (lowest first) and label (a number read as a number), each
     * with its path towards the panel and the switch.
     *
     * @return list<array{floor: int, place: string, jacks: list<array{id: int, label: string, location: string|null, path: array<string, mixed>}>}>
     */
    public static function pluggableJacks(int $siteId): array
    {
        $jacks = Jack::query()
            ->where('status', '!=', Status::Retired->value)
            ->doesntHave('cord')
            ->whereHas('floor.building', fn (Builder $query) => $query->where('site_id', $siteId))
            ->with(NetworkPresenter::JACK_PATH)
            ->get()
            ->sort(fn (Jack $a, Jack $b): int => strnatcasecmp($a->floor->building->name, $b->floor->building->name)
                ?: $a->floor->building_id <=> $b->floor->building_id
                ?: $a->floor->level <=> $b->floor->level
                ?: strnatcasecmp($a->label, $b->label)
                ?: $a->id <=> $b->id);

        $groups = [];

        foreach ($jacks as $jack) {
            $groups[$jack->floor_id] ??= ['floor' => $jack->floor_id, 'place' => $jack->floor->path(), 'jacks' => []];
            $groups[$jack->floor_id]['jacks'][] = [
                'id' => $jack->id,
                'label' => $jack->label,
                'location' => $jack->location,
                'path' => NetworkPresenter::jackPath($jack),
            ];
        }

        return array_values($groups);
    }

    /**
     * The sites, their buildings and the floors of each, as the filters of
     * the list choose among them.
     *
     * @return list<array{id: int, name: string, buildings: list<array{id: int, name: string, floors: list<array{id: int, name: string}>}>}>
     */
    private static function places(): array
    {
        return array_values(
            Site::query()
                ->with(['buildings.floors'])
                ->get()
                ->sortBy(fn (Site $site): string => $site->name, SORT_NATURAL | SORT_FLAG_CASE)
                ->map(fn (Site $site): array => [
                    'id' => $site->id,
                    'name' => $site->name,
                    'buildings' => array_values(
                        $site->buildings
                            ->sortBy(fn (Building $building): string => $building->name, SORT_NATURAL | SORT_FLAG_CASE)
                            ->map(fn (Building $building): array => [
                                'id' => $building->id,
                                'name' => $building->name,
                                'floors' => array_values(
                                    $building->floors
                                        ->sortBy('level')
                                        ->map(fn (Floor $floor): array => ['id' => $floor->id, 'name' => $floor->name])
                                        ->all(),
                                ),
                            ])
                            ->all(),
                    ),
                ])
                ->all(),
        );
    }

    /**
     * The patch panels of a site, by name, each with where it is and its
     * ports that have no jack: what a jack of the site can be cabled to.
     *
     * @return list<array{id: int, name: string, place: string, port_count: int, free: list<array{id: int, number: int}>}>
     */
    public static function panelChoices(int $siteId): array
    {
        return array_values(
            PatchPanel::query()
                ->whereHas('room.floor.building', fn (Builder $query) => $query->where('site_id', $siteId))
                ->with(['room.floor.building', 'ports' => fn ($query) => $query->doesntHave('jack')])
                ->get()
                ->sortBy(fn (PatchPanel $panel): string => $panel->name, SORT_NATURAL | SORT_FLAG_CASE)
                ->map(fn (PatchPanel $panel): array => [
                    'id' => $panel->id,
                    'name' => $panel->name,
                    'place' => $panel->room->path(),
                    'port_count' => $panel->port_count,
                    'free' => array_values($panel->ports->map(fn (PatchPanelPort $port): array => ['id' => $port->id, 'number' => $port->number])->all()),
                ])
                ->all(),
        );
    }
}
