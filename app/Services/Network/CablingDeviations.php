<?php

// torsade/app/Services/Network/CablingDeviations.php

namespace App\Services\Network;

use App\Enums\DeviationKind;
use App\Enums\PortMode;
use App\Enums\Status;
use App\Models\IpAddress;
use App\Models\Jack;
use App\Models\PatchCord;
use App\Models\PatchPanelPort;
use App\Models\Port;
use App\Models\Room;
use App\Models\Vlan;

/**
 * Where the cabling differs from what is declared around it: a cord between
 * a panel and a device kept in two different rooms; an active address
 * plugged into a jack that is not patched; an address, active or planned,
 * whose VLAN the port at the end of its jack does not carry. What a cabled
 * path tells about links is told by App\Services\Network\CablingMap.
 *
 * Nothing here is stored and nothing is refused: each deviation only makes
 * an imperfection visible, with its reason in words. The methods work on
 * objects already read with what they say they need, and never query.
 *
 * The VLAN of an address is the VLAN of its subnet. The port at the end of
 * a jack carries it in access as its VLAN or its voice VLAN, in trunk as its
 * native VLAN or a tagged one; for a port of an aggregate, the mode and the
 * VLANs of the aggregate count. A port without a mode, the port of a server
 * or of a firewall, is not compared. The VLAN itself is compared, not its
 * number: the VLAN of the same number of another site is another network.
 *
 * @phpstan-type Deviation array{kind: string, reason: string}
 */
final class CablingDeviations
{
    /**
     * What a port of a panel must be loaded with, besides its panel with
     * the room of the panel: its jack with the addresses plugged into it,
     * each with the VLAN of its subnet, and its cord with the port at the
     * other end.
     *
     * @var list<string>
     */
    public const array PANEL_PORT = [
        'jack.ipAddresses.subnet.vlan.site',
        'cord.port.device.room.floor.building',
        'cord.port.member.room.floor.building',
        'cord.port.aggregate.portVlans.vlan.site',
        'cord.port.portVlans.vlan.site',
    ];

    /**
     * What a jack must be loaded with: its port of a panel, the cord of that
     * port with the port at the other end, and the addresses plugged into
     * it, each with the VLAN of its subnet.
     *
     * @var list<string>
     */
    public const array JACK = [
        'panelPort.panel',
        'panelPort.cord.port.device',
        'panelPort.cord.port.aggregate.portVlans.vlan.site',
        'panelPort.cord.port.portVlans.vlan.site',
        'ipAddresses.subnet.vlan.site',
    ];

    /**
     * What a port of a device must be loaded with, besides its device with
     * its room: its member with its room, its link, and its cord with the
     * port of the panel, the room of the panel and the jack, and the
     * addresses plugged into that jack, each with the VLAN of its subnet.
     * The link is read for the table of ports, which tells what each port
     * faces.
     *
     * @var list<string>
     */
    public const array PORT = [
        'member.room.floor.building',
        'linkEnd.link.ends.port.device',
        'aggregate.portVlans.vlan.site',
        'portVlans.vlan.site',
        'patchCord.panelPort.panel.room.floor.building',
        'patchCord.panelPort.jack.ipAddresses.subnet.vlan.site',
    ];

    /**
     * The deviations of a port of a panel: those of its cord, of its jack
     * and of the addresses plugged into that jack.
     *
     * @return list<Deviation>
     */
    public function ofPanelPort(PatchPanelPort $panelPort): array
    {
        $cord = $panelPort->cord;
        $jack = $panelPort->jack;

        $deviations = [];

        if ($cord !== null) {
            $cord->setRelation('panelPort', $panelPort);
            $deviations = $this->ofCord($cord);
        }

        if ($jack !== null) {
            $jack->setRelation('panelPort', $panelPort);

            $deviations = [...$deviations, ...$this->ofJackAddresses($jack)];
        }

        return $deviations;
    }

    /**
     * The deviations of a jack: an address plugged into it is not served as
     * declared.
     *
     * @return list<Deviation>
     */
    public function ofJack(Jack $jack): array
    {
        return $this->ofJackAddresses($jack);
    }

    /**
     * The deviations of an address due to its jack: an active address on a
     * jack that is not patched, or an address whose VLAN the port at the end
     * of the jack does not carry. Its subnet with its VLAN must be loaded,
     * and its jack with the port of a panel, the cord of that port and the
     * port at the other end.
     *
     * @return list<Deviation>
     */
    public function ofAddress(IpAddress $address): array
    {
        $jack = $address->jack;

        if ($jack === null) {
            return [];
        }

        $port = $jack->panelPort?->cord?->port;

        if ($port === null) {
            return $address->status === Status::Active ? [$this->unpatched($jack)] : [];
        }

        $deviation = $this->vlanNotCarried($address, $port);

        return $deviation === null ? [] : [$deviation];
    }

    /**
     * The deviations of a port of a device: its cord leads to a panel in
     * another room, or an address plugged into the jack of its panel port
     * is of a VLAN it does not carry.
     *
     * @return list<Deviation>
     */
    public function ofPort(Port $port): array
    {
        $cord = $port->patchCord;

        if ($cord === null) {
            return [];
        }

        $cord->setRelation('port', $port);
        $jack = $cord->panelPort->jack;

        $deviations = $this->ofCord($cord);

        if ($jack !== null) {
            foreach ($jack->ipAddresses as $address) {
                $deviation = $this->vlanNotCarried($address, $port);

                if ($deviation !== null) {
                    $deviations[] = $deviation;
                }
            }
        }

        return $deviations;
    }

