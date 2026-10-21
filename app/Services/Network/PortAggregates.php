<?php

// torsade/app/Services/Network/PortAggregates.php

namespace App\Services\Network;

use App\Enums\ActivityEvent;
use App\Models\Device;
use App\Models\Port;
use App\Models\PortAggregate;
use App\Services\Network\Concerns\TranslatesUniqueViolations;
use App\Support\Actor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change to the aggregates of ports of a device goes through this
 * class, whether it comes from the interface or from a command: the rules
 * are enforced here and each change is written to the activity log.
 *
 * An aggregate groups ports of one device, on one or several of its
 * members. Its mode and its VLANs are set on the aggregate, by the rules of
 * App\Services\Network\VlanCarriage: a port that joins it loses its own,
 * and a port that leaves it has none.
 *
 * @phpstan-type AggregateData array{name: string, protocol: string, description?: string|null, port_ids?: list<int>, mode?: string|null, untagged_vlan_id?: int|null, tagged_vlan_ids?: list<int>}
 */
final class PortAggregates
{
    use TranslatesUniqueViolations;

    public function __construct(
        private readonly ChangeLog $log,
        private readonly VlanCarriage $carriage,
    ) {}

    /**
     * @param  AggregateData  $data
     *
     * @throws ValidationException Messages are keyed by field.
     */
    public function create(Device $device, array $data, Actor $actor): PortAggregate
    {
        return DB::transaction(function () use ($device, $data, $actor): PortAggregate {
            $device = $this->locked($device);

            $aggregate = new PortAggregate;
            $aggregate->device()->associate($device);
            $aggregate->setRelation('ports', new Collection);
            $aggregate->setRelation('portVlans', new Collection);

            $changes = $this->write($aggregate, $data);

            $this->log->created(ActivityEvent::PortAggregateCreated, $aggregate, $actor);

            $this->logPorts($changes, $actor);

            return $aggregate;
        });
    }

    /**
     * @param  AggregateData  $data
     *
     * @throws ValidationException Messages are keyed by field.
     */
    public function update(PortAggregate $aggregate, array $data, Actor $actor): void
    {
        DB::transaction(function () use ($aggregate, $data, $actor): void {
            $device = $this->locked($aggregate->device);

            $aggregate->setRelation('device', $device);
            $aggregate->setRelation('ports', $this->portsOf($aggregate, $device));
            $aggregate->load('portVlans.vlan.site');

            $before = $aggregate->auditValues();

            $changes = $this->write($aggregate, $data);

            $this->log->updated(ActivityEvent::PortAggregateUpdated, $aggregate, $before, $actor);

            $this->logPorts($changes, $actor);
        });
    }

    /**
     * Delete an aggregate: its ports are free again, without a mode nor a
     * VLAN.
     */
    public function delete(PortAggregate $aggregate, Actor $actor): void
    {
        DB::transaction(function () use ($aggregate, $actor): void {
            $device = $this->locked($aggregate->device);

            $aggregate->setRelation('device', $device);
            $aggregate->setRelation('ports', $this->portsOf($aggregate, $device));
            $aggregate->load('portVlans.vlan.site');

            $this->log->deleted(ActivityEvent::PortAggregateDeleted, $aggregate, $actor);

            $changes = [];

            foreach ($aggregate->ports as $port) {
                $changes[] = [$port, $port->auditValues()];

                $port->aggregate()->dissociate();
                $port->save();
            }

            // The VLANs the aggregate carried go with it.
            $aggregate->delete();

            $this->logPorts($changes, $actor);
        });
    }

