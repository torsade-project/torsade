<?php

// torsade/app/Services/Network/IpAddresses.php

namespace App\Services\Network;

use App\Casts\AsIpAddress;
use App\Enums\ActivityEvent;
use App\Enums\IpAssignment;
use App\Enums\Status;
use App\Models\Device;
use App\Models\IpAddress;
use App\Models\IpRange;
use App\Models\Jack;
use App\Models\Subnet;
use App\Rules\Ipv4Address;
use App\Services\Network\Concerns\TranslatesUniqueViolations;
use App\Support\Actor;
use App\Support\Ipv4;
use App\Support\MacAddress;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change to an IP address goes through this class, whether it comes
 * from the interface or from a command: the rules of the addressing plan are
 * enforced here and each change is written to the activity log.
 *
 * An address attached to a device is one of its interfaces. Attaching and
 * detaching are events of their own in the activity log, whatever they come
 * from: the page of the device, or the window of the address.
 *
 * @phpstan-type AddressData array{address: string, hostname?: string|null, mac_address?: string|null, device_type?: string|null, assignment?: string|null, device_id?: int|null, jack_id?: int|null, status?: string|null, description?: string|null}
 */
final class IpAddresses
{
    use TranslatesUniqueViolations;

    /**
     * Prefix of the message keys of an entry of several lines:
     * "addresses.<line>.<field>".
     */
    private const string LINES_PREFIX = 'addresses.';

    public function __construct(private readonly ChangeLog $log) {}

    /**
     * @param  AddressData  $data
     *
     * @throws ValidationException Messages are keyed by field.
     */
    public function create(Subnet $subnet, array $data, Actor $actor): IpAddress
    {
        return $this->store($subnet, [$data], $actor, single: true)[0];
    }

    /**
     * Document several addresses of a subnet at once. Either every line is
     * saved, or none is.
     *
     * @param  array<int, AddressData>  $lines
     * @return list<IpAddress>
     *
     * @throws ValidationException Messages are keyed "addresses.<line>.<field>".
     */
    public function createMany(Subnet $subnet, array $lines, Actor $actor): array
    {
        return $this->store($subnet, $lines, $actor, single: false);
    }

    /**
     * What the addressing plan refuses in an entry of several lines, without
     * saving anything: every line is looked at, so that all the messages
     * come back at once, each on its line and its field.
     *
     * @param  array<int, AddressData>  $lines
     * @return array<string, string> Messages keyed "addresses.<line>.<field>".
     */
    public function check(Subnet $subnet, array $lines): array
    {
        return $this->evaluate($subnet, $lines, self::LINES_PREFIX, lock: false)[1];
    }

    /**
     * @param  AddressData  $data
     *
     * @throws ValidationException
     */
    public function update(IpAddress $address, array $data, Actor $actor): void
    {
        DB::transaction(function () use ($address, $data, $actor): void {
            $subnet = $this->lock($address->subnet);
            $address->setRelation('subnet', $subnet);

            $before = $address->auditValues();
            $previous = $address->device;
            $previousJack = $address->jack;

            [$attributes, $errors] = $this->prepare($subnet, $subnet->dhcpRanges()->get(), $data, $address);

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $address->fill($attributes);
            // The device and the jack read for the values before are not
            // the ones after.
            $address->unsetRelation('device');
            $address->unsetRelation('jack');

            $this->save($address, 'address');

            // The device and the jack are told apart: the address was
            // attached or detached, plugged or unplugged.
            $this->log->updated(ActivityEvent::IpAddressUpdated, $address, $before, $actor, except: ['device', 'jack']);
            $this->logDevice($address, $previous, $address->device, $actor);
            $this->logJack($address, $previousJack, $address->jack, $actor);
        });
    }

    /**
     * Make an address an interface of a device. An address of another site
     * than the device is accepted: it is shown as a deviation. An address
     * that already belongs to a device is detached first, by someone.
     *
     * @param  string  $field  Where the refusal is told.
     *
     * @throws ValidationException
     */
    public function attach(IpAddress $address, Device $device, Actor $actor, string $field = 'ip_address_id'): void
    {
        DB::transaction(function () use ($address, $device, $actor, $field): void {
            $address = $this->lockAddress($address);

            if ($address->device_id === $device->id) {
                throw ValidationException::withMessages([
                    $field => __('The address :address is already attached to this device.', ['address' => $address->address]),
                ]);
            }

            if ($address->device !== null) {
                throw ValidationException::withMessages([
                    $field => __('The address :address is already attached to the device :device: detach it first.', [
                        'address' => $address->address,
                        'device' => $address->device->label(),
                    ]),
                ]);
            }

            $this->assign($address, $device, $actor);
        });
    }

    /**
     * Detach an address from its device. The address stays documented, with
     * its host name and its type.
     */
    public function detach(IpAddress $address, Actor $actor): void
    {
        DB::transaction(function () use ($address, $actor): void {
            $this->assign($this->lockAddress($address), null, $actor);
        });
    }

