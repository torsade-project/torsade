<?php

// torsade/app/Services/Network/PatchCords.php

namespace App\Services\Network;

use App\Enums\ActivityEvent;
use App\Models\Device;
use App\Models\JackCord;
use App\Models\PatchCord;
use App\Models\PatchPanel;
use App\Models\PatchPanelPort;
use App\Models\Port;
use App\Services\Network\Concerns\PhrasesMessages;
use App\Services\Network\Concerns\RetriesCrossedWrites;
use App\Services\Network\Concerns\TranslatesUniqueViolations;
use App\Support\Actor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Every change to the patch cords goes through this class, whether it comes
 * from the interface or from a command: the rules are enforced here and each
 * cord is written to the activity log.
 *
 * A cord joins a port of a patch panel to a port of a device of the same
 * site; each of the two ports has one cord at most. Consecutive ports of a
 * panel are patched in one go to as many ports of a device, in the order of
 * the device, member then place, from a first port: all of them, or none.
 * Since patching locks the rows of both ports, a write the database gives
 * up for a crossing one is replayed.
 *
 * A cord does not join two sites: a device that is patched does not move to
 * another site until its ports are unpatched, which can be done at once.
 * A port has one connector: a port plugged into a wall jack by a jack cord
 * is not patched, which is checked again when the cord is written.
 *
 * @phpstan-type CordData array{color?: string|null, description?: string|null}
 */
final class PatchCords
{
    use PhrasesMessages;
    use RetriesCrossedWrites;
    use TranslatesUniqueViolations;

    public function __construct(
        private readonly ChangeLog $log,
    ) {}

    /**
     * Patch ports of a panel, consecutive, to as many ports of a device
     * taken in its order from a first one. Each cord is one entry of the
     * activity log.
     *
     * @param  list<int>  $panelPortIds
     * @param  CordData  $data
     * @return int How many cords were created.
     *
     * @throws ValidationException Messages are keyed by field.
     */
    public function create(PatchPanel $panel, array $panelPortIds, int $deviceId, int $firstPortId, array $data, Actor $actor): int
    {
        return $this->replayingCrossedWrites(function () use ($panel, $panelPortIds, $deviceId, $firstPortId, $data, $actor): int {
            $panel->loadMissing('room.floor.building.site');

            $panelPorts = $this->panelPorts($panel, $panelPortIds);

            // Locked, as it is stored now: a move to another site that
            // commits meanwhile is seen.
            $device = Device::query()->with('site')->whereKey($deviceId)->lockForUpdate()->first();

            if ($device === null) {
                throw ValidationException::withMessages(['device_id' => __('This device no longer exists.')]);
            }

            // A cord is laid within a building, never between two sites.
            if ($device->site_id !== $panel->siteId()) {
                throw ValidationException::withMessages([
                    'device_id' => __('The device :device is on the site :site, the panel on the site :panel: a cord does not join two sites.', [
                        'device' => $device->name,
                        'site' => $device->site->name,
                        'panel' => $panel->room->floor->building->site->name,
                    ]),
                ]);
            }

            $ports = $this->devicePorts($device, $firstPortId, $panelPorts->count());

            $this->refusePatched($panel, $panelPorts, $device, $ports);

            foreach ($panelPorts->values() as $index => $panelPort) {
                $port = $ports[$index];

                $cord = new PatchCord([
                    'color' => self::blankToNull($data['color'] ?? null),
                    'description' => self::blankToNull($data['description'] ?? null),
                ]);
                $cord->panelPort()->associate($panelPort);
                $cord->port()->associate($port);

                $this->guardingUniqueness(fn (): bool => $cord->save(), [
                    'patch_cords_patch_panel_port_id_unique' => ['panel_port_ids', __('A port of the panel was patched in the meantime: reload the page.')],
                    'patch_cords_port_id_unique' => ['port_id', __('A port of :device was patched in the meantime: choose the ports again.', ['device' => $device->name])],
                ]);

                // One entry per cord, as one per link of a series.
                $this->log->created(ActivityEvent::PatchCordCreated, $cord, $actor);
            }

            return $panelPorts->count();
        }, 'panel_port_ids');
    }