    /**
     * The device as it is stored now, kept from other changes until the
     * end of the transaction.
     */
    private function locked(Device $device): Device
    {
        return Device::query()->with('site')->whereKey($device->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * The ports of an aggregate, in the order of the device: member by
     * member, then by their place in their member. Each one is ready to be
     * read by the activity log.
     *
     * @return Collection<int, Port>
     */
    private function portsOf(PortAggregate $aggregate, Device $device): Collection
    {
        return $this->ordered(
            Port::query()
                ->where('port_aggregate_id', $aggregate->id)
                ->with(['member', 'portVlans.vlan.site'])
                ->get(),
            $aggregate,
            $device,
        );
    }

    /**
     * @param  Collection<int, Port>  $ports
     * @return Collection<int, Port>
     */
    private function ordered(Collection $ports, ?PortAggregate $aggregate, Device $device): Collection
    {
        foreach ($ports as $port) {
            $port->setRelation('device', $device);

            if ($aggregate !== null) {
                $port->setRelation('aggregate', $aggregate);
            }
        }

        return $ports->sortBy([
            fn (Port $a, Port $b): int => $a->member->position <=> $b->member->position,
            fn (Port $a, Port $b): int => $a->position <=> $b->position,
            fn (Port $a, Port $b): int => $a->id <=> $b->id,
        ])->values();
    }

    /**
     * Check what depends on stored data, then save the aggregate, its
     * ports and what it carries: no other aggregate of the device has the
     * name, its ports belong to the device and to no other aggregate, and
     * its mode and its VLANs follow the rules.
     *
     * @param  AggregateData  $data
     * @return list<array{0: Port, 1: array<string, scalar|null>}> The ports that joined or left the aggregate, each with its values before.
     *
     * @throws ValidationException
     */
    private function write(PortAggregate $aggregate, array $data): array
    {
        $device = $aggregate->device;
        $name = trim($data['name']);

        // The collation of the column does not tell capitals from small
        // letters: the refusal names the aggregate as it is written.
        $other = PortAggregate::query()
            ->where('device_id', $device->id)
            ->where('name', $name)
            ->whereKeyNot($aggregate->getKey())
            ->first();

        if ($other !== null) {
            throw ValidationException::withMessages([
                'name' => __('The device already has an aggregate named :name.', ['name' => $other->name]),
            ]);
        }

        $ids = array_values(array_unique($data['port_ids'] ?? []));

        $chosen = Port::query()
            ->where('device_id', $device->id)
            ->whereKey($ids)
            ->with(['member', 'aggregate', 'portVlans.vlan.site'])
            ->lockForUpdate()
            ->get();

        if ($chosen->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'port_ids' => __('Some of these ports no longer exist: reload the page.'),
            ]);
        }

        $chosen = $this->ordered($chosen, null, $device);

        // A port is part of one aggregate at most.
        $elsewhere = array_values($chosen
            ->filter(fn (Port $port): bool => $port->port_aggregate_id !== null && $port->port_aggregate_id !== $aggregate->getKey())
            ->map(fn (Port $port): string => $port->name)
            ->all());

        if ($elsewhere !== []) {
            throw ValidationException::withMessages([
                'port_ids' => trans_choice(
                    'The port :names is already part of another aggregate: take it out of it first.|The ports :names are already part of another aggregate: take them out of it first.',
                    count($elsewhere),
                    ['names' => Ports::named($elsewhere)],
                ),
            ]);
        }

        $carriage = $this->carriage->resolve($device->site_id, $data);

        $aggregate->fill([
            'name' => $name,
            'protocol' => $data['protocol'],
            'description' => $this->blankToNull($data['description'] ?? null),
        ]);

        $this->guardingUniqueness(fn (): bool => $aggregate->save(), [
            'port_aggregates_device_id_name_unique' => ['name', __('The device already has an aggregate named :name.', ['name' => $name])],
        ]);

        if (! $this->carriage->matches($aggregate, $carriage)) {
            $this->carriage->write($aggregate, $carriage);
        }

        $changes = [];

        // The ports that leave the aggregate are free again.
        foreach ($aggregate->ports as $port) {
            if ($chosen->contains(fn (Port $one): bool => $one->is($port))) {
                continue;
            }

            $changes[] = [$port, $port->auditValues()];

            $port->aggregate()->dissociate();
            $port->save();
        }

        // Those that join it lose their own mode and their own VLANs: the
        // ones of the aggregate apply to them.
        foreach ($chosen as $port) {
            if ($port->port_aggregate_id === $aggregate->id) {
                $port->setRelation('aggregate', $aggregate);

                continue;
            }

            $changes[] = [$port, $port->auditValues()];

            if ($port->mode !== null || $port->portVlans->isNotEmpty()) {
                $this->carriage->clear($port);
            }

            $port->aggregate()->associate($aggregate);
            $port->save();
        }

        $aggregate->setRelation('ports', $chosen);

        return $changes;
    }

    /**
     * Write what changed on each port that joined or left an aggregate.
     *
     * @param  list<array{0: Port, 1: array<string, scalar|null>}>  $changes
     */
    private function logPorts(array $changes, Actor $actor): void
    {
        foreach ($changes as [$port, $before]) {
            $this->log->updated(ActivityEvent::PortUpdated, $port, $before, $actor);
        }
    }

    private function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }
}
