<?php

// torsade/app/Http/Controllers/Network/PatchPanelController.php

namespace App\Http\Controllers\Network;

use App\Enums\PanelConnector;
use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Presenters\NetworkPresenter;
use App\Http\Requests\Network\PatchPanelRequest;
use App\Http\Requests\Network\PatchPanelShowRequest;
use App\Models\Device;
use App\Models\Jack;
use App\Models\PatchPanel;
use App\Models\PatchPanelPort;
use App\Models\Port;
use App\Models\Room;
use App\Services\Network\CabledPaths;
use App\Services\Network\CablingDeviations;
use App\Services\Network\Links;
use App\Services\Network\PatchPanels;
use App\Support\Actor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The patch panels, created from the room they are kept in, on the page of
 * their site, and read on a page of their own. Each route of this
 * controller is behind the patch panel policy (see routes/network.php).
 */
class PatchPanelController extends Controller
{
    /**
     * A panel: where it is, its ports drawn as on the panel itself, and for
     * each port its jack and its cord.
     *
     * The number of queries does not depend on the number of ports.
     */
    public function show(PatchPanelShowRequest $request, PatchPanel $patchPanel, CablingDeviations $cabling, CabledPaths $paths): Response
    {
        $patchPanel->load('room.floor.building.site')->loadCount(['ports as occupied_count' => fn ($query) => $query->where(fn ($query) => $query->has('jack')->orHas('cord'))]);

        $ports = $patchPanel->ports()
            ->with(['jack.floor.building', ...CablingDeviations::PANEL_PORT])
            ->get();

        foreach ($ports as $port) {
            $port->setRelation('panel', $patchPanel);
        }

        // What the cabled paths tell about the jacks of its ports.
        $map = $paths->of([$patchPanel->siteId()]);

        return Inertia::render('patch-panels/Show', [
            'panel' => NetworkPresenter::patchPanel($patchPanel),
            // Each port with how its cabling differs from what is
            // declared around it.
            'ports' => $ports->map(fn (PatchPanelPort $port): array => NetworkPresenter::panelPort($port, [
                ...$cabling->ofPanelPort($port),
                ...($port->jack === null ? [] : $map->ofJack($port->jack)),
            ])),
            // What the window of a panel chooses among.
            'connectors' => PanelConnector::options(),
            'statuses' => Status::options(),
            // The jacks of the site of the panel that are not cabled yet,
            // on any of its floors: what one of its ports can be cabled to,
            // by building and floor.
            'freeJacks' => self::freeJacks($patchPanel),
            // The devices of the site, by name: what a port is patched to.
            'patchDevices' => array_values(
                Device::query()
                    ->where('site_id', $patchPanel->siteId())
                    ->with('room.floor.building')
                    ->get()
                    ->sortBy(fn (Device $device): string => $device->name, SORT_NATURAL | SORT_FLAG_CASE)
                    ->map(fn (Device $device): array => ['id' => $device->id, 'name' => $device->name, 'place' => $device->room?->path()])
                    ->all(),
            ),
            // The ports of the device chosen in the window "Patch", asked
            // for when it chooses one.
            'devicePorts' => Inertia::optional(fn (): array => $request->device() === null ? [] : self::devicePorts($request->device(), $patchPanel->siteId())),
        ]);
    }

    /**
     * The jacks of the site of the panel without a horizontal cable, by
     * building (by name), floor (lowest first) and label (a number read as
     * a number).
     *
     * @return list<array{floor: int, place: string, jacks: list<array{id: int, label: string, location: string|null}>}>
     */
    private static function freeJacks(PatchPanel $panel): array
    {
        $jacks = Jack::query()
            ->whereNull('patch_panel_port_id')
            ->whereHas('floor.building', fn (Builder $query) => $query->where('site_id', $panel->siteId()))
            ->with('floor.building')
            ->get()
            ->sort(fn (Jack $a, Jack $b): int => strnatcasecmp($a->floor->building->name, $b->floor->building->name)
                ?: $a->floor->building_id <=> $b->floor->building_id
                ?: $a->floor->level <=> $b->floor->level
                ?: strnatcasecmp($a->label, $b->label)
                ?: $a->id <=> $b->id);

        $groups = [];

        foreach ($jacks as $jack) {
            $groups[$jack->floor_id] ??= ['floor' => $jack->floor_id, 'place' => $jack->floor->path(), 'jacks' => []];
            $groups[$jack->floor_id]['jacks'][] = ['id' => $jack->id, 'label' => $jack->label, 'location' => $jack->location];
        }

        return array_values($groups);
    }

    /**
     * The ports of a device of the site of a panel, in the order of the
     * device, member then place: each with what it is already patched to,
     * what it is linked to, the jack it is plugged into, and what it
     * carries. Nothing for a device of another site.
     *
     * @return list<array{id: int, name: string, member: int, cord: string|null, link: string|null, plugged: string|null, configuration: string|null}>
     */
    private static function devicePorts(int $deviceId, int $siteId): array
    {
        $device = Device::query()->whereKey($deviceId)->where('site_id', $siteId)->first();

        if ($device === null) {
            return [];
        }

        return array_values(
            Port::query()
                ->where('device_id', $device->id)
                ->with([
                    'member',
                    'patchCord.panelPort.panel',
                    'patchCord.panelPort.jack',
                    'jackCord.jack',
                    'linkEnd.link.ends.port.device',
                    'aggregate.portVlans.vlan.site',
                    'portVlans.vlan.site',
                ])
                ->get()
                // What each port carries is told from the site of its device.
                ->each(fn (Port $port) => $port->setRelation('device', $device))
                ->sortBy([
                    fn (Port $a, Port $b): int => $a->member->position <=> $b->member->position,
                    fn (Port $a, Port $b): int => $a->position <=> $b->position,
                    fn (Port $a, Port $b): int => $a->id <=> $b->id,
                ])
                ->map(function (Port $port): array {
                    $panelPort = $port->patchCord?->panelPort;
                    $opposite = $port->linkedPort();

                    return [
                        'id' => $port->id,
                        'name' => $port->name,
                        'member' => $port->member->position,
                        'cord' => $panelPort === null ? null : ($panelPort->jack === null ? $panelPort->name() : $panelPort->name().' ('.$panelPort->jack->label.')'),
                        'link' => $opposite === null ? null : Links::named($opposite),
                        'plugged' => $port->jackCord?->jack->label,
                        'configuration' => NetworkPresenter::configurationOf($port),
                    ];
                })
                ->all(),
        );
    }

    public function store(PatchPanelRequest $request, Room $room, PatchPanels $panels): RedirectResponse
    {
        $panels->create($room, $request->panelData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Patch panel created.')]);

        return to_route('sites.show', $room->siteId());
    }

    /**
     * A panel is changed from the page of its site or from its own page:
     * the answer goes back to where it was asked.
     */
    public function update(PatchPanelRequest $request, PatchPanel $patchPanel, PatchPanels $panels): RedirectResponse
    {
        $panels->update($patchPanel, $request->panelData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Patch panel updated.')]);

        return back();
    }

    public function destroy(Request $request, PatchPanel $patchPanel, PatchPanels $panels): RedirectResponse
    {
        $site = $patchPanel->room->siteId();

        $panels->delete($patchPanel, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Patch panel deleted.')]);

        return to_route('sites.show', $site);
    }
}
