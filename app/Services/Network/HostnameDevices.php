<?php

// torsade/app/Services/Network/HostnameDevices.php

namespace App\Services\Network;

use App\Enums\DeviceType;
use App\Enums\Status;
use App\Models\Device;
use App\Models\IpAddress;
use App\Models\Site;
use App\Support\Actor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The devices of a site created from what is already documented: the host
 * names of its addresses. Each distinct name becomes a device, and the
 * addresses that bear it become its interfaces. It is how a network
 * documented address by address moves to devices.
 *
 * Nothing is decided here that the services of devices and addresses do
 * not decide: each device is created and each address attached through
 * them, and logged by them. All the lines are done, or none. A host name
 * is never changed.
 *
 * @phpstan-type Candidate array{hostname: string, addresses: list<IpAddress>, type: DeviceType, status: Status, device: Device|null}
 * @phpstan-type HostnameLine array{hostname: string, type: string, status: string}
 */
final class HostnameDevices
{
    /**
     * How many host names can be turned into devices at once.
     */
    public const int MAX_LINES = 500;

    public function __construct(
        private readonly Devices $devices,
        private readonly IpAddresses $addresses,
    ) {}

    /**
     * The distinct host names of the addresses of the site that belong to
     * no device, each with its addresses, the type and the status proposed
     * for the device and, when the site already has a device of that name,
     * that device: the addresses are then attached to it.
     *
     * Two host names are the same, and a host name is the name of a device,
     * as the names of devices are told apart: by the collation of the
     * database, without regard to case or accents. The database compares
     * them, in the query itself: each address comes with the first address
     * of its name, and with the device of the site of that name.
     *
     * The number of queries does not depend on what the site holds.
     *
     * @return array<string, Candidate> By host name as the first of its addresses writes it, in the order of the names.
     */
    public function candidatesFor(Site $site, bool $lock = false): array
    {
        $ofTheSite = fn (Builder $query) => $query->where('site_id', $site->id);

        /** @var Collection<int, IpAddress> $addresses */
        $addresses = IpAddress::query()
            ->select('ip_addresses.*')
            // The lowest address that bears the same name.
            ->selectSub(
                IpAddress::query()
                    ->from('ip_addresses', 'peers')
                    ->select('peers.id')
                    ->join('subnets as peer_subnets', 'peer_subnets.id', '=', 'peers.subnet_id')
                    ->where('peer_subnets.site_id', $site->id)
                    ->whereNull('peers.device_id')
                    ->whereColumn('peers.hostname', 'ip_addresses.hostname')
                    ->orderBy('peers.address')
                    ->orderBy('peers.id')
                    ->limit(1),
                'first_of_its_name',
            )
            // The device of the site that bears that name.
            ->selectSub(
                Device::query()
                    ->select('devices.id')
                    ->where('devices.site_id', $site->id)
                    ->whereColumn('devices.name', 'ip_addresses.hostname')
                    ->limit(1),
                'device_of_its_name',
            )
            ->whereNull('ip_addresses.device_id')
            ->whereNotNull('ip_addresses.hostname')
            ->whereHas('subnet', $ofTheSite)
            ->with('subnet')
            ->orderBy('ip_addresses.address')
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())
            ->get();

        $existing = $site->devices()->get()
            ->each(fn (Device $device) => $device->setRelation('site', $site))
            ->keyBy('id');

        $groups = [];

        foreach ($addresses as $address) {
            $groups[(int) $address->getAttribute('first_of_its_name')][] = $address;
        }

        $candidates = [];

        foreach ($groups as $group) {
            // As the lowest of its addresses writes it.
            $hostname = (string) $group[0]->hostname;
            $device = $group[0]->getAttribute('device_of_its_name');

            $candidates[$hostname] = [
                'hostname' => $hostname,
                'addresses' => $group,
                'type' => $this->proposedType($group),
                'status' => $this->proposedStatus($site, $group),
                'device' => $device === null ? null : $existing->get((int) $device),
            ];
        }

        uksort($candidates, strnatcasecmp(...));

