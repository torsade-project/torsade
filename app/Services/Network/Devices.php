<?php

// torsade/app/Services/Network/Devices.php

namespace App\Services\Network;

use App\Enums\ActivityEvent;
use App\Models\Device;
use App\Models\Port;
use App\Models\Room;
use App\Models\Site;
use App\Services\Network\Concerns\PhrasesMessages;
use App\Services\Network\Concerns\TranslatesUniqueViolations;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change to a device goes through this class, whether it comes from
 * the interface or from a command: the rules are enforced here and each
 * change is written to the activity log.
 *
 * The addresses of a device are attached and detached by
 * App\Services\Network\IpAddresses: they are changes to those addresses.
 *
 * @phpstan-type DeviceData array{site_id: int, room_id?: int|null, name: string, type: string, manufacturer?: string|null, model?: string|null, serial_number?: string|null, status?: string|null, description?: string|null}
 */
final class Devices
{
    use PhrasesMessages;
    use TranslatesUniqueViolations;

    public function __construct(
        private readonly ChangeLog $log,
        private readonly DeviceMembers $members,
        private readonly Links $links,
        private readonly PatchCords $cords,
        private readonly JackCords $jackCords,
    ) {}

    /**
     * @param  DeviceData  $data
     *
     * @throws ValidationException Messages are keyed by field.
     */
    public function create(array $data, Actor $actor): Device
    {
        return DB::transaction(function () use ($data, $actor): Device {
            $device = new Device;

            $this->write($device, $data, $data['room_id'] ?? null);

            $this->log->created(ActivityEvent::DeviceCreated, $device, $actor);

            return $device;
        });
    }

    /**
     * Change a device. Moved to another site, it leaves its room, unless a
     * room of the new site is given with the change, and its members leave
     * theirs. Its ports keep the VLANs they carry, whatever their site, and
     * its links, which may join two sites; a patched or plugged device does
     * not move.
     *
     * @param  DeviceData  $data
     *
     * @throws ValidationException Messages are keyed by field.
     */
    public function update(Device $device, array $data, Actor $actor): void
    {
        DB::transaction(function () use ($device, $data, $actor): void {
            $device->loadMissing(['site', 'room.floor.building']);

            $before = $device->auditValues();

            $roomId = array_key_exists('room_id', $data) ? $data['room_id'] : $device->room_id;
            $moved = $data['site_id'] !== $device->site_id;

            // A cord does not join two sites: a patched or plugged device
            // stays.
            if ($moved) {
                $this->refuseMove($device, 'site_id');
            }

            // The room it was in belongs to the site it leaves.
            if ($moved && $roomId === $device->room_id) {
                $roomId = null;
            }

            $this->write($device, $data, $roomId);

            $this->log->updated(ActivityEvent::DeviceUpdated, $device, $before, $actor);

            if ($moved) {
                $this->members->leaveRooms($device, $actor);
            }
        });
    }

    /**
     * A device is only deleted once no address is attached to it: an
     * address never loses its device without anyone deciding so. Nor is it
     * deleted while one of its ports is linked, patched or plugged into a
     * jack: a link or a cord never loses an end that way either. Its
     * aggregates, its members and their ports are deleted with it.
     *
     * @throws ValidationException
     */
    public function delete(Device $device, Actor $actor): void
    {
        DB::transaction(function () use ($device, $actor): void {
            $addresses = $device->ipAddresses()->lockForUpdate()->count();

            if ($addresses > 0) {
                throw ValidationException::withMessages([
                    'device' => trans_choice(
                        'An IP address is still attached to this device: detach it first.|:count IP addresses are still attached to this device: detach them first.',
                        $addresses,
                    ),
                ]);
            }

            $this->links->refuseDeletion(Port::query()->where('device_id', $device->id), 'links');
            $this->cords->refuseDeletion(Port::query()->where('device_id', $device->id), 'cords');
            $this->jackCords->refuseDeletion(Port::query()->where('device_id', $device->id), 'jack_cords');

            $device->loadMissing(['site', 'room.floor.building']);

            // One entry for the device and everything that goes with it:
            // its members, its aggregates and its ports, by their names.
            $this->log->deleted(ActivityEvent::DeviceDeleted, $device, $actor, $this->held($device));

            // The database deletes its members, their ports, its aggregates
            // and the VLANs they carry with it.
            $device->delete();
        });
    }

