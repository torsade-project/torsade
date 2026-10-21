<?php

// torsade/app/Services/Network/DiagramDeviceDeviations.php

namespace App\Services\Network;

use App\Models\Device;
use App\Models\IpAddress;
use App\Models\Port;
use App\Models\PortAggregate;
use App\Models\PortVlan;
use App\Models\Vlan;
use Illuminate\Database\Eloquent\Collection;

/**
 * The deviations a diagram draws on the devices of its site: their
 * addresses on the network of another site, and the VLANs of one number a
 * switch merges. Read for all the devices at once, in the same number of
 * queries whatever their number.
 *
 * @phpstan-import-type Deviation from Deviations
 */
final class DiagramDeviceDeviations
{
    public function __construct(
        private readonly Deviations $deviations,
    ) {}

    /**
     * The deviations of each device given, by its identifier.
     *
     * @param  list<Device>  $devices  Each with its site.
     * @return array<int, list<Deviation>>
     */
    public function of(array $devices): array
    {
        if ($devices === []) {
            return [];
        }

        $ids = array_map(fn (Device $device): int => $device->id, $devices);

        // What the ports and the aggregates of these devices carry.
        $portLines = PortVlan::query()
            ->whereIn('port_id', Port::query()->select('id')->whereIn('device_id', $ids))
            ->with(['vlan.site', 'port:id,device_id'])
            ->get()
            ->groupBy(fn (PortVlan $line): int => (int) $line->port?->device_id);

        $aggregateLines = PortVlan::query()
            ->whereIn('port_aggregate_id', PortAggregate::query()->select('id')->whereIn('device_id', $ids))
            ->with(['vlan.site', 'aggregate:id,device_id'])
            ->get()
            ->groupBy(fn (PortVlan $line): int => (int) $line->aggregate?->device_id);

        $addresses = IpAddress::query()
            ->whereIn('device_id', $ids)
            ->with(['subnet.site', 'subnet.vlan'])
            ->orderBy('address')
            ->get()
            ->groupBy('device_id');

        $found = [];

        foreach ($devices as $device) {
            $byPorts = $portLines->get($device->id) ?? new Collection;
            $byAggregates = $aggregateLines->get($device->id) ?? new Collection;

            $device->setRelation('carriedByPorts', new Collection($byPorts->all()));
            $device->setRelation('carriedByAggregates', new Collection($byAggregates->all()));

            $deviations = [];

            foreach ($addresses->get($device->id) ?? [] as $address) {
                $address->setRelation('device', $device);

                foreach ($this->deviations->ofAddress($address) as $deviation) {
                    $deviations[] = ['kind' => $deviation['kind'], 'reason' => (string) __(':address: :reason', ['address' => $address->address, 'reason' => $deviation['reason']])];
                }
            }

            $vlans = [...$byPorts->map(fn (PortVlan $line): Vlan => $line->vlan)->all(), ...$byAggregates->map(fn (PortVlan $line): Vlan => $line->vlan)->all()];

            $found[$device->id] = [...$deviations, ...$this->deviations->ofSwitchCarrying($device, $vlans)];
        }

        return $found;
    }
}
