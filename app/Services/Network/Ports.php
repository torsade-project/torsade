<?php

// torsade/app/Services/Network/Ports.php

namespace App\Services\Network;

use App\Enums\ActivityEvent;
use App\Enums\PoeType;
use App\Enums\PortConnector;
use App\Enums\PortSpeed;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\DeviceTemplate;
use App\Models\Port;
use App\Services\Network\Concerns\TranslatesUniqueViolations;
use App\Support\Actor;
use App\Support\PortNaming;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change to the ports of a device goes through this class, whether it
 * comes from the interface or from a command: the rules are enforced here
 * and each change of a port is written to the activity log, one entry per
 * port, so that each port keeps its history.
 *
 * Ports created in number, from a template or as a series, and ports
 * deleted with their member, are told in one entry by what holds them:
 * App\Services\Network\DeviceMembers writes it, with their number and their
 * names.
 *
 * An action on several ports is done for all of them or for none: a refusal
 * names the ports in the way, ten at most.
 *
 * The mode of a port and the VLANs it carries follow the rules of
 * App\Services\Network\VlanCarriage. A port of an aggregate has neither:
 * they are those of the aggregate.
 *
 * @phpstan-import-type CarriageData from VlanCarriage
 *
 * @phpstan-type PortSpec array{name: string, connector: PortConnector|string, max_speed: PortSpeed|string, poe_type: PoeType|string, poe_label: string|null}
 * @phpstan-type SeriesData array{name_pattern: string, first_number: int, last_number: int, connector: string, max_speed: string, poe_type: string, poe_label?: string|null}
 * @phpstan-type PortData array{name: string, position: int, connector: string, max_speed: string, poe_type: string, poe_label?: string|null, enabled: bool, description?: string|null, mode?: string|null, untagged_vlan_id?: int|null, tagged_vlan_ids?: list<int>}
 */
final class Ports
{
    use TranslatesUniqueViolations;

    /**
     * How many ports a message names at most: the others are counted.
     */
    public const int NAMED = 10;

    public function __construct(
        private readonly ChangeLog $log,
        private readonly VlanCarriage $carriage,
        private readonly Links $links,
        private readonly PatchCords $cords,
        private readonly JackCords $jackCords,
    ) {}

    /**
     * Create the ports a template gives to a member: named by the patterns
     * of its groups with the number of the member, in the order of the
     * groups. To be called in the transaction that adds the member.
     *
     * @param  string  $field  The field a refusal is told on.
     * @return list<string> The names of the ports created, in their order.
     *
     * @throws ValidationException
     */
    public function generate(DeviceMember $member, DeviceTemplate $template, string $field = 'device_template_id'): array
    {
        $groups = array_values($template->portGroups->all());

        // A template is checked on the member 1: another number may bring
        // two of its patterns to the same name.
        $clashes = PortNaming::clashes(
            array_map(fn ($group): array => [
                'name_pattern' => $group->name_pattern,
                'first_number' => $group->first_number,
                'quantity' => $group->quantity,
            ], $groups),
            $member->position,
        );

        if ($clashes !== []) {
            throw ValidationException::withMessages([
                $field => __('For the member :position, this template gives the name :name twice.', [
                    'position' => $member->position,
                    'name' => array_values($clashes)[0]['name'],
                ]),
            ]);
        }

        $specs = [];

        foreach ($groups as $group) {
            foreach (PortNaming::names($group->name_pattern, $member->position, $group->first_number, $group->quantity) as $name) {
                $specs[] = [
                    'name' => $name,
                    'connector' => $group->connector,
                    'max_speed' => $group->max_speed,
                    'poe_type' => $group->poe_type,
                    'poe_label' => $group->poe_label,
                ];
            }
        }

        $taken = $this->taken($member->device_id, array_column($specs, 'name'));

        if ($taken !== []) {
            throw ValidationException::withMessages([
                $field => trans_choice(
                    'This template gives a name the device already has for a port: :names.|This template gives names the device already has for ports: :names.',
                    count($taken),
                    ['names' => self::named($taken)],
                ),
            ]);
        }

        return $this->create($member, $specs, $field, $field);
    }