    /**
     * Change the colour and the description of a cord. Its two ends do not
     * change: to move a cord, the port is unpatched, then patched again.
     *
     * The cord is given by its identifier: one unpatched in the meantime is
     * told in words, as a refusal on the field "cord".
     *
     * @param  CordData  $data
     *
     * @throws ValidationException When the cord was unpatched in the meantime.
     */
    public function update(int $cordId, array $data, Actor $actor): void
    {
        $this->replayingCrossedWrites(function () use ($cordId, $data, $actor): void {
            $cord = PatchCord::query()
                ->with(['panelPort.panel.room.floor.building', 'port.device.site'])
                ->whereKey($cordId)
                ->lockForUpdate()
                ->first();

            if ($cord === null) {
                throw ValidationException::withMessages(['cord' => self::gone()]);
            }

            $before = $cord->auditValues();

            $cord->fill([
                'color' => self::blankToNull($data['color'] ?? null),
                'description' => self::blankToNull($data['description'] ?? null),
            ])->save();

            $this->log->updated(ActivityEvent::PatchCordUpdated, $cord, $before, $actor);
        }, 'cord');
    }

    /**
     * What is told when a cord was unpatched in the meantime.
     */
    public static function gone(): string
    {
        return __('This cord was unpatched in the meantime: reload the page.');
    }

    /**
     * Unpatch several ports of a panel at once, consecutive or not: each
     * cord is deleted, one entry of the activity log each, and the ports
     * stay. The ports without a cord are left as they are.
     *
     * @param  list<int>  $panelPortIds
     * @return int How many cords were deleted.
     *
     * @throws ValidationException When none of the ports has a cord any more.
     */
    public function deleteMany(PatchPanel $panel, array $panelPortIds, Actor $actor): int
    {
        return $this->replayingCrossedWrites(function () use ($panel, $panelPortIds, $actor): int {
            $panel->loadMissing('room.floor.building');

            $ports = PatchPanelPort::query()
                ->where('patch_panel_id', $panel->id)
                ->whereKey($panelPortIds)
                ->lockForUpdate()
                ->get();

            if ($ports->count() !== count(array_unique($panelPortIds))) {
                throw ValidationException::withMessages(['panel_port_ids' => __('Some of these ports are no longer ports of :panel: reload the page.', ['panel' => $panel->name])]);
            }

            $cords = PatchCord::query()
                ->whereIn('patch_panel_port_id', $ports->modelKeys())
                ->with(['panelPort', 'port.device.site'])
                ->lockForUpdate()
                ->get()
                ->sortBy(fn (PatchCord $cord): int => $cord->panelPort->number)
                ->values();

            if ($cords->isEmpty()) {
                throw ValidationException::withMessages(['panel_port_ids' => __('None of these ports is patched any more: reload the page.')]);
            }

            foreach ($cords as $cord) {
                $cord->panelPort->setRelation('panel', $panel);

                // One entry per cord, as one per cord laid.
                $this->log->deleted(ActivityEvent::PatchCordDeleted, $cord, $actor);

                $cord->delete();
            }

            return $cords->count();
        }, 'panel_port_ids');
    }

    /**
     * Unpatch every port of a device at once: each cord is deleted, one
     * entry of the activity log each, in the order of the device, and the
     * ports stay.
     *
     * @return int How many cords were deleted.
     *
     * @throws ValidationException When none of its ports is patched any more.
     */
    public function deleteAllOf(Device $device, Actor $actor): int
    {
        return $this->replayingCrossedWrites(function () use ($device, $actor): int {
            $cords = PatchCord::query()
                ->whereIn('port_id', Port::query()->where('device_id', $device->id)->select('id'))
                ->with(['panelPort.panel.room.floor.building', 'port.member', 'port.device.site'])
                ->lockForUpdate()
                ->get()
                ->sortBy([
                    fn (PatchCord $a, PatchCord $b): int => $a->port->member->position <=> $b->port->member->position,
                    fn (PatchCord $a, PatchCord $b): int => $a->port->position <=> $b->port->position,
                    fn (PatchCord $a, PatchCord $b): int => $a->port->id <=> $b->port->id,
                ])
                ->values();

            if ($cords->isEmpty()) {
                throw ValidationException::withMessages(['cords' => __('No port of this device is patched any more: reload the page.')]);
            }

            foreach ($cords as $cord) {
                $this->log->deleted(ActivityEvent::PatchCordDeleted, $cord, $actor);

                $cord->delete();
            }

            return $cords->count();
        }, 'cords');
    }