    /**
     * Detach every address of a device at once, each one logged.
     *
     * @return int How many addresses were detached.
     */
    public function detachAll(Device $device, Actor $actor): int
    {
        return DB::transaction(function () use ($device, $actor): int {
            $addresses = $device->ipAddresses()->with('subnet')->orderBy('address')->lockForUpdate()->get();

            foreach ($addresses as $address) {
                $address->setRelation('device', $device);

                $this->assign($address, null, $actor);
            }

            return $addresses->count();
        });
    }

    /**
     * Deleting the record frees the address. The activity log keeps the trace
     * of what it was.
     */
    public function delete(IpAddress $address, Actor $actor): void
    {
        DB::transaction(function () use ($address, $actor): void {
            $this->log->deleted(ActivityEvent::IpAddressDeleted, $address, $actor);

            $address->delete();
        });
    }

    /**
     * @param  array<int, AddressData>  $lines
     * @return list<IpAddress>
     *
     * @throws ValidationException
     */
    private function store(Subnet $subnet, array $lines, Actor $actor, bool $single): array
    {
        return DB::transaction(function () use ($subnet, $lines, $actor, $single): array {
            $subnet = $this->lock($subnet);

            [$attributes, $errors] = $this->evaluate($subnet, $lines, $single ? '' : self::LINES_PREFIX, lock: true);

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $created = [];

            foreach ($attributes as $values) {
                $address = new IpAddress($values);
                $address->subnet()->associate($subnet);

                $this->save($address, $single ? 'address' : 'addresses');

                $this->log->created(ActivityEvent::IpAddressCreated, $address, $actor);
                // Created as an interface of a device, it was attached too;
                // created with a jack, it was plugged into it.
                $this->logDevice($address, null, $address->device, $actor);
                $this->logJack($address, null, $address->jack, $actor);

                $created[] = $address;
            }

            return $created;
        });
    }

