<?php

// torsade/app/Services/Network/JackCords.php

namespace App\Services\Network;

use App\Enums\ActivityEvent;
use App\Enums\Status;
use App\Models\Device;
use App\Models\Jack;
use App\Models\JackCord;
use App\Models\PatchCord;
use App\Models\Port;
use App\Services\Network\Concerns\PhrasesMessages;
use App\Services\Network\Concerns\RetriesCrossedWrites;
use App\Services\Network\Concerns\TranslatesUniqueViolations;
use App\Support\Actor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Every change to the jack cords goes through this class, whether it comes
 * from the interface or from a command: the rules are enforced here and each
 * cord is written to the activity log, with its two ends.
 *
 * A jack cord plugs a port of a device into a wall jack of the same site, on
 * the side of the desk. A jack has one at most, and so has a port; a port
 * has one connector, so a port patched to a panel is not plugged into a
 * jack, nor the other way round. What is retired, a jack or a device, is
 * plugged into nothing.
 *
 * Each rule is checked again when the cord is written: the row of the port
 * and the row of the jack are locked, and the cords of the other kind are
 * read as they are stored at that moment, not as the transaction first saw
 * them. A write the database gives up for a crossing one is replayed.
 *
 * A plugged port is not deleted, nor its member nor its device; a plugged
 * device does not move to another site, a cord not joining two sites.
 *
 * @phpstan-type CordData array{color?: string|null, description?: string|null}
 */
final class JackCords
{
    use PhrasesMessages;
    use RetriesCrossedWrites;
    use TranslatesUniqueViolations;

    /**
     * What a cord is read with to be told in the activity log: where its
     * jack is, and the device of its port with its site.
     *
     * @var list<string>
     */
    private const array LOGGED = ['jack.floor.building.site', 'port.device.site'];

    public function __construct(
        private readonly ChangeLog $log,
    ) {}

    /**
     * Plug a port of a device into a jack.
     *
     * @param  CordData  $data
     *
     * @throws ValidationException Messages are keyed by field: "jack_id" or "port_id".
     */
    public function create(int $jackId, int $portId, array $data, Actor $actor): JackCord
    {
        return $this->replayingCrossedWrites(function () use ($jackId, $portId, $data, $actor): JackCord {
            $jack = Jack::query()
                ->with('floor.building.site')
                ->whereKey($jackId)
                ->lockForUpdate()
                ->first();

            if ($jack === null) {
                throw ValidationException::withMessages(['jack_id' => __('This jack no longer exists.')]);
            }

            $port = Port::query()
                ->whereKey($portId)
                ->lockForUpdate()
                ->first();

            if ($port === null) {
                throw ValidationException::withMessages(['port_id' => __('This port no longer exists.')]);
            }

            // The device as it is stored now, locked too: a move to another
            // site or a retirement that commits meanwhile is seen, never the
            // device as the transaction first read it.
            $device = Device::query()
                ->with('site')
                ->whereKey($port->device_id)
                ->lockForUpdate()
                ->first();

            if ($device === null) {
                throw ValidationException::withMessages(['port_id' => __('This port no longer exists.')]);
            }

            $port->setRelation('device', $device);

            $this->refuseRetired($jack, $port);

            // A cord stays within a building, never between two sites.
            if ($port->device->site_id !== $jack->siteId()) {
                throw ValidationException::withMessages([
                    'port_id' => __('The device :device is on the site :site, the jack on the site :jack: a cord does not join two sites.', [
                        'device' => $port->device->name,
                        'site' => $port->device->site->name,
                        'jack' => $jack->floor->building->site->name,
                    ]),
                ]);
            }

            $this->refuseTaken($jack, $port);

            $cord = new JackCord([
                'color' => self::blankToNull($data['color'] ?? null),
                'description' => self::blankToNull($data['description'] ?? null),
            ]);
            $cord->jack()->associate($jack);
            $cord->port()->associate($port);

            $this->guardingUniqueness(fn (): bool => $cord->save(), [
                'jack_cords_jack_id_unique' => ['jack_id', __('A device was plugged into this jack in the meantime: reload the page.')],
                'jack_cords_port_id_unique' => ['port_id', __('This port was plugged into a jack in the meantime: reload the page.')],
            ]);

            $this->log->created(ActivityEvent::JackCordCreated, $cord, $actor);

            return $cord;
        }, 'port_id');
    }