    /**
     * Unpatch: the cord is deleted, its two ports stay.
     *
     * @throws ValidationException
     */
    public function delete(PatchCord $cord, Actor $actor): void
    {
        $this->replayingCrossedWrites(function () use ($cord, $actor): void {
            $cord = PatchCord::query()
                ->with(['panelPort.panel.room.floor.building', 'port.device.site'])
                ->whereKey($cord->getKey())
                ->lockForUpdate()
                ->first();

            // Unpatched in the meantime: there is nothing left to do.
            if ($cord === null) {
                return;
            }

            $this->log->deleted(ActivityEvent::PatchCordDeleted, $cord, $actor);

            $cord->delete();
        }, 'cord');
    }

    /**
     * Refuse to delete ports of devices while some of them are patched: the
     * refusal names each cord, ten at most. To be called in the transaction
     * that deletes the ports, their member or their device, before anything
     * is deleted.
     *
     * @param  Builder<Port>  $ports  The ports about to be deleted.
     * @param  string  $field  The field the refusal is told on.
     *
     * @throws ValidationException
     */
    public function refuseDeletion(Builder $ports, string $field): void
    {
        $cords = $this->patchedAmong($ports);

        if ($cords === []) {
            return;
        }

        throw ValidationException::withMessages([
            $field => trans_choice(
                ':cords: unpatch it first.|:cords: unpatch them first.',
                count($cords),
                ['cords' => $this->enumerate($cords)],
            ),
        ]);
    }

    /**
     * The cords of the ports given, kept from other changes until the end of
     * the transaction, each as a refusal names it: "1/0/1 is patched to
     * PB-A 15 (B.15)", in the order of the device.
     *
     * @param  Builder<Port>  $ports
     * @return list<string>
     */
    public function patchedAmong(Builder $ports): array
    {
        $ids = $ports->lockForUpdate()->pluck('ports.id');

        return array_values(Port::query()
            ->whereKey($ids)
            ->has('patchCord')
            ->with(['member', 'patchCord.panelPort.panel', 'patchCord.panelPort.jack'])
            ->get()
            ->sortBy([
                fn (Port $a, Port $b): int => $a->member->position <=> $b->member->position,
                fn (Port $a, Port $b): int => $a->position <=> $b->position,
                fn (Port $a, Port $b): int => $a->id <=> $b->id,
            ])
            ->map(fn (Port $port): string => __(':port is patched to :target', ['port' => $port->name, 'target' => self::target($port->patchCord?->panelPort)]))
            ->all());
    }

    /**
     * What the port of a device is patched to, as a refusal names it: the
     * port of the panel, with its jack when it has one, "PB-A 15 (B.15)".
     */
    private static function target(?PatchPanelPort $panelPort): string
    {
        if ($panelPort === null) {
            return '—';
        }

        return $panelPort->jack === null
            ? $panelPort->name()
            : $panelPort->name().' ('.$panelPort->jack->label.')';
    }

    /**
     * The ports of the panel to patch, by number, which must follow one
     * another.
     *
     * @param  list<int>  $ids
     * @return Collection<int, PatchPanelPort>
     *
     * @throws ValidationException
     */
    private function panelPorts(PatchPanel $panel, array $ids): Collection
    {
        $ports = PatchPanelPort::query()
            ->where('patch_panel_id', $panel->id)
            ->whereKey($ids)
            ->with(['cord.port.device', 'jack'])
            ->orderBy('number')
            ->lockForUpdate()
            ->get();

        if ($ports->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['panel_port_ids' => __('Some of these ports are no longer ports of :panel: reload the page.', ['panel' => $panel->name])]);
        }

        $numbers = $ports->pluck('number')->all();

        if ($numbers !== range($numbers[0], $numbers[0] + count($numbers) - 1)) {
            throw ValidationException::withMessages(['panel_port_ids' => __('The ports of the panel patched in one go follow one another: choose consecutive ports.')]);
        }

        foreach ($ports as $port) {
            $port->setRelation('panel', $panel);
        }