    /**
     * Refuse to move a device to another site while one of its ports has a
     * cord, patch cord or jack cord: neither joins two sites. The refusal
     * names each cord of both kinds, ten at most, and says what to do with
     * them. To be called in the transaction that moves the device, before
     * anything is written.
     *
     * @throws ValidationException
     */
    private function refuseMove(Device $device, string $field): void
    {
        $patched = $this->cords->patchedAmong(Port::query()->where('device_id', $device->id));
        $plugged = $this->jackCords->pluggedAmong(Port::query()->where('device_id', $device->id));
        $cords = [...$patched, ...$plugged];

        if ($cords === []) {
            return;
        }

        $values = ['cords' => $this->enumerate($cords)];

        throw ValidationException::withMessages([
            $field => match (true) {
                $plugged === [] => trans_choice(':cords: unpatch it before moving the device to another site.|:cords: unpatch them before moving the device to another site.', count($cords), $values),
                $patched === [] => trans_choice(':cords: unplug it before moving the device to another site.|:cords: unplug them before moving the device to another site.', count($cords), $values),
                default => __(':cords: unpatch and unplug them before moving the device to another site.', $values),
            },
        ]);
    }

    /**
     * Check what depends on stored data, then save: the site and the room
     * exist, the room is on the site of the device, and no other device of
     * the site has the name.
     *
     * The serial number of a device is the one of a machine made of one
     * box. Once the device has members, each of them has its own: the
     * number of the device is no longer asked, and stays as it was.
     *
     * @param  DeviceData  $data
     *
     * @throws ValidationException
     */
    private function write(Device $device, array $data, ?int $roomId): void
    {
        $site = Site::query()->find($data['site_id']);

        if ($site === null) {
            throw ValidationException::withMessages(['site_id' => __('This site no longer exists.')]);
        }

        $room = null;

        if ($roomId !== null) {
            $room = Room::query()->with('floor.building.site')->find($roomId);

            if ($room === null) {
                throw ValidationException::withMessages(['room_id' => __('This room no longer exists.')]);
            }

            if ($room->siteId() !== $site->id) {
                throw ValidationException::withMessages([
                    'room_id' => __('The room :room belongs to the site :site: choose a room of the site of the device.', [
                        'room' => $room->path(),
                        'site' => $room->floor->building->site->name,
                    ]),
                ]);
            }
        }

        // The collation of the column does not tell capitals from small
        // letters: "SW-01" is found when "sw-01" is asked for. The refusal
        // names the device as it is written.
        $other = Device::query()
            ->where('site_id', $site->id)
            ->where('name', $data['name'])
            ->whereKeyNot($device->getKey())
            ->first();

        if ($other !== null) {
            throw ValidationException::withMessages([
                'name' => __('The site :site already has a device named :name.', ['site' => $site->name, 'name' => $other->name]),
            ]);
        }

        $device->fill([
            'name' => $data['name'],
            'type' => $data['type'],
            'manufacturer' => $this->blankToNull($data['manufacturer'] ?? null),
            'model' => $this->blankToNull($data['model'] ?? null),
            'serial_number' => $device->exists && $device->members()->exists()
                ? $device->serial_number
                : $this->blankToNull($data['serial_number'] ?? null),
            'status' => $data['status'] ?? $device->status->value,
            'description' => $this->blankToNull($data['description'] ?? null),
        ]);

        $device->site()->associate($site);
        $device->room()->associate($room);

        $this->guardingUniqueness(fn (): bool => $device->save(), [
            'devices_site_id_name_unique' => ['name', __('Another device of this site already has this name.')],
        ]);
    }

    /**
     * What a device is made of, as the entry of its deletion tells it: the
     * numbers of its members, the names of its aggregates, and its ports,
     * member by member, with their number. Nothing for a device without a
     * member nor an aggregate.
     *
     * @return array<string, scalar|null>
     */
    private function held(Device $device): array
    {
        $members = $device->members()->pluck('position')->all();
        $aggregates = $device->aggregates()->pluck('name')->all();

        /** @var list<string> $ports */
        $ports = Port::query()
            ->join('device_members', 'device_members.id', '=', 'ports.device_member_id')
            ->where('ports.device_id', $device->id)
            ->orderBy('device_members.position')
            ->orderBy('ports.position')
            ->orderBy('ports.id')
            ->pluck('ports.name')
            ->all();

        return [
            ...($members === [] ? [] : ['members' => implode(', ', $members)]),
            ...($aggregates === [] ? [] : ['aggregates' => implode(', ', $aggregates)]),
            ...Ports::logged($ports),
        ];
    }

    private function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }
}
