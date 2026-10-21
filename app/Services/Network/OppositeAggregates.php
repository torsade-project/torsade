<?php

// torsade/app/Services/Network/OppositeAggregates.php

namespace App\Services\Network;

use App\Models\Device;
use App\Models\Port;
use App\Models\PortAggregate;
use App\Models\Vlan;

/**
 * The aggregate missing in front of another one: when the links of an
 * aggregate all lead to ports of one same device, none of which is in an
 * aggregate, the window of a new aggregate of that device is offered with
 * those ports, the protocol, the mode and the VLANs of the aggregate in
 * front, and a name in the series of the aggregates of the device. Nothing
 * is written here: the window saves it as any new aggregate, and everything
 * in it can still be changed.
 *
 * @phpstan-type Draft array{origin: string, name: string, protocol: string, mode: string|null, untagged_vlan_id: int|null, tagged_vlan_ids: list<int>, port_ids: list<int>}
 */
final class OppositeAggregates
{
    /**
     * The window of the aggregate in front of the given one, on the device
     * given, or null when it is not one to create there: a link of the
     * aggregate leads to another device, or a port in front already is in
     * an aggregate.
     *
     * @return Draft|null
     */
    public function draft(PortAggregate $origin, Device $device): ?array
    {
        $origin->loadMissing(['device', 'portVlans.vlan', 'ports.linkEnd.link.ends.port.member']);

        $opposite = $this->inFront($origin->ports->all());

        if ($this->deviceInFront($opposite) !== $device->id) {
            return null;
        }

        // In the order of the device: member, then place.
        usort($opposite, fn (Port $a, Port $b): int => [$a->member->position, $a->position, $a->id] <=> [$b->member->position, $b->position, $b->id]);

        return [
            'origin' => $origin->device->name.' › '.$origin->name,
            'name' => self::nextName(array_values(array_map(strval(...), $device->aggregates()->orderBy('id')->pluck('name')->all()))),
            'protocol' => $origin->protocol->value,
            'mode' => $origin->mode?->value,
            'untagged_vlan_id' => $origin->untaggedVlan()?->id,
            'tagged_vlan_ids' => array_map(fn (Vlan $vlan): int => $vlan->id, $origin->taggedVlans()),
            'port_ids' => array_map(fn (Port $port): int => $port->id, $opposite),
        ];
    }

    /**
     * The device on which the aggregate missing in front of each aggregate
     * given can be created, by the identifier of the aggregate; an
     * aggregate is left out when it cannot. Every link of each aggregate is
     * read, not only those a page shows, in the same number of queries
     * whatever the number of aggregates.
     *
     * @param  list<int>  $aggregateIds
     * @return array<int, int>
     */
    public function devicesInFront(array $aggregateIds): array
    {
        if ($aggregateIds === []) {
            return [];
        }

        $found = [];
        $ports = Port::query()
            ->whereIn('port_aggregate_id', $aggregateIds)
            ->with('linkEnd.link.ends.port')
            ->get()
            ->groupBy('port_aggregate_id');

        foreach ($ports as $id => $group) {
            $device = $this->deviceInFront($this->inFront($group->all()));

            if ($device !== null) {
                $found[(int) $id] = $device;
            }
        }

        return $found;
    }

    /**
     * The ports at the other end of the links of the ports given.
     *
     * @param  array<int, Port>  $ports
     * @return list<Port>
     */
    private function inFront(array $ports): array
    {
        $opposite = [];

        foreach ($ports as $port) {
            $link = $port->linkEnd?->link;

            if ($link === null) {
                continue;
            }

            foreach ($link->ends as $end) {
                if ($end->port_id !== $port->id) {
                    $opposite[] = $end->port;
                }
            }
        }

        return $opposite;
    }

    /**
     * The device of the ports in front of an aggregate, when they are all
     * on that device and in no aggregate; null otherwise, or when there
     * are none.
     *
     * @param  list<Port>  $opposite
     */
    private function deviceInFront(array $opposite): ?int
    {
        $devices = array_values(array_unique(array_map(fn (Port $port): int => $port->device_id, $opposite)));

        if (count($devices) !== 1) {
            return null;
        }

        foreach ($opposite as $port) {
            if ($port->port_aggregate_id !== null) {
                return null;
            }
        }

        return $devices[0];
    }

    /**
     * The next name in the series of the aggregates of a device: the series
     * of the last aggregate that ends with a number, followed by the number
     * after the highest of that series, "LAG3" after "LAG2"; "Po1" when
     * none ends with a number. A name already taken is skipped.
     *
     * @param  list<string>  $names  The names of the aggregates, the oldest first.
     */
    public static function nextName(array $names): string
    {
        $prefix = null;
        $numbers = [];

        foreach ($names as $name) {
            if (preg_match('/^(.*?)(\d+)$/', $name, $parts) === 1) {
                $prefix = $parts[1];
                $numbers[$parts[1]][] = (int) $parts[2];
            }
        }

        if ($prefix === null) {
            $prefix = 'Po';
            $next = 1;
        } else {
            $next = max($numbers[$prefix]) + 1;
        }

        $taken = array_flip(array_map(mb_strtolower(...), $names));

        while (isset($taken[mb_strtolower($prefix.$next)])) {
            $next++;
        }

        return $prefix.$next;
    }
}
