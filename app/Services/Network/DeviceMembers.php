<?php

// torsade/app/Services/Network/DeviceMembers.php

namespace App\Services\Network;

use App\Enums\ActivityEvent;
use App\Enums\MemberRole;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\DeviceTemplate;
use App\Models\Port;
use App\Models\Room;
use App\Services\Network\Concerns\TranslatesUniqueViolations;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change to the members of a device goes through this class, whether
 * it comes from the interface or from a command: the rules are enforced
 * here and each change is written to the activity log.
 *
 * A member added with a template gets its ports in the same transaction,
 * copied from the template: changing the template afterwards, or the
 * template of the member, changes no port.
 *
 * The serial number of a device is the one of a machine made of one box.
 * When the device gets its first member, the number goes to that member
 * and leaves the device: it only exists in one place.
 *
 * Ports created or deleted in number are told in one entry of the log, on
 * their member, with their number and their names: the ports a member is
 * created with, those of a series added to it, those deleted with it.
 *
 * @phpstan-type MemberData array{position: int, role: string, device_template_id?: int|null, serial_number?: string|null, room_id?: int|null, description?: string|null}
 */
final class DeviceMembers
{
    use TranslatesUniqueViolations;

    public function __construct(
        private readonly ChangeLog $log,
        private readonly Ports $ports,
        private readonly Links $links,
        private readonly PatchCords $cords,
        private readonly JackCords $jackCords,
    ) {}

    /**
     * Add a member to a device and, with a template, create its ports.
     *
     * @param  MemberData  $data
     *
     * @throws ValidationException Messages are keyed by field.
     */
    public function add(Device $device, array $data, Actor $actor): DeviceMember
    {
        return DB::transaction(fn (): DeviceMember => $this->addTo($this->locked($device), $data, null, $actor));
    }

    /**
     * Give its first member to a device that has none, from a template:
     * the member 1, primary, with the ports of the template.
     *
     * @throws ValidationException
     */
    public function addFirst(Device $device, int $templateId, Actor $actor): DeviceMember
    {
        return DB::transaction(function () use ($device, $templateId, $actor): DeviceMember {
            $device = $this->locked($device);

            if ($device->members()->exists()) {
                throw ValidationException::withMessages([
                    'device_template_id' => __('This device already has members: add a member to it instead.'),
                ]);
            }

            return $this->addTo($device, [
                'position' => DeviceMember::MIN_POSITION,
                'role' => MemberRole::Primary->value,
                'device_template_id' => $templateId,
            ], null, $actor);
        });
    }

    /**
     * Add a series of ports to a member of a device. A device without a
     * member gets its first one with them: the member 1, primary.
     *
     * @param  int|null  $memberId  The member the ports are added to; null for a device without a member.
     * @param  array{name_pattern: string, first_number: int, last_number: int, connector: string, max_speed: string, poe_type: string, poe_label?: string|null}  $series
     * @return int How many ports were created.
     *
     * @throws ValidationException Messages are keyed by field.
     */
    public function addPorts(Device $device, ?int $memberId, array $series, Actor $actor): int
    {
        return DB::transaction(function () use ($device, $memberId, $series, $actor): int {
            $device = $this->locked($device);

            if ($memberId === null) {
                if ($device->members()->exists()) {
                    throw ValidationException::withMessages([
                        'device_member_id' => __('Choose the member the ports are added to.'),
                    ]);
                }

                // The member and its first ports are one entry of the
                // log.
                $member = $this->addTo($device, [
                    'position' => DeviceMember::MIN_POSITION,
                    'role' => MemberRole::Primary->value,
                ], $series, $actor);

                return $member->ports()->count();
            }

            $member = $device->members()->whereKey($memberId)->first();

            if ($member === null) {
                throw ValidationException::withMessages([
                    'device_member_id' => __('This member no longer exists.'),
                ]);
            }

            $member->setRelation('device', $device);

            $names = $this->ports->addSeries($member, $series);

            $this->log->noted(ActivityEvent::DeviceMemberPortsAdded, $member, [], Ports::logged($names), $actor);

            return count($names);
        });
    }

    /**
     * Change a member. Its ports stay as they are: a new number does not
     * rename them, and another template does not replace them.
     *
     * @param  MemberData  $data
     *
     * @throws ValidationException Messages are keyed by field.
     */
    public function update(DeviceMember $member, array $data, Actor $actor): void
    {
        DB::transaction(function () use ($member, $data, $actor): void {
            $member->setRelation('device', $this->locked($member->device));
            $member->loadMissing(['template', 'room.floor.building']);

            $before = $member->auditValues();

            $this->write($member, $data);

            $this->log->updated(ActivityEvent::DeviceMemberUpdated, $member, $before, $actor);
        });
    }

    /**
     * Delete a member with its ports. A member one of whose ports is
     * linked, patched or plugged is not deleted: its links and its cords
     * are, first.
     *
     * @return int How many ports were deleted with it.
     *
     * @throws ValidationException
     */
    public function delete(DeviceMember $member, Actor $actor): int
    {
        return DB::transaction(function () use ($member, $actor): int {
            $this->links->refuseDeletion(Port::query()->where('device_member_id', $member->id), 'member');
            $this->cords->refuseDeletion(Port::query()->where('device_member_id', $member->id), 'member');
            $this->jackCords->refuseDeletion(Port::query()->where('device_member_id', $member->id), 'member');

            $member->loadMissing(['device.site', 'template', 'room.floor.building']);

            $names = $this->ports->deleteOf($member);

            $this->log->deleted(ActivityEvent::DeviceMemberDeleted, $member, $actor, Ports::logged($names));

            $member->delete();

            return count($names);
        });
    }