    /**
     * A cord between a panel and a device kept in two different rooms: the
     * room of the member of the port, or else of its device, against the
     * room of the panel. A device kept in no room is not compared. Each
     * room is named by what tells it from the other (see apart()).
     *
     * @return list<Deviation>
     */
    private function ofCord(PatchCord $cord): array
    {
        $port = $cord->port;
        $panelRoom = $cord->panelPort->panel->room;
        $deviceRoom = $port->member->room ?? $port->device->room;

        if (! $deviceRoom instanceof Room || $deviceRoom->id === $panelRoom->id) {
            return [];
        }

        [$panel, $room] = self::apart($panelRoom, $deviceRoom);

        return [$this->deviation(
            DeviationKind::CordAcrossRooms,
            __('Panel in :panel, :device in :room', [
                'panel' => $panel,
                'device' => $port->device->name,
                'room' => $room,
            ]),
        )];
    }

    /**
     * Two rooms of a site named by their paths without what the two have in
     * common: the building when it is the same, then the floor when it is
     * the same too. "Local technique" and "Bureaux" on one floor;
     * "Rez-de-chaussée › Local technique" and "1er étage › Placard
     * technique" in one building; the whole paths in two buildings. Their
     * floors and buildings must be loaded.
     *
     * @return array{0: string, 1: string}
     */
    private static function apart(Room $one, Room $other): array
    {
        $steps = fn (Room $room): array => [$room->floor->building->name, $room->floor->name, $room->name];

        $common = match (true) {
            $one->floor_id === $other->floor_id => 2,
            $one->floor->building_id === $other->floor->building_id => 1,
            default => 0,
        };

        return [
            implode(' › ', array_slice($steps($one), $common)),
            implode(' › ', array_slice($steps($other), $common)),
        ];
    }

    /**
     * The addresses plugged into a jack that are not served as declared:
     * one deviation for a jack not patched, whatever the number of active
     * addresses plugged into it, and one per address whose VLAN is not
     * carried.
     *
     * @return list<Deviation>
     */
    private function ofJackAddresses(Jack $jack): array
    {
        $port = $jack->panelPort?->cord?->port;

        if ($port === null) {
            return $jack->ipAddresses->contains(fn (IpAddress $address): bool => $address->status === Status::Active)
                ? [$this->unpatched($jack)]
                : [];
        }

        $deviations = [];

        foreach ($jack->ipAddresses as $address) {
            $deviation = $this->vlanNotCarried($address, $port);

            if ($deviation !== null) {
                $deviations[] = $deviation;
            }
        }

        return $deviations;
    }

    /**
     * @return Deviation
     */
    private function unpatched(Jack $jack): array
    {
        return $this->deviation(DeviationKind::AddressOnUnpatchedJack, __('Jack :jack not patched', ['jack' => $jack->label]));
    }

    /**
     * An address whose VLAN the port at the end of its jack does not carry,
     * or null when it is carried, or when there is nothing to compare: a
     * subnet without a VLAN, a port without a mode. An address that is
     * retired is not compared either: it is only kept for the record,
     * while one that is planned on the wrong port is a mistake of design.
     *
     * When the port carries another VLAN of the same number, the VLANs are
     * named with their site: their numbers alone would not tell them apart.
     *
     * @return Deviation|null
     */
    private function vlanNotCarried(IpAddress $address, Port $port): ?array
    {
        $vlan = $address->subnet->vlan;
        $holder = $port->aggregate ?? $port;

        if ($vlan === null || $holder->mode === null || $address->status === Status::Retired) {
            return null;
        }

        $untagged = $holder->untaggedVlan();
        $carried = [...($untagged === null ? [] : [$untagged]), ...$holder->taggedVlans()];

        if (array_any($carried, fn (Vlan $one): bool => $one->id === $vlan->id)) {
            return null;
        }

        $homonym = array_any($carried, fn (Vlan $one): bool => $one->vid === $vlan->vid);
        $name = fn (Vlan $one): string => $homonym
            ? __(':vid of the site :site', ['vid' => $one->vid, 'site' => $one->site->name])
            : (string) $one->vid;

        $values = [
            'port' => $port->device->name.' '.($port->aggregate === null ? $port->name : $port->aggregate->name),
            'address' => $address->address,
            'vid' => $name($vlan),
            'carried' => $untagged === null ? null : $name($untagged),
        ];

        return $this->deviation(DeviationKind::AddressVlanNotCarried, match (true) {
            $holder->mode === PortMode::Trunk => __(':port in trunk without VLAN :vid, the VLAN of the address :address', $values),
            $untagged === null => __(':port in access without a VLAN, address :address of VLAN :vid', $values),
            default => __(':port in access on VLAN :carried, address :address of VLAN :vid', $values),
        });
    }

    /**
     * @return Deviation
     */
    private function deviation(DeviationKind $kind, string $reason): array
    {
        return ['kind' => $kind->value, 'reason' => $reason];
    }
}