    /**
     * The address as it is now, locked for the time of the change, with its
     * subnet and its device.
     */
    private function lockAddress(IpAddress $address): IpAddress
    {
        return IpAddress::query()->with(['subnet', 'device.site'])->whereKey($address->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Give an address its device, or none, and log it. The subnet and the
     * current device of the address must be loaded.
     */
    private function assign(IpAddress $address, ?Device $device, Actor $actor): void
    {
        $previous = $address->device;

        $address->device()->associate($device);
        $address->save();

        $this->logDevice($address, $previous, $device, $actor);
    }

    /**
     * An address that leaves a device, joins one, or both, is told by events
     * of their own, each naming the device and its site.
     */
    private function logDevice(IpAddress $address, ?Device $previous, ?Device $current, Actor $actor): void
    {
        if ($previous?->id === $current?->id) {
            return;
        }

        if ($previous !== null) {
            $this->log->noted(ActivityEvent::IpAddressDetached, $address, ['device' => $previous->label()], [], $actor);
        }

        if ($current !== null) {
            $this->log->noted(ActivityEvent::IpAddressAttached, $address, [], ['device' => $current->label()], $actor);
        }
    }

    /**
     * An address plugged into a jack, unplugged from one, or both, is told
     * by events of their own, each naming the jack with its place and its
     * site.
     */
    private function logJack(IpAddress $address, ?Jack $previous, ?Jack $current, Actor $actor): void
    {
        if ($previous?->id === $current?->id) {
            return;
        }

        if ($previous !== null) {
            $this->log->noted(ActivityEvent::IpAddressUnplugged, $address, ['jack' => $previous->auditLabel()], [], $actor);
        }

        if ($current !== null) {
            $this->log->noted(ActivityEvent::IpAddressPlugged, $address, [], ['jack' => $current->auditLabel()], $actor);
        }
    }

    /**
     * The subnet row is locked for the time of the change, so that its ranges
     * and addresses cannot change between the checks and the write.
     */
    private function lock(Subnet $subnet): Subnet
    {
        return Subnet::query()->whereKey($subnet->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Check every line against the subnet, against the other lines and
     * against what is already documented.
     *
     * @param  array<int, AddressData>  $lines
     * @param  string  $prefix  "" for a single address, whose messages are keyed by field alone.
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, string>} Attributes by line, then messages.
     */
    private function evaluate(Subnet $subnet, array $lines, string $prefix, bool $lock): array
    {
        $ranges = $subnet->dhcpRanges()->orderBy('start_address')->get();
        $key = fn (int $line, string $field): string => $prefix === '' ? $field : $prefix.$line.'.'.$field;

        $attributes = [];
        $errors = [];
        $seen = [];

        foreach ($lines as $line => $data) {
            [$attributes[$line], $lineErrors] = $this->prepare($subnet, $ranges, $data);

            $address = $data['address'];

            if (! isset($lineErrors['address']) && isset($seen[$address])) {
                $lineErrors['address'] = __('This address appears twice in this entry.');
            }

            $seen[$address] = true;

            foreach ($lineErrors as $field => $message) {
                $errors[$key($line, $field)] = $message;
            }
        }

        // One query for the whole entry: which of these are already documented?
        $candidates = array_values(array_filter(array_map(strval(...), array_keys($seen)), Ipv4::isValid(...)));

        /** @var list<string> $taken */
        $taken = $candidates === [] ? [] : IpAddress::query()
            ->whereIn('address', array_map(AsIpAddress::toBinary(...), $candidates))
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->pluck('address')
            ->all();

        foreach ($lines as $line => $data) {
            if (! isset($errors[$key($line, 'address')]) && in_array($data['address'], $taken, true)) {
                $errors[$key($line, 'address')] = $this->takenMessage();
            }
        }

        return [$attributes, $errors];
    }

    /**
     * Turn typed values into the attributes to store, and collect what the
     * addressing plan refuses.
     *
     * @param  Collection<int, IpRange>  $ranges  The DHCP ranges of the subnet.
     * @param  AddressData  $data
     * @return array{0: array<string, mixed>, 1: array<string, string>} Attributes, then messages by field.
     */
    private function prepare(Subnet $subnet, Collection $ranges, array $data, ?IpAddress $existing = null): array
    {
        $errors = [];

        $ip = $data['address'];
        $assignment = IpAssignment::tryFrom($data['assignment'] ?? '') ?? $existing->assignment ?? IpAssignment::Static;

        if (! Ipv4::isValid($ip)) {
            $errors['address'] = Ipv4Address::looksLikeIpv6($ip)
                ? __('IPv6 addresses are not supported yet: enter an IPv4 address.')
                : __('Enter a valid IPv4 address, such as 192.168.1.10.');
        } elseif (! Ipv4::isUsable($subnet->network, $subnet->prefix_length, $ip)) {
            [$first, $last] = Ipv4::usableRange($subnet->network, $subnet->prefix_length);

            $errors['address'] = __('This address is not a usable address of :cidr (:first – :last).', [
                'cidr' => $subnet->cidr(),
                'first' => $first,
                'last' => $last,
            ]);
        } elseif ($assignment === IpAssignment::Static) {
            // A static address inside a dynamic range ends up in an address
            // conflict; a DHCP reservation is legitimate there.
            $range = $ranges->first(fn (IpRange $range): bool => Ipv4::inRange($ip, $range->start_address, $range->end_address));

            if ($range !== null) {
                $errors['address'] = __('A static address cannot be inside the DHCP range :range. Choose another address, or make it a DHCP reservation.', [
                    'range' => $range->label(),
                ]);
            }
        }

        // Left out, the device stays what it is: the entry of several
        // lines says nothing of devices.
        $deviceId = array_key_exists('device_id', $data) ? $data['device_id'] : $existing?->device_id;

        if (array_key_exists('device_id', $data) && $deviceId !== null && ! Device::query()->whereKey($deviceId)->exists()) {
            $errors['device_id'] = __('This device no longer exists.');
        }

        // Left out, the jack stays what it is, as the device does.
        $jackId = array_key_exists('jack_id', $data) ? $data['jack_id'] : $existing?->jack_id;

        if (array_key_exists('jack_id', $data) && $jackId !== null) {
            $error = $this->jackProblem($subnet, $jackId);

            if ($error !== null) {
                $errors['jack_id'] = $error;
            }
        }

        $mac = $this->blankToNull($data['mac_address'] ?? null);

        if ($mac !== null) {
            $mac = MacAddress::normalise($mac);

            if ($mac === null) {
                $errors['mac_address'] = __('Enter a MAC address such as aa:bb:cc:dd:ee:ff.');
            }
        }

        return [[
            'address' => $ip,
            'hostname' => $this->blankToNull($data['hostname'] ?? null),
            'mac_address' => $mac,
            'device_type' => $this->blankToNull($data['device_type'] ?? null),
            'assignment' => $assignment->value,
            'device_id' => $deviceId,
            'jack_id' => $jackId,
            'status' => $data['status'] ?? $existing->status->value ?? Status::Active->value,
            'description' => $this->blankToNull($data['description'] ?? null),
        ], $errors];
    }

    /**
     * What keeps an address from being plugged into a jack, if anything: a
     * jack that no longer exists, or a jack of another site than the subnet
     * of the address, when the subnet has a site. The jack is kept from
     * being deleted until the end of the change.
     */
    private function jackProblem(Subnet $subnet, int $jackId): ?string
    {
        $jack = Jack::query()->with('floor.building.site')->whereKey($jackId)->sharedLock()->first();

        if ($jack === null) {
            return __('This jack no longer exists.');
        }

        $subnet->loadMissing('site');

        if ($subnet->site !== null && $jack->siteId() !== $subnet->site_id) {
            return __('The jack :jack is on the site :site: choose a jack of the site of the subnet, :subnet.', [
                'jack' => $jack->label,
                'site' => $jack->floor->building->site->name,
                'subnet' => $subnet->site->name,
            ]);
        }

        return null;
    }

    private function blankToNull(?string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private function takenMessage(): string
    {
        return __('This address is already documented.');
    }

    /**
     * @throws ValidationException
     */
    private function save(IpAddress $address, string $field): void
    {
        $this->guardingUniqueness(fn (): bool => $address->save(), [
            'ip_addresses_address_unique' => [$field, $this->takenMessage()],
        ]);
    }
}
