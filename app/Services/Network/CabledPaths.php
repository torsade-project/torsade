<?php

// torsade/app/Services/Network/CabledPaths.php

namespace App\Services\Network;

use App\Enums\Status;
use App\Models\Device;
use App\Models\IpAddress;
use App\Models\Jack;
use App\Models\Link;
use App\Models\PatchCord;
use App\Models\PatchPanelPort;
use App\Models\Port;
use Illuminate\Database\Eloquent\Builder;

/**
 * The cabled paths of one or several sites, worked out from what is
 * declared and never stored: a port patched to a port of a panel whose
 * jack has a port of a device plugged into it is cabled to that port. The
 * two ports are a pair, whichever side is looked at; a link between them
 * has that cabling as its physical path.
 *
 * Each pair is told how its links agree with it, in this order: a link that
 * is not retired joins the two ports, and the cabling is its path; a
 * retired link joins them, and the cords are still there; one of the two
 * has a link to another port, which the cabling contradicts; neither has a
 * link, and one is expected.
 *
 * A port linked to another one and patched to a jack where nothing is
 * plugged is linked without being plugged: the port in front could be
 * plugged into that jack. A port without a link patched to a jack where
 * nothing is plugged, but into which addresses of devices are plugged, is
 * cabled to those devices, without a port: a workstation whose ports are
 * not documented. Behind a jack that has a jack cord, only the device
 * plugged into it is cabled: a workstation behind a telephone is not.
 *
 * What is retired, a device, a panel or a jack, enters no path: it cuts
 * it. Links count whatever their status, a port having one at most; an
 * address that is retired counts for nothing.
 *
 * A cord never joins two sites: the paths of a site are all within it. The
 * number of queries depends neither on the number of jacks nor on the
 * number of devices.
 */
final class CabledPaths
{
    /**
     * What a patch cord is read with: the port of the panel with its panel
     * and its room, the jack with where it is, the device plugged into the
     * jack and the addresses plugged into it, and the patched port with its
     * device and its link, the port in front of the link with its own
     * connectors.
     *
     * @var list<string>
     */
    private const array RELATIONS = [
        'panelPort.panel.room.floor.building',
        'panelPort.jack.floor.building',
        'panelPort.jack.cord.port.device',
        'panelPort.jack.cord.port.linkEnd.link.ends.port.device',
        'panelPort.jack.ipAddresses.device',
        'port.device',
        'port.linkEnd.link.ends.port.device',
        'port.linkEnd.link.ends.port.patchCord',
        'port.linkEnd.link.ends.port.jackCord',
    ];

    /**
     * The cabling of the sites given, or of every site.
     *
     * @param  list<int>|null  $siteIds
     */
    public function of(?array $siteIds = null): CablingMap
    {
        if ($siteIds === []) {
            return new CablingMap([], [], []);
        }

        $sites = $siteIds === null ? null : array_values(array_unique($siteIds));

        $cords = PatchCord::query()
            ->whereHas('panelPort', fn (Builder $query) => $query
                ->has('jack')
                ->when($sites !== null, fn (Builder $query) => $query->whereHas(
                    'panel.room.floor.building',
                    fn (Builder $query) => $query->whereIn('site_id', $sites ?? []),
                )))
            ->with(self::RELATIONS)
            ->orderBy('id')
            ->get();

        $pairs = [];
        $unplugged = [];
        $addressed = [];

        foreach ($cords as $cord) {
            $port = $cord->port;
            $panelPort = $cord->panelPort;
            $jack = $panelPort->jack;

            if ($jack === null || $this->cut($port->device, $panelPort, $jack)) {
                continue;
            }

            $panelPort->setRelation('jack', $jack);
            $jack->setRelation('panelPort', $panelPort);

            $plugged = $jack->cord?->port;

            if ($plugged !== null) {
                if ($plugged->device->status !== Status::Retired) {
                    $pairs[] = $this->pair($port, $plugged, $panelPort, $jack);
                }

                continue;
            }

            $link = $port->linkEnd?->link;

            if ($link !== null) {
                if ($link->status !== Status::Retired) {
                    $opposite = $port->linkedPort();

                    if ($opposite !== null) {
                        $unplugged[] = [
                            'port' => $port,
                            'link' => $link,
                            'opposite' => $opposite,
                            'panel_port' => $panelPort,
                            'jack' => $jack,
                            'pluggable' => $this->pluggable($opposite, $jack),
                        ];
                    }
                }

                continue;
            }

            $devices = [];

            foreach ($jack->ipAddresses as $address) {
                $device = $this->addressedDevice($address);

                if ($device !== null && ! $device->is($port->device)) {
                    $devices[$device->id] = $device;
                }
            }

            if ($devices !== []) {
                $addressed[] = ['port' => $port, 'panel_port' => $panelPort, 'jack' => $jack, 'devices' => array_values($devices)];
            }
        }

        return new CablingMap($pairs, $unplugged, $addressed);
    }

    /**
     * Whether something retired cuts the path at the side of the panel: the
     * patched device, the panel or the jack.
     */
    private function cut(Device $device, PatchPanelPort $panelPort, Jack $jack): bool
    {
        return $device->status === Status::Retired
            || $panelPort->panel->status === Status::Retired
            || $jack->status === Status::Retired;
    }

    /**
     * Two ports cabled to each other, and how their links agree with it.
     *
     * @return array{a: Port, b: Port, panel_port: PatchPanelPort, jack: Jack, state: string, links: list<Link>}
     */
    private function pair(Port $patched, Port $plugged, PatchPanelPort $panelPort, Jack $jack): array
    {
        $one = $patched->linkEnd?->link;
        $other = $plugged->linkEnd?->link;

        [$state, $links] = match (true) {
            $one !== null && $other !== null && $one->is($other) => [$one->status === Status::Retired ? CablingMap::RETIRED : CablingMap::CONFIRMED, [$one]],
            $one !== null || $other !== null => [CablingMap::CONTRADICTED, array_values(array_filter([$one, $other]))],
            default => [CablingMap::UNLINKED, []],
        };

        return ['a' => $patched, 'b' => $plugged, 'panel_port' => $panelPort, 'jack' => $jack, 'state' => $state, 'links' => $links];
    }

    /**
     * Whether the port in front of a link can be plugged into a jack: it is
     * of the site of the jack, its device is not retired, and it has no
     * connector yet.
     */
    private function pluggable(Port $port, Jack $jack): bool
    {
        return $port->device->site_id === $jack->siteId()
            && $port->device->status !== Status::Retired
            && $port->patchCord === null
            && $port->jackCord === null;
    }

    /**
     * The device of an address plugged into a jack, when it enters a path:
     * neither the address nor the device is retired.
     */
    private function addressedDevice(IpAddress $address): ?Device
    {
        $device = $address->device;

        return $address->status === Status::Retired || $device === null || $device->status === Status::Retired
            ? null
            : $device;
    }
}