        return $ports;
    }

    /**
     * As many ports of the device as there are ports of the panel, in the
     * order of the device, member then place, from the first one chosen.
     *
     * @return list<Port>
     *
     * @throws ValidationException
     */
    private function devicePorts(Device $device, int $firstPortId, int $quantity): array
    {
        $ports = Port::query()
            ->where('device_id', $device->id)
            ->with(['member', 'patchCord.panelPort.panel', 'patchCord.panelPort.jack'])
            ->lockForUpdate()
            ->get()
            ->sortBy([
                fn (Port $a, Port $b): int => $a->member->position <=> $b->member->position,
                fn (Port $a, Port $b): int => $a->position <=> $b->position,
                fn (Port $a, Port $b): int => $a->id <=> $b->id,
            ])
            ->values();

        $start = $ports->search(fn (Port $port): bool => $port->id === $firstPortId);

        if ($start === false) {
            throw ValidationException::withMessages([
                'port_id' => __('This port is no longer a port of :device: choose it again.', ['device' => $device->name]),
            ]);
        }

        $chosen = array_values($ports->slice($start, $quantity)->all());

        if (count($chosen) < $quantity) {
            throw ValidationException::withMessages([
                'port_id' => trans_choice(
                    'From :port, :device has not enough ports for :count cord.|From :port, :device has not enough ports for :count cords.',
                    $quantity,
                    ['port' => $ports[$start]->name, 'device' => $device->name],
                ),
            ]);
        }

        foreach ($chosen as $port) {
            $port->setRelation('device', $device);
        }

        return $chosen;
    }

    /**
     * Each port, of the panel or of the device, has one cord at most: the
     * refusal names the ports in the way and what they are patched to, ten
     * of them at most.
     *
     * @param  Collection<int, PatchPanelPort>  $panelPorts
     * @param  list<Port>  $ports
     *
     * @throws ValidationException
     */
    private function refusePatched(PatchPanel $panel, Collection $panelPorts, Device $device, array $ports): void
    {
        $taken = array_values($panelPorts
            ->filter(fn (PatchPanelPort $port): bool => $port->cord !== null)
            ->map(fn (PatchPanelPort $port): string => __(':port is patched to :target', [
                'port' => $port->name(),
                'target' => $port->cord === null ? '—' : $port->cord->port->device->name.' '.$port->cord->port->name,
            ]))
            ->all());

        if ($taken !== []) {
            throw ValidationException::withMessages([
                'panel_port_ids' => trans_choice(
                    ':cords: unpatch it first.|:cords: unpatch them first.',
                    count($taken),
                    ['cords' => $this->enumerate($taken)],
                ),
            ]);
        }

        $busy = array_values(array_map(
            fn (Port $port): string => __(':port is patched to :target', ['port' => $device->name.' '.$port->name, 'target' => self::target($port->patchCord?->panelPort)]),
            array_filter($ports, fn (Port $port): bool => $port->patchCord !== null),
        ));

        if ($busy !== []) {
            throw ValidationException::withMessages([
                'port_id' => trans_choice(
                    ':cords: unpatch it first.|:cords: unpatch them first.',
                    count($busy),
                    ['cords' => $this->enumerate($busy)],
                ),
            ]);
        }

        // A port has one connector. The jack cords are read as they are
        // stored now, the rows of the ports being locked: one laid since
        // the transaction began is seen.
        $order = array_flip(array_map(fn (Port $port): int => $port->id, $ports));

        $plugged = array_values(JackCord::query()
            ->whereIn('port_id', array_keys($order))
            ->with(['jack', 'port'])
            ->lockForUpdate()
            ->get()
            ->sortBy(fn (JackCord $cord): int => $order[$cord->port_id])
            ->map(fn (JackCord $cord): string => __(':port is plugged into the jack :jack', ['port' => $device->name.' '.$cord->port->name, 'jack' => $cord->jack->label]))
            ->all());

        if ($plugged !== []) {
            throw ValidationException::withMessages([
                'port_id' => trans_choice(
                    ':cords: a port has one connector, unplug it first.|:cords: a port has one connector, unplug them first.',
                    count($plugged),
                    ['cords' => $this->enumerate($plugged)],
                ),
            ]);
        }
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
