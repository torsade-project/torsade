<?php

// torsade/app/Services/Network/Links.php

namespace App\Services\Network;

use App\Enums\ActivityEvent;
use App\Models\Device;
use App\Models\Link;
use App\Models\LinkEnd;
use App\Models\Port;
use App\Services\Network\Concerns\PhrasesMessages;
use App\Services\Network\Concerns\RetriesCrossedWrites;
use App\Services\Network\Concerns\TranslatesUniqueViolations;
use App\Support\Actor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change to the links between devices goes through this class,
 * whether it comes from the interface or from a command: the rules are
 * enforced here and each change is written to the activity log, one entry
 * per link, with its two ends.
 *
 * A link joins two ports of two different devices, and a port is the end of
 * one link at most. Ports linked as a series are linked in the order of
 * each device, all of them or none.
 *
 * A linked port is not deleted, nor its member nor its device: the link is
 * deleted first, by someone who decides so.
 *
 * Linking locks the ports on both sides: two requests that link the same
 * ports from opposite sides can cross. The database then gives one of them
 * up, and it is replayed.
 *
 * @phpstan-type LinkData array{medium?: string|null, status: string, description?: string|null}
 */
final class Links
{
    use PhrasesMessages;
    use RetriesCrossedWrites;
    use TranslatesUniqueViolations;

    public function __construct(
        private readonly ChangeLog $log,
    ) {}

    /**
     * Link ports of a device to as many ports of another one: the first
     * with the first, in the order of each device. One port on each side
     * makes one link.
     *
     * @param  list<int>  $portIds  Ports of the device.
     * @param  list<int>  $oppositePortIds  Ports of the opposite device.
     * @param  LinkData  $data  What every link of the series is given.
     * @return int How many links were created.
     *
     * @throws ValidationException Messages are keyed by field.
     */
    public function create(Device $device, array $portIds, int $oppositeDeviceId, array $oppositePortIds, array $data, Actor $actor): int
    {
        return $this->replayingCrossedWrites(function () use ($device, $portIds, $oppositeDeviceId, $oppositePortIds, $data, $actor): int {
            $device->loadMissing('site');

            $opposite = Device::query()->with('site')->find($oppositeDeviceId);

            if ($opposite === null) {
                throw ValidationException::withMessages(['opposite_device_id' => __('This device no longer exists.')]);
            }

            if ($opposite->is($device)) {
                throw ValidationException::withMessages([
                    'opposite_device_id' => __('A link joins two different devices: choose another device than :name.', ['name' => $device->name]),
                ]);
            }

            // Told before anything is looked for, and long before anything
            // is written: which port goes with which would be a guess.
            if (count($portIds) !== count($oppositePortIds)) {
                throw ValidationException::withMessages([
                    'opposite_port_ids' => __('As many ports are needed on each side: :local on :device, :chosen on :opposite.', [
                        'local' => count($portIds),
                        'device' => $device->name,
                        'chosen' => count($oppositePortIds),
                        'opposite' => $opposite->name,
                    ]),
                ]);
            }

            $near = $this->portsOf($device, $portIds, 'port_ids', __('Some of these ports no longer exist: reload the page.'));
            $far = $this->portsOf($opposite, $oppositePortIds, 'opposite_port_ids', __('Some of these ports are no longer ports of :name: choose them again.', ['name' => $opposite->name]));

            $this->refuseLinked($near, 'port_ids', named: false);
            $this->refuseLinked($far, 'opposite_port_ids', named: true);

            foreach ($near as $index => $port) {
                $this->write($port, $far[$index], $data, $actor);
            }

            return $near->count();
        }, 'port_ids');
    }

    /**
     * Change what a link is: its medium, its status and its description.
     * Its ends do not change: another port is another link.
     *
     * @param  LinkData  $data
     */
    public function update(Link $link, array $data, Actor $actor): void
    {
        DB::transaction(function () use ($link, $data, $actor): void {
            $link->loadMissing('ends.port.device.site');

            $before = $link->auditValues();

            $link->fill($this->values($data));
            $link->save();

            $this->log->updated(ActivityEvent::LinkUpdated, $link, $before, $actor);
        });
    }

    /**
     * Delete a link. Its two ports stay, free to be linked again.
     */
    public function delete(Link $link, Actor $actor): void
    {
        DB::transaction(function () use ($link, $actor): void {
            $link->loadMissing('ends.port.device.site');

            $this->log->deleted(ActivityEvent::LinkDeleted, $link, $actor);

            // Its ends go with it.
            $link->delete();
        });
    }