    /**
     * Add a series of ports to a member, after those it has: named by a
     * pattern, from a first number to a last one. To be called in a
     * transaction that holds the device.
     *
     * @param  SeriesData  $data
     * @return list<string> The names of the ports created, in their order.
     *
     * @throws ValidationException Messages are keyed by field.
     */
    public function addSeries(DeviceMember $member, array $data): array
    {
        $quantity = $data['last_number'] - $data['first_number'] + 1;

        if ($quantity > PortNaming::MAX_QUANTITY) {
            throw ValidationException::withMessages([
                'last_number' => trans_choice(
                    'A series holds :count port at most.|A series holds :count ports at most.',
                    PortNaming::MAX_QUANTITY,
                ),
            ]);
        }

        $names = PortNaming::names($data['name_pattern'], $member->position, $data['first_number'], $quantity);

        // The last name bears the highest number: it is the longest.
        if (strlen($names[count($names) - 1]) > PortNaming::MAX_NAME_LENGTH) {
            throw ValidationException::withMessages([
                'name_pattern' => trans_choice(
                    'The names this pattern gives are longer than :count character.|The names this pattern gives are longer than :count characters.',
                    PortNaming::MAX_NAME_LENGTH,
                ),
            ]);
        }

        $taken = $this->taken($member->device_id, $names);

        if ($taken !== []) {
            throw ValidationException::withMessages([
                'name_pattern' => trans_choice(
                    'The device already has a port named :names.|The device already has ports named :names.',
                    count($taken),
                    ['names' => self::named($taken)],
                ),
            ]);
        }

        return $this->create(
            $member,
            array_map(fn (string $name): array => [
                'name' => $name,
                'connector' => $data['connector'],
                'max_speed' => $data['max_speed'],
                'poe_type' => $data['poe_type'],
                'poe_label' => $this->blankToNull($data['poe_label'] ?? null),
            ], $names),
            'name_pattern',
            // The order of the member is what has no room left.
            'device_member_id',
        );
    }

    /**
     * Change one port: what it is, its mode and the VLANs it carries. Its
     * name is free, and unique within its device.
     *
     * @param  PortData  $data
     *
     * @throws ValidationException Messages are keyed by field.
     */
    public function update(Port $port, array $data, Actor $actor): void
    {
        DB::transaction(function () use ($port, $data, $actor): void {
            $port->loadMissing(['member', 'device.site', 'aggregate', 'portVlans.vlan.site']);

            $before = $port->auditValues();

            $name = trim($data['name']);

            // The collation of the column does not tell capitals from
            // small letters: the refusal names the port as it is written.
            $other = Port::query()
                ->where('device_id', $port->device_id)
                ->where('name', $name)
                ->whereKeyNot($port->getKey())
                ->first();

            if ($other !== null) {
                throw ValidationException::withMessages([
                    'name' => __('The device already has a port named :name.', ['name' => $other->name]),
                ]);
            }

            $carriage = $this->carriage->resolve($port->device->site_id, $data);

            if ($port->aggregate !== null && $carriage['mode'] !== null) {
                throw ValidationException::withMessages([
                    'mode' => __('This port is part of the aggregate :name: its mode and its VLANs are those of the aggregate.', ['name' => $port->aggregate->name]),
                ]);
            }

            $port->fill([
                'name' => $name,
                'position' => $data['position'],
                'connector' => $data['connector'],
                'max_speed' => $data['max_speed'],
                'poe_type' => $data['poe_type'],
                'poe_label' => $this->blankToNull($data['poe_label'] ?? null),
                'enabled' => $data['enabled'],
                'description' => $this->blankToNull($data['description'] ?? null),
            ]);

            $this->guardingUniqueness(fn (): bool => $port->save(), [
                'ports_device_id_name_unique' => ['name', __('The device already has a port named :name.', ['name' => $name])],
            ]);

            if (! $this->carriage->matches($port, $carriage)) {
                $this->carriage->write($port, $carriage);
            }

            $this->log->updated(ActivityEvent::PortUpdated, $port, $before, $actor);
        });
    }

    /**
     * Delete one port, with the VLANs it carries. A linked, patched or
     * plugged port is not deleted: its link or its cord is, first.
     *
     * @throws ValidationException
     */
    public function delete(Port $port, Actor $actor): void
    {
        DB::transaction(function () use ($port, $actor): void {
            $this->links->refuseDeletion(Port::query()->whereKey($port->id), 'port');
            $this->cords->refuseDeletion(Port::query()->whereKey($port->id), 'port');
            $this->jackCords->refuseDeletion(Port::query()->whereKey($port->id), 'port');

            $port->loadMissing(['member', 'device.site', 'aggregate', 'portVlans.vlan.site']);

            $this->log->deleted(ActivityEvent::PortDeleted, $port, $actor);

            $port->delete();
        });
    }