    /**
     * Take the members of a device out of their rooms: the device moves to
     * another site, and a room never holds a machine of another site. To
     * be called in the transaction that moves the device.
     */
    public function leaveRooms(Device $device, Actor $actor): void
    {
        $members = $device->members()->whereNotNull('room_id')->with(['template', 'room.floor.building'])->get();

        foreach ($members as $member) {
            $member->setRelation('device', $device);

            $before = $member->auditValues();

            $member->room()->dissociate();
            $member->save();

            $this->log->updated(ActivityEvent::DeviceMemberUpdated, $member, $before, $actor);
        }
    }

    /**
     * Create a member of a device, with the ports of its template or, when
     * it has none, those of a first series, and write one entry of the log
     * for the member and its ports. To be called in a transaction that
     * holds the device.
     *
     * @param  MemberData  $data
     * @param  array{name_pattern: string, first_number: int, last_number: int, connector: string, max_speed: string, poe_type: string, poe_label?: string|null}|null  $series
     *
     * @throws ValidationException Messages are keyed by field.
     */
    private function addTo(Device $device, array $data, ?array $series, Actor $actor): DeviceMember
    {
        // The first member of a device takes its serial number, unless it
        // is given one of its own: the device then keeps what it has.
        $carried = $device->serial_number !== null
            && $this->blankToNull($data['serial_number'] ?? null) === null
            && ! $device->members()->exists();

        if ($carried) {
            $data['serial_number'] = $device->serial_number;
        }

        $member = new DeviceMember;
        $member->device()->associate($device);

        $template = $this->write($member, $data);

        $names = match (true) {
            $template !== null => $this->ports->generate($member, $template),
            $series !== null => $this->ports->addSeries($member, $series),
            default => [],
        };

        $this->log->created(ActivityEvent::DeviceMemberCreated, $member, $actor, Ports::logged($names));

        if ($carried) {
            $device->loadMissing('room.floor.building');

            $before = $device->auditValues();

            $device->serial_number = null;
            $device->save();

            $this->log->updated(ActivityEvent::DeviceUpdated, $device, $before, $actor);
        }

        return $member;
    }

    /**
     * The device as it is stored now, kept from other changes until the
     * end of the transaction: the rules of its members are checked on what
     * it holds, and two requests at the same time wait for each other.
     */
    private function locked(Device $device): Device
    {
        return Device::query()->with('site')->whereKey($device->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Check what depends on stored data, then save: the number is free in
     * the device, the device has one primary and one secondary at most,
     * the template exists, and the room is on the site of the device.
     *
     * @param  MemberData  $data
     * @return DeviceTemplate|null The template of a member that was just created, whose ports are to be copied.
     *
     * @throws ValidationException
     */
    private function write(DeviceMember $member, array $data): ?DeviceTemplate
    {
        $device = $member->device;
        $others = DeviceMember::query()->where('device_id', $device->id)->whereKeyNot($member->getKey());

        if ((clone $others)->where('position', $data['position'])->exists()) {
            throw ValidationException::withMessages([
                'position' => __('This device already has a member :position.', ['position' => $data['position']]),
            ]);
        }

        $role = MemberRole::from($data['role']);

        if ($role->isSingle()) {
            $holder = (clone $others)->where('role', $role->value)->first();

            if ($holder !== null) {
                throw ValidationException::withMessages([
                    'role' => $role === MemberRole::Primary
                        ? __('This device already has a primary member, the member :position: it has one at most.', ['position' => $holder->position])
                        : __('This device already has a secondary member, the member :position: it has one at most.', ['position' => $holder->position]),
                ]);
            }
        }

        $template = null;

        if (($data['device_template_id'] ?? null) !== null) {
            $template = DeviceTemplate::query()->with('portGroups')->whereKey($data['device_template_id'])->first();

            if ($template === null) {
                throw ValidationException::withMessages(['device_template_id' => __('This template no longer exists.')]);
            }
        }

        $room = null;

        if (($data['room_id'] ?? null) !== null) {
            $room = Room::query()->with('floor.building.site')->whereKey($data['room_id'])->first();

            if ($room === null) {
                throw ValidationException::withMessages(['room_id' => __('This room no longer exists.')]);
            }

            if ($room->siteId() !== $device->site_id) {
                throw ValidationException::withMessages([
                    'room_id' => __('The room :room belongs to the site :site: choose a room of the site of the device.', [
                        'room' => $room->path(),
                        'site' => $room->floor->building->site->name,
                    ]),
                ]);
            }
        }

        $created = ! $member->exists;

        $member->fill([
            'position' => $data['position'],
            'role' => $role,
            'serial_number' => $this->blankToNull($data['serial_number'] ?? null),
            'description' => $this->blankToNull($data['description'] ?? null),
        ]);

        $member->template()->associate($template);
        $member->room()->associate($room);

        $this->guardingUniqueness(fn (): bool => $member->save(), [
            'device_members_device_id_position_unique' => ['position', __('This device already has a member :position.', ['position' => $data['position']])],
        ]);

        return $created ? $template : null;
    }

    private function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }
}