    /**
     * Refuse to delete ports while some of them are linked: the refusal
     * names each link, ten at most. To be called in the transaction that
     * deletes the ports, their member or their device, before anything is
     * deleted: the ports are kept from being linked until its end.
     *
     * @param  Builder<Port>  $ports  The ports about to be deleted.
     * @param  string  $field  The field the refusal is told on.
     *
     * @throws ValidationException
     */
    public function refuseDeletion(Builder $ports, string $field): void
    {
        $ids = $ports->lockForUpdate()->pluck('ports.id');

        $linked = Port::query()
            ->whereKey($ids)
            ->has('linkEnd')
            ->with(['member', 'linkEnd.link.ends.port.device'])
            ->get()
            ->sortBy([
                fn (Port $a, Port $b): int => $a->member->position <=> $b->member->position,
                fn (Port $a, Port $b): int => $a->position <=> $b->position,
                fn (Port $a, Port $b): int => $a->id <=> $b->id,
            ])
            ->values();

        if ($linked->isEmpty()) {
            return;
        }

        $links = $linked
            ->map(fn (Port $port): string => __(':port is linked to :opposite', ['port' => $port->name, 'opposite' => self::facing($port)]))
            ->all();

        throw ValidationException::withMessages([
            $field => trans_choice(
                ':links: delete the link first.|:links: delete these links first.',
                count($links),
                ['links' => $this->enumerate($links)],
            ),
        ]);
    }

    /**
     * How a port is named away from its device: "sw-coeur-01 › 1/0/49".
     * Its device must be loaded.
     */
    public static function named(Port $port): string
    {
        return $port->device->name.' › '.$port->name;
    }

    /**
     * What a linked port faces, by name, or a dash when it faces nothing.
     */
    private static function facing(Port $port): string
    {
        $opposite = $port->linkedPort();

        return $opposite === null ? '—' : self::named($opposite);
    }

    /**
     * The ports of a device a series is made of, in the order of the
     * device, kept from other changes until the end of the transaction.
     * All of them belong to the device, or the series is refused: the
     * window that sent them no longer shows the device as it is.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Port>
     *
     * @throws ValidationException
     */
    private function portsOf(Device $device, array $ids, string $field, string $message): Collection
    {
        $ports = Port::query()
            ->where('device_id', $device->id)
            ->whereKey($ids)
            ->with(['member', 'linkEnd.link.ends.port.device'])
            ->lockForUpdate()
            ->get();

        if ($ports->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages([$field => $message]);
        }

        foreach ($ports as $port) {
            $port->setRelation('device', $device);
        }

        return $ports->sortBy([
            fn (Port $a, Port $b): int => $a->member->position <=> $b->member->position,
            fn (Port $a, Port $b): int => $a->position <=> $b->position,
            fn (Port $a, Port $b): int => $a->id <=> $b->id,
        ])->values();
    }

    /**
     * A port has one link at most, whichever end it is: the refusal names
     * what each port in the way is already linked to, ten ports at most.
     *
     * @param  Collection<int, Port>  $ports
     * @param  bool  $named  Whether the ports are named with their device: those of the opposite side.
     *
     * @throws ValidationException
     */
    private function refuseLinked(Collection $ports, string $field, bool $named): void
    {
        $linked = array_values($ports
            ->filter(fn (Port $port): bool => $port->linkEnd !== null)
            ->map(fn (Port $port): string => __(':port is already linked to :opposite', [
                'port' => $named ? self::named($port) : $port->name,
                'opposite' => self::facing($port),
            ]))
            ->all());

        if ($linked !== []) {
            throw ValidationException::withMessages([$field => $this->enumerate($linked).'.']);
        }
    }

    /**
     * Write one link and its two ends, and tell it in the activity log.
     *
     * @param  LinkData  $data
     *
     * @throws ValidationException
     */
    private function write(Port $first, Port $second, array $data, Actor $actor): void
    {
        $link = new Link($this->values($data));
        $link->save();

        $ends = new Collection;

        foreach ([[1, $first, 'port_ids'], [2, $second, 'opposite_port_ids']] as [$number, $port, $field]) {
            $end = new LinkEnd(['end' => $number]);
            $end->link()->associate($link);
            $end->port()->associate($port);

            // Two requests at the same time: the database refuses the
            // second one, whichever end the port would have been.
            $this->guardingUniqueness(fn (): bool => $end->save(), [
                'link_ends_port_id_unique' => [$field, __('One of these ports was linked in the meantime: reload the page.')],
            ]);

            $ends->push($end);
        }

        $link->setRelation('ends', $ends);

        $this->log->created(ActivityEvent::LinkCreated, $link, $actor);
    }

    /**
     * @param  LinkData  $data
     * @return array{medium: string|null, status: string, description: string|null}
     */
    private function values(array $data): array
    {
        return [
            'medium' => $this->blankToNull($data['medium'] ?? null),
            'status' => $data['status'],
            'description' => $this->blankToNull($data['description'] ?? null),
        ];
    }

    private function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }
}