    /**
     * Change the colour and the description of a cord. Its two ends do not
     * change: to move a cord, the port is unplugged, then plugged again.
     *
     * The cord is given by its identifier: one unplugged in the meantime is
     * told in words, as a refusal on the field "cord".
     *
     * @param  CordData  $data
     *
     * @throws ValidationException When the cord was unplugged in the meantime.
     */
    public function update(int $cordId, array $data, Actor $actor): void
    {
        $this->replayingCrossedWrites(function () use ($cordId, $data, $actor): void {
            $cord = JackCord::query()
                ->with(self::LOGGED)
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

            $this->log->updated(ActivityEvent::JackCordUpdated, $cord, $before, $actor);
        }, 'cord');
    }

    /**
     * What is told when a cord was unplugged in the meantime.
     */
    public static function gone(): string
    {
        return __('This cord was unplugged in the meantime: reload the page.');
    }

    /**
     * Unplug: the cord is deleted, the jack and the port stay. A cord
     * unplugged in the meantime leaves nothing to do.
     */
    public function delete(int $cordId, Actor $actor): void
    {
        $this->replayingCrossedWrites(function () use ($cordId, $actor): void {
            $cord = JackCord::query()
                ->with(self::LOGGED)
                ->whereKey($cordId)
                ->lockForUpdate()
                ->first();

            if ($cord === null) {
                return;
            }

            $this->log->deleted(ActivityEvent::JackCordDeleted, $cord, $actor);

            $cord->delete();
        }, 'cord');
    }

    /**
     * Unplug every port of a device at once: each cord is deleted, one
     * entry of the activity log each, in the order of the device, and the
     * jacks and the ports stay.
     *
     * @return int How many cords were deleted.
     *
     * @throws ValidationException When none of its ports is plugged any more.
     */
    public function deleteAllOf(Device $device, Actor $actor): int
    {
        return $this->replayingCrossedWrites(function () use ($device, $actor): int {
            $cords = JackCord::query()
                ->whereIn('port_id', Port::query()->where('device_id', $device->id)->select('id'))
                ->with([...self::LOGGED, 'port.member'])
                ->lockForUpdate()
                ->get()
                ->sortBy([
                    fn (JackCord $a, JackCord $b): int => $a->port->member->position <=> $b->port->member->position,
                    fn (JackCord $a, JackCord $b): int => $a->port->position <=> $b->port->position,
                    fn (JackCord $a, JackCord $b): int => $a->port->id <=> $b->port->id,
                ])
                ->values();

            if ($cords->isEmpty()) {
                throw ValidationException::withMessages(['jack_cords' => __('No port of this device is plugged into a jack any more: reload the page.')]);
            }

            foreach ($cords as $cord) {
                $this->log->deleted(ActivityEvent::JackCordDeleted, $cord, $actor);

                $cord->delete();
            }

            return $cords->count();
        }, 'jack_cords');
    }

    /**
     * Refuse to delete ports of devices while some of them are plugged
     * into a jack: the refusal names each cord, ten at most. To be called
     * in the transaction that deletes the ports, their member or their
     * device, before anything is deleted.
     *
     * @param  Builder<Port>  $ports  The ports about to be deleted.
     * @param  string  $field  The field the refusal is told on.
     *
     * @throws ValidationException
     */
    public function refuseDeletion(Builder $ports, string $field): void
    {
        $cords = $this->pluggedAmong($ports);

        if ($cords === []) {
            return;
        }

        throw ValidationException::withMessages([
            $field => trans_choice(
                ':cords: unplug it first.|:cords: unplug them first.',
                count($cords),
                ['cords' => $this->enumerate($cords)],
            ),
        ]);
    }

