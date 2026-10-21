<?php

// torsade/app/Services/Network/IpRanges.php

namespace App\Services\Network;

use App\Casts\AsIpAddress;
use App\Enums\ActivityEvent;
use App\Enums\IpAssignment;
use App\Enums\RangeKind;
use App\Models\IpRange;
use App\Models\Subnet;
use App\Support\Actor;
use App\Support\Ipv4;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change to a range of a subnet goes through this class, whether it
 * comes from the interface or from a command: the rules are enforced here
 * and each change is written to the activity log.
 *
 * @phpstan-type RangeData array{kind?: string|null, start_address: string, end_address: string, name?: string|null, description?: string|null}
 */
final class IpRanges
{
    /**
     * How many addresses a message lists before summing up the rest.
     */
    private const int LISTED_ADDRESSES = 10;

    public function __construct(private readonly ChangeLog $log) {}

    /**
     * @param  RangeData  $data
     *
     * @throws ValidationException
     */
    public function create(Subnet $subnet, array $data, Actor $actor): IpRange
    {
        return DB::transaction(function () use ($subnet, $data, $actor): IpRange {
            $range = new IpRange;
            $range->subnet()->associate($subnet);

            $this->check($range, $data);

            $range->fill($this->attributes($range, $data));
            $range->save();

            $this->log->created(ActivityEvent::IpRangeCreated, $range, $actor);

            return $range;
        });
    }

    /**
     * @param  RangeData  $data
     *
     * @throws ValidationException
     */
    public function update(IpRange $range, array $data, Actor $actor): void
    {
        DB::transaction(function () use ($range, $data, $actor): void {
            $before = $range->auditValues();

            $this->check($range, $data);

            $range->fill($this->attributes($range, $data));
            $range->save();

            $this->log->updated(ActivityEvent::IpRangeUpdated, $range, $before, $actor);
        });
    }

    public function delete(IpRange $range, Actor $actor): void
    {
        DB::transaction(function () use ($range, $actor): void {
            $this->log->deleted(ActivityEvent::IpRangeDeleted, $range, $actor);

            $range->delete();
        });
    }

    /**
     * The kind asked for; failing that, the one the range already has. A
     * new range whose kind is not given is a DHCP range, the only kind
     * there was at first.
     *
     * @param  RangeData  $data
     */
    private function kind(IpRange $range, array $data): RangeKind
    {
        return RangeKind::tryFrom($data['kind'] ?? '')
            ?? ($range->exists ? $range->kind : RangeKind::Dhcp);
    }

    /**
     * @param  RangeData  $data
     * @return array<string, mixed>
     */
    private function attributes(IpRange $range, array $data): array
    {
        return [
            'kind' => $this->kind($range, $data)->value,
            'start_address' => $data['start_address'],
            'end_address' => $data['end_address'],
            'name' => ($data['name'] ?? null) === '' ? null : ($data['name'] ?? null),
            'description' => $data['description'] ?? null,
        ];
    }

    /**
     * @param  RangeData  $data
     *
     * @throws ValidationException
     */
    private function check(IpRange $range, array $data): void
    {
        $start = $data['start_address'];
        $end = $data['end_address'];
        $kind = $this->kind($range, $data);

        $errors = [];

        foreach (['start_address' => $start, 'end_address' => $end] as $field => $address) {
            if (! Ipv4::isValid($address)) {
                $errors[$field] = __('Enter a valid IPv4 address, such as 192.168.1.10.');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if (Ipv4::compare($end, $start) < 0) {
            throw ValidationException::withMessages([
                'end_address' => __('The end of the range cannot be lower than its start.'),
            ]);
        }

        // The subnet row is locked, so that its ranges and addresses do not
        // change while this range is being checked.
        $subnet = Subnet::query()->whereKey($range->subnet_id)->lockForUpdate()->firstOrFail();

        // A link between two routers or the address of a router is not cut
        // into ranges.
        if (! $subnet->kind->allowsRanges()) {
            throw ValidationException::withMessages([
                'start_address' => __('A subnet of the kind ":kind" has no ranges.', ['kind' => __($subnet->kind->label())]),
            ]);
        }

        [$first, $last] = Ipv4::usableRange($subnet->network, $subnet->prefix_length);

        foreach (['start_address' => $start, 'end_address' => $end] as $field => $address) {
            if (! Ipv4::isUsable($subnet->network, $subnet->prefix_length, $address)) {
                $errors[$field] = __('The range must stay within the usable addresses of :cidr (:first – :last).', [
                    'cidr' => $subnet->cidr(),
                    'first' => $first,
                    'last' => $last,
                ]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // Whatever their kinds, two ranges never share an address.
        foreach ($subnet->ipRanges()->whereKeyNot($range->getKey())->orderBy('start_address')->get() as $other) {
            if (Ipv4::rangesOverlap($start, $end, $other->start_address, $other->end_address)) {
                throw ValidationException::withMessages([
                    'start_address' => __('This range overlaps the range :range.', ['range' => $other->label()]),
                ]);
            }
        }

        if ($kind === RangeKind::Dhcp) {
            $this->checkDynamic($range, $subnet, $start, $end);
        }
    }

    /**
     * What a DHCP range must not contain: the gateway and the static
     * addresses, which would end up in an address conflict. A DHCP
     * reservation is legitimate there.
     *
     * @throws ValidationException
     */
    private function checkDynamic(IpRange $range, Subnet $subnet, string $start, string $end): void
    {
        // When a range of another kind becomes a DHCP range, its kind is
        // what is refused, not its bounds.
        $field = $range->exists && $range->kind !== RangeKind::Dhcp ? 'kind' : 'start_address';

        if ($subnet->gateway !== null && Ipv4::inRange($subnet->gateway, $start, $end)) {
            throw ValidationException::withMessages([
                $field => __('A DHCP range cannot contain the gateway (:gateway).', ['gateway' => $subnet->gateway]),
            ]);
        }

        $static = $subnet->ipAddresses()
            ->where('assignment', IpAssignment::Static)
            ->whereBetween('address', [AsIpAddress::toBinary($start), AsIpAddress::toBinary($end)])
            ->orderBy('address');

        $count = $static->count();

        if ($count > 0) {
            /** @var list<string> $listed */
            $listed = $static->limit(self::LISTED_ADDRESSES)->pluck('address')->all();

            $addresses = implode(', ', $listed);

            throw ValidationException::withMessages([
                $field => __('A DHCP range cannot contain static addresses: :addresses.', [
                    'addresses' => $count > count($listed)
                        ? trans_choice(':items and :count more', $count - count($listed), ['items' => $addresses])
                        : $addresses,
                ]),
            ]);
        }
    }
}