    /**
     * Delete several ports of a device at once: all of them, or none. One
     * linked, patched or plugged port among them, and none is deleted.
     *
     * @param  list<int>  $ids
     * @return int How many ports were deleted.
     *
     * @throws ValidationException
     */
    public function deleteMany(Device $device, array $ids, Actor $actor): int
    {
        return DB::transaction(function () use ($device, $ids, $actor): int {
            $ports = $this->selected($device, $ids);

            $this->links->refuseDeletion(Port::query()->whereKey($ports->modelKeys()), 'ports');
            $this->cords->refuseDeletion(Port::query()->whereKey($ports->modelKeys()), 'ports');
            $this->jackCords->refuseDeletion(Port::query()->whereKey($ports->modelKeys()), 'ports');

            foreach ($ports as $port) {
                $this->log->deleted(ActivityEvent::PortDeleted, $port, $actor);
            }

            Port::query()->whereKey($ports->modelKeys())->delete();

            return $ports->count();
        });
    }

    /**
     * Enable or disable several ports of a device at once. A port that is
     * already in the state asked for is left as it is.
     *
     * @param  list<int>  $ids
     * @return int How many ports changed.
     *
     * @throws ValidationException
     */
    public function setEnabled(Device $device, array $ids, bool $enabled, Actor $actor): int
    {
        return DB::transaction(function () use ($device, $ids, $enabled, $actor): int {
            $changed = 0;

            foreach ($this->selected($device, $ids) as $port) {
                if ($port->enabled === $enabled) {
                    continue;
                }

                $before = $port->auditValues();

                $port->enabled = $enabled;
                $port->save();

                $this->log->updated(ActivityEvent::PortUpdated, $port, $before, $actor);

                $changed++;
            }

            return $changed;
        });
    }

    /**
     * Give the same mode and the same VLANs to several ports of a device
     * at once. A port that already has them is left as it is. Refused for
     * all of them when one is part of an aggregate.
     *
     * @param  list<int>  $ids
     * @param  CarriageData  $data
     * @return int How many ports changed.
     *
     * @throws ValidationException
     */
    public function setMode(Device $device, array $ids, array $data, Actor $actor): int
    {
        return DB::transaction(function () use ($device, $ids, $data, $actor): int {
            $ports = $this->selected($device, $ids);

            $carriage = $this->carriage->resolve($device->site_id, $data);

            $aggregated = array_values($ports
                ->filter(fn (Port $port): bool => $port->aggregate !== null)
                ->map(fn (Port $port): string => $port->name)
                ->all());

            if ($aggregated !== []) {
                throw ValidationException::withMessages([
                    'ports' => trans_choice(
                        'The port :names is part of an aggregate: its mode and its VLANs are those of the aggregate.|The ports :names are part of an aggregate: their mode and their VLANs are those of the aggregate.',
                        count($aggregated),
                        ['names' => self::named($aggregated)],
                    ),
                ]);
            }

            $changed = 0;

            foreach ($ports as $port) {
                if ($this->carriage->matches($port, $carriage)) {
                    continue;
                }

                $before = $port->auditValues();

                $this->carriage->write($port, $carriage);

                $this->log->updated(ActivityEvent::PortUpdated, $port, $before, $actor);

                $changed++;
            }

            return $changed;
        });
    }

    /**
     * Take their mode and their VLANs away from several ports of a device
     * at once. A port without a mode is left as it is.
     *
     * @param  list<int>  $ids
     * @return int How many ports changed.
     *
     * @throws ValidationException
     */
    public function clearMode(Device $device, array $ids, Actor $actor): int
    {
        return DB::transaction(function () use ($device, $ids, $actor): int {
            $changed = 0;

            foreach ($this->selected($device, $ids) as $port) {
                if ($port->mode === null && $port->portVlans->isEmpty()) {
                    continue;
                }

                $before = $port->auditValues();

                $this->carriage->clear($port);

                $this->log->updated(ActivityEvent::PortUpdated, $port, $before, $actor);

                $changed++;
            }

            return $changed;
        });
    }