    /**
     * Refuse to delete a jack while a device is plugged into it. To be
     * called in the transaction that deletes the jack, its row locked.
     *
     * @throws ValidationException
     */
    public function refuseJackDeletion(Jack $jack, string $field): void
    {
        $cord = JackCord::query()
            ->where('jack_id', $jack->id)
            ->with('port.device')
            ->lockForUpdate()
            ->first();

        if ($cord === null) {
            return;
        }

        throw ValidationException::withMessages([
            $field => __(':device :port is plugged into this jack: unplug it first.', [
                'device' => $cord->port->device->name,
                'port' => $cord->port->name,
            ]),
        ]);
    }

    /**
     * The cords of the ports given, kept from other changes until the end of
     * the transaction, each as a refusal names it: "1/0/1 is plugged into
     * the jack B.07", in the order of the device.
     *
     * @param  Builder<Port>  $ports
     * @return list<string>
     */
    public function pluggedAmong(Builder $ports): array
    {
        $ids = $ports->lockForUpdate()->pluck('ports.id');

        $cords = JackCord::query()
            ->whereIn('port_id', $ids)
            ->with(['jack', 'port.member'])
            ->lockForUpdate()
            ->get();

        return array_values($cords
            ->sortBy([
                fn (JackCord $a, JackCord $b): int => $a->port->member->position <=> $b->port->member->position,
                fn (JackCord $a, JackCord $b): int => $a->port->position <=> $b->port->position,
                fn (JackCord $a, JackCord $b): int => $a->port->id <=> $b->port->id,
            ])
            ->map(fn (JackCord $cord): string => __(':port is plugged into the jack :jack', ['port' => $cord->port->name, 'jack' => $cord->jack->label]))
            ->all());
    }

    /**
     * What is retired is plugged into nothing: neither a jack nor a device
     * retired enters a path.
     *
     * @throws ValidationException
     */
    private function refuseRetired(Jack $jack, Port $port): void
    {
        if ($jack->status === Status::Retired) {
            throw ValidationException::withMessages([
                'jack_id' => __('The jack :jack is retired: nothing is plugged into it.', ['jack' => $jack->label]),
            ]);
        }

        if ($port->device->status === Status::Retired) {
            throw ValidationException::withMessages([
                'port_id' => __('The device :device is retired: it is plugged into no jack.', ['device' => $port->device->name]),
            ]);
        }
    }

    /**
     * A jack has one cord at most, a port one connector: the refusal names
     * what is in the way. The cords are read as they are stored now, locked
     * until the end of the transaction, whatever the transaction saw first.
     *
     * @throws ValidationException
     */
    private function refuseTaken(Jack $jack, Port $port): void
    {
        $onJack = JackCord::query()->where('jack_id', $jack->id)->with('port.device')->lockForUpdate()->first();

        if ($onJack !== null) {
            throw ValidationException::withMessages([
                'jack_id' => __(':device :port is already plugged into the jack :jack: unplug it first.', [
                    'device' => $onJack->port->device->name,
                    'port' => $onJack->port->name,
                    'jack' => $jack->label,
                ]),
            ]);
        }

        $onPort = JackCord::query()->where('port_id', $port->id)->with('jack')->lockForUpdate()->first();

        if ($onPort !== null) {
            throw ValidationException::withMessages([
                'port_id' => __(':device :port is already plugged into the jack :jack: unplug it first.', [
                    'device' => $port->device->name,
                    'port' => $port->name,
                    'jack' => $onPort->jack->label,
                ]),
            ]);
        }

        $patched = PatchCord::query()->where('port_id', $port->id)->with(['panelPort.panel', 'panelPort.jack'])->lockForUpdate()->first();

        if ($patched !== null) {
            throw ValidationException::withMessages([
                'port_id' => __(':device :port is patched to :target: a port has one connector, unpatch it first.', [
                    'device' => $port->device->name,
                    'port' => $port->name,
                    'target' => $patched->panelPort->name(),
                ]),
            ]);
        }
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
