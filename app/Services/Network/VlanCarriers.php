<?php

// torsade/app/Services/Network/VlanCarriers.php

namespace App\Services\Network;

use App\Enums\PortMode;
use App\Models\Device;
use App\Models\PortVlan;
use App\Models\Vlan;
use Illuminate\Database\Eloquent\Collection;

/**
 * The ports and the aggregates of ports that carry a VLAN, device by
 * device: what the page of the VLAN lists, and what stands in the way of
 * its deletion or of a change of its number.
 *
 * The number of queries does not depend on the number of ports.
 *
 * @phpstan-type Carrier array{kind: 'port'|'aggregate', name: string, member: int|null, role: string}
 * @phpstan-type CarrierGroup array{device: Device, carriers: list<Carrier>}
 */
final class VlanCarriers
{
    /**
     * How a VLAN is carried: untagged by an access port, tagged by it for
     * the voice, native on a trunk, or tagged on it.
     */
    public const string UNTAGGED = 'untagged';

    public const string VOICE = 'voice';

    public const string NATIVE = 'native';

    public const string TAGGED = 'tagged';

    /**
     * How many ports a message names at most: the others are counted.
     */
    public const int NAMED = 10;

    /**
     * Every port and every aggregate that carries the VLAN: the devices by
     * name, the ports of each in the order of the device, then its
     * aggregates by name.
     *
     * @return list<CarrierGroup>
     */
    public function of(Vlan $vlan): array
    {
        return $this->grouped(
            PortVlan::query()
                ->where('vlan_id', $vlan->id)
                ->with(['port.member', 'port.device', 'aggregate.device'])
                ->get(),
        );
    }

    /**
     * The ports and the aggregates that carry the VLAN and, with it,
     * another VLAN of the number given: those a change of the number of the
     * VLAN would give two VLANs of one number. Read as they are stored, and
     * kept from other changes until the end of the transaction: a port
     * given the VLAN meanwhile waits for it. Grouped as of() groups them.
     *
     * @return list<CarrierGroup>
     */
    public function alongside(Vlan $vlan, int $vid): array
    {
        $holders = PortVlan::query()
            ->where('vlan_id', $vlan->id)
            ->lockForUpdate()
            ->get(['id', 'port_id', 'port_aggregate_id']);

        $portIds = array_values(array_filter($holders->pluck('port_id')->all()));
        $aggregateIds = array_values(array_filter($holders->pluck('port_aggregate_id')->all()));

        if ($portIds === [] && $aggregateIds === []) {
            return [];
        }

        // Joined rather than filtered by a subquery: the numbers are read as
        // they are stored now, and kept so.
        return $this->grouped(
            PortVlan::query()
                ->select('port_vlans.*')
                ->join('vlans', 'vlans.id', '=', 'port_vlans.vlan_id')
                ->where('vlans.vid', $vid)
                ->where('port_vlans.vlan_id', '!=', $vlan->id)
                ->where(fn ($query) => $query
                    ->whereIn('port_vlans.port_id', $portIds)
                    ->orWhereIn('port_vlans.port_aggregate_id', $aggregateIds))
                ->lockForUpdate()
                ->with(['port.member', 'port.device', 'aggregate.device'])
                ->get(),
        );
    }

    /**
     * Lines of port_vlans as the carriers of each device: the devices by
     * name, the ports of each in the order of the device, then its
     * aggregates by name. Each line must be loaded with its port, the
     * member and the device of the port, or its aggregate and the device of
     * the aggregate.
     *
     * @param  Collection<int, PortVlan>  $lines
     * @return list<CarrierGroup>
     */
    private function grouped(Collection $lines): array
    {
        /** @var array<int, Device> $devices */
        $devices = [];
        /** @var array<int, list<array{0: array<int, int|string>, 1: Carrier}>> $sortable */
        $sortable = [];

        foreach ($lines as $line) {
            $port = $line->port;
            $aggregate = $line->aggregate;

            if ($port !== null) {
                $device = $port->device;
                $mode = $port->mode;
                $carrier = ['kind' => 'port', 'name' => $port->name, 'member' => $port->member->position, 'role' => self::role($mode, $line->tagged)];
                // The ports first, in the order of the device.
                $order = [0, $port->member->position, $port->position, $port->id];
            } elseif ($aggregate !== null) {
                $device = $aggregate->device;
                $mode = $aggregate->mode;
                $carrier = ['kind' => 'aggregate', 'name' => $aggregate->name, 'member' => null, 'role' => self::role($mode, $line->tagged)];
                $order = [1, $aggregate->name, 0, $aggregate->id];
            } else {
                continue;
            }

            $devices[$device->id] = $device;
            $sortable[$device->id][] = [$order, $carrier];
        }

        $groups = [];

        foreach ($sortable as $deviceId => $entries) {
            usort($entries, fn (array $a, array $b): int => $a[0] <=> $b[0]);

            $groups[] = [
                'device' => $devices[$deviceId],
                'carriers' => array_map(fn (array $entry): array => $entry[1], $entries),
            ];
        }

        usort($groups, fn (array $a, array $b): int => [mb_strtolower($a['device']->name), $a['device']->id] <=> [mb_strtolower($b['device']->name), $b['device']->id]);

        return $groups;
    }

    /**
     * The ports and the aggregates that carry a VLAN, in a sentence: each
     * device with its ports, ten ports at most in all, then how many are
     * left out. "sw-coeur (1/0/1, 1/0/2, Po1), sw-etage-1 (1/0/24) and 3
     * more".
     *
     * @param  list<CarrierGroup>  $groups
     */
    public static function named(array $groups): string
    {
        $left = self::NAMED;
        $parts = [];
        $total = 0;

        foreach ($groups as $group) {
            $total += count($group['carriers']);

            $names = array_map(fn (array $carrier): string => $carrier['name'], array_slice($group['carriers'], 0, $left));
            $left -= count($names);

            if ($names !== []) {
                $parts[] = $group['device']->name.' ('.implode(', ', $names).')';
            }
        }

        $shown = implode(', ', $parts);
        $more = $total - self::NAMED;

        return $more > 0
            ? __(':names and :count more', ['names' => $shown, 'count' => $more])
            : $shown;
    }

    /**
     * The translation key of a way to carry a VLAN.
     */
    public static function label(string $role): string
    {
        return match ($role) {
            self::UNTAGGED => 'Untagged',
            self::VOICE => 'Voice',
            self::NATIVE => 'Native',
            default => 'Tagged',
        };
    }

    private static function role(?PortMode $mode, bool $tagged): string
    {
        return match (true) {
            $mode === PortMode::Access && $tagged => self::VOICE,
            $mode === PortMode::Access => self::UNTAGGED,
            $tagged => self::TAGGED,
            default => self::NATIVE,
        };
    }
}
