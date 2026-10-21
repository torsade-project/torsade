<?php

// torsade/app/Services/Network/AddressPlan.php

namespace App\Services\Network;

use App\Enums\AddressState;
use App\Enums\IpAssignment;
use App\Enums\RangeKind;
use App\Enums\Status;
use App\Models\IpAddress;
use App\Models\IpRange;
use App\Models\Subnet;
use App\Support\Ipv4;

/**
 * The address plan of a subnet: the state of each of its addresses, from
 * the network address to the broadcast address, like the ports of a patch
 * panel.
 *
 * It is only drawn for a subnet a screen can show: beyond the limit, the
 * table of addresses and the list of free intervals carry the information.
 */
final class AddressPlan
{
    /**
     * The largest subnet that gets a plan: a /23.
     */
    public const int MAX_ADDRESSES = 512;

    /**
     * @return array{size: int, limit: int, cells: list<string>|null}
     */
    public function of(Subnet $subnet): array
    {
        $size = 2 ** (32 - $subnet->prefix_length);

        $plan = ['size' => $size, 'limit' => self::MAX_ADDRESSES];

        if ($size > self::MAX_ADDRESSES) {
            return [...$plan, 'cells' => null];
        }

        $network = Ipv4::toInt($subnet->network);
        [$firstUsable, $lastUsable] = array_map(
            Ipv4::toInt(...),
            Ipv4::usableRange($subnet->network, $subnet->prefix_length),
        );
        $gateway = $subnet->gateway === null ? null : Ipv4::toInt($subnet->gateway);

        /** @var array<int, IpAddress> $documented */
        $documented = [];

        foreach ($subnet->ipAddresses()->get() as $address) {
            $documented[Ipv4::toInt($address->address)] = $address;
        }

        // A static range changes nothing to the state of an address.
        $ranges = $subnet->ipRanges()->whereIn('kind', [RangeKind::Dhcp, RangeKind::Reserved])->get()
            ->map(fn (IpRange $range): array => [
                Ipv4::toInt($range->start_address),
                Ipv4::toInt($range->end_address),
                $range->kind === RangeKind::Dhcp ? AddressState::DhcpRange : AddressState::ReservedRange,
            ])
            ->all();

        $cells = [];

        for ($address = $network; $address < $network + $size; $address++) {
            $cells[] = $this->state(
                $address,
                $address >= $firstUsable && $address <= $lastUsable,
                $gateway,
                $documented[$address] ?? null,
                $ranges,
            )->value;
        }

        return [...$plan, 'cells' => $cells];
    }

    /**
     * @param  array<int, array{int, int, AddressState}>  $ranges
     */
    private function state(int $address, bool $usable, ?int $gateway, ?IpAddress $documented, array $ranges): AddressState
    {
        if (! $usable) {
            return AddressState::Unusable;
        }

        if ($address === $gateway) {
            return AddressState::Gateway;
        }

        if ($documented !== null) {
            return match (true) {
                $documented->status === Status::Planned => AddressState::Planned,
                $documented->status === Status::Retired => AddressState::Retired,
                $documented->assignment === IpAssignment::DhcpReservation => AddressState::Reservation,
                default => AddressState::Documented,
            };
        }

        // Two ranges never share an address: one of them at most applies.
        foreach ($ranges as [$start, $end, $state]) {
            if ($address >= $start && $address <= $end) {
                return $state;
            }
        }

        return AddressState::Free;
    }
}