    /**
     * Delete every port of a member, with the VLANs they carry. To be
     * called in the transaction that deletes the member, which tells them
     * in its own entry of the activity log.
     *
     * @return list<string> The names of the ports deleted, in their order.
     */
    public function deleteOf(DeviceMember $member): array
    {
        /** @var list<string> $names */
        $names = $member->ports()->pluck('name')->all();

        // The VLANs the ports carry go with them.
        $member->ports()->delete();

        return $names;
    }

    /**
     * Ports created or deleted in number, as the entry of what holds them
     * tells them in the activity log: how many, and their names. Nothing
     * when there is none.
     *
     * @param  list<string>  $names
     * @return array<string, scalar|null>
     */
    public static function logged(array $names): array
    {
        return $names === [] ? [] : ['ports_count' => count($names), 'ports' => implode(', ', $names)];
    }

    /**
     * Names of ports in a sentence: ten of them at most, then how many are
     * left out.
     *
     * @param  list<string>  $names
     */
    public static function named(array $names): string
    {
        $shown = implode(', ', array_slice($names, 0, self::NAMED));
        $more = count($names) - self::NAMED;

        return $more > 0
            ? __(':names and :count more', ['names' => $shown, 'count' => $more])
            : $shown;
    }

    /**
     * The ports an action is asked for, in the order of the device, kept
     * from other changes until the end of the transaction. All of them
     * belong to the device, or the action is refused: the page that sent
     * them no longer shows the device as it is.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Port>
     *
     * @throws ValidationException
     */
    private function selected(Device $device, array $ids): Collection
    {
        $device->loadMissing('site');

        $ports = Port::query()
            ->where('device_id', $device->id)
            ->whereKey($ids)
            ->with(['member', 'aggregate', 'portVlans.vlan.site'])
            ->lockForUpdate()
            ->get();

        if ($ports->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'ports' => __('Some of these ports no longer exist: reload the page.'),
            ]);
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
     * The names, among those given, that ports of the device already have,
     * as the device writes them. The database compares them: it is the one
     * that keeps the names unique, whatever their case.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function taken(int $deviceId, array $names): array
    {
        if ($names === []) {
            return [];
        }

        /** @var list<string> $found */
        $found = Port::query()
            ->where('device_id', $deviceId)
            ->whereIn('name', $names)
            ->pluck('name')
            ->all();

        natcasesort($found);

        return array_values($found);
    }

    /**
     * Write ports after those the member already has.
     *
     * The order of a member stops at Port::MAX_POSITION, the last place a
     * port can be given in its window. Ports that would go past it are
     * refused, all of them, before any is written: stored beyond it, they
     * could no longer be saved from their own window, and the column
     * itself would end up refusing them.
     *
     * @param  list<PortSpec>  $specs
     * @param  string  $field  The field a name that is already taken is told on.
     * @param  string  $placeField  The field a member without enough room left is told on.
     * @return list<string> The names of the ports written, in their order.
     *
     * @throws ValidationException
     */
    private function create(DeviceMember $member, array $specs, string $field, string $placeField): array
    {
        $position = (int) $member->ports()->max('position');

        if ($position + count($specs) > Port::MAX_POSITION) {
            throw ValidationException::withMessages([
                $placeField => trans_choice(
                    'The ports of this member are ordered up to :max at most, and its last port has the place :last: there is not enough room left for :count port. Give a lower place to its last ports first.|The ports of this member are ordered up to :max at most, and its last port has the place :last: there is not enough room left for :count ports. Give a lower place to its last ports first.',
                    count($specs),
                    ['max' => Port::MAX_POSITION, 'last' => $position],
                ),
            ]);
        }

        foreach ($specs as $spec) {
            $port = new Port([
                'name' => $spec['name'],
                'position' => ++$position,
                'connector' => $spec['connector'],
                'max_speed' => $spec['max_speed'],
                'poe_type' => $spec['poe_type'],
                'poe_label' => $spec['poe_label'],
            ]);

            $port->member()->associate($member);

            // Two requests at the same time: the database refuses the
            // second one.
            $this->guardingUniqueness(fn (): bool => $port->save(), [
                'ports_device_id_name_unique' => [$field, __('The device already has a port named :name.', ['name' => $spec['name']])],
            ]);
        }

        return array_column($specs, 'name');
    }

    private function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }
}