        return $candidates;
    }

    /**
     * Create a device for each line, or find the one the site already has
     * under that name, and attach to it the addresses that bear the name.
     *
     * @param  array<int, HostnameLine>  $lines  The host names chosen, by line.
     * @return array{devices: int, attachments: int, addresses: int} Devices created, lines attached to a device already there, addresses attached.
     *
     * @throws ValidationException Messages are keyed "lines.<line>.<field>".
     */
    public function apply(Site $site, array $lines, Actor $actor): array
    {
        return DB::transaction(function () use ($site, $lines, $actor): array {
            // The site is locked: two people doing this at the same time
            // do not both pass.
            Site::query()->whereKey($site->getKey())->lockForUpdate()->firstOrFail();

            $candidates = $this->candidatesFor($site, lock: true);

            // The name of each address, to find the name of a line.
            $nameOf = [];

            foreach ($candidates as $key => $candidate) {
                foreach ($candidate['addresses'] as $address) {
                    $nameOf[$address->id] = $key;
                }
            }

            // The host name of a line may come back written another way:
            // the database tells which name it is, comparing it as it
            // compares the names of devices. Each line is read before any
            // of them is done.
            $keys = array_map(
                fn (array $data): ?string => $nameOf[$this->addressNamed($site, $data['hostname'])] ?? null,
                $lines,
            );

            $errors = [];
            $done = ['devices' => 0, 'attachments' => 0, 'addresses' => 0];
            $seen = [];

            foreach ($lines as $line => $data) {
                $key = $keys[$line];
                $candidate = $key === null ? null : $candidates[$key];

                if ($key !== null && isset($seen[$key])) {
                    $errors['lines.'.$line.'.hostname'] = __('This host name appears twice in this entry.');

                    continue;
                }

                if ($key !== null) {
                    $seen[$key] = true;
                }

                if ($candidate === null) {
                    $errors['lines.'.$line.'.hostname'] = __('No address of this site without a device bears this host name any more.');

                    continue;
                }

                try {
                    $device = $candidate['device'];

                    if ($device === null) {
                        $device = $this->createDevice($site, $candidate['hostname'], $data['type'], $data['status'], $actor);
                        $done['devices']++;
                    } else {
                        $done['attachments']++;
                    }

                    foreach ($candidate['addresses'] as $address) {
                        $this->addresses->attach($address, $device, $actor, 'hostname');
                        $done['addresses']++;
                    }
                } catch (ValidationException $exception) {
                    // The others are still tried: every refusal is told at
                    // once, each on its line.
                    foreach ($exception->errors() as $field => $messages) {
                        $errors['lines.'.$line.'.'.(in_array($field, ['type', 'status'], true) ? $field : 'hostname')] = $messages[0];
                    }
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            return $done;
        });
    }

    /**
     * An address of the site that belongs to no device and bears the host
     * name, compared by the database; 0 when there is none.
     */
    private function addressNamed(Site $site, string $hostname): int
    {
        return (int) IpAddress::query()
            ->whereNull('device_id')
            ->where('hostname', $hostname)
            ->whereHas('subnet', fn (Builder $query) => $query->where('site_id', $site->id))
            ->value('id');
    }

    /**
     * @throws ValidationException
     */
    private function createDevice(Site $site, string $hostname, string $type, string $status, Actor $actor): Device
    {
        // A host name may be longer than the name of a device.
        if (mb_strlen($hostname) > Device::MAX_NAME_LENGTH) {
            throw ValidationException::withMessages([
                'hostname' => trans_choice(
                    'This host name is longer than the :count character of the name of a device: create the device by hand.|This host name is longer than the :count characters of the name of a device: create the device by hand.',
                    Device::MAX_NAME_LENGTH,
                ),
            ]);
        }

        // Without a room: nothing of an address says where the machine is.
        return $this->devices->create(['site_id' => $site->id, 'name' => $hostname, 'type' => $type, 'status' => $status], $actor);
    }

    /**
     * The status a device is most likely to have: planned on a site that
     * is only planned, or when all of its addresses are; retired when all
     * of its addresses are; in service otherwise.
     *
     * @param  list<IpAddress>  $addresses
     */
    private function proposedStatus(Site $site, array $addresses): Status
    {
        if ($site->status === Status::Planned) {
            return Status::Planned;
        }

        $statuses = array_values(array_unique(array_map(fn (IpAddress $address): string => $address->status->value, $addresses)));

        return match ($statuses) {
            [Status::Planned->value] => Status::Planned,
            [Status::Retired->value] => Status::Retired,
            default => Status::Active,
        };
    }

    /**
     * The type most of the addresses have; the first met when several
     * come equal; "other" when none has a type.
     *
     * @param  list<IpAddress>  $addresses
     */
    private function proposedType(array $addresses): DeviceType
    {
        $counts = [];

        foreach ($addresses as $address) {
            if ($address->device_type !== null) {
                $counts[$address->device_type->value] = ($counts[$address->device_type->value] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return DeviceType::Other;
        }

        // The first key of the highest count, in the order the types were
        // met.
        return DeviceType::from((string) array_search(max($counts), $counts, true));
    }
}
