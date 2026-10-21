<?php

// torsade/app/Services/Home/FullestSubnetsModule.php

namespace App\Services\Home;

use App\Enums\Status;
use App\Enums\SubnetKind;
use App\Models\Subnet;
use App\Models\User;
use App\Support\Ipv4;

/**
 * The subnets the closest to full: segments that are not retired and hold
 * at least one documented address, by occupancy, highest first, then by
 * address. The occupancy is worked out as on the page of a subnet: the
 * documented addresses out of the usable ones.
 *
 * The share is worked out here, on the subnets and their counts read in
 * one query, rather than by an expression of the database: the number of
 * usable addresses depends on the length of the prefix (RFC 3021 for a
 * /31, the address alone for a /32).
 */
final class FullestSubnetsModule extends HomeModule
{
    public function key(): string
    {
        return 'fullest-subnets';
    }

    public function authorize(User $user): bool
    {
        return $user->can('view-network');
    }

    public function settings(): array
    {
        return ['limit' => ['min' => 1, 'max' => 20, 'default' => 5]];
    }

    public function data(User $user, array $settings): array
    {
        $subnets = Subnet::query()
            ->where('kind', SubnetKind::Segment->value)
            ->where('status', '!=', Status::Retired->value)
            ->has('ipAddresses')
            ->withCount('ipAddresses')
            ->get(['id', 'vlan_id', 'site_id', 'network', 'prefix_length', 'name']);

        $occupancy = fn (Subnet $subnet): float => (int) $subnet->getAttribute('ip_addresses_count') / Ipv4::usableCount($subnet->prefix_length);

        $fullest = $subnets
            ->sort(fn (Subnet $a, Subnet $b): int => [$occupancy($b), Ipv4::toInt($a->network), $a->prefix_length] <=> [$occupancy($a), Ipv4::toInt($b->network), $b->prefix_length])
            ->take($settings['limit'])
            ->values();

        $fullest->load(['vlan', 'site']);

        return [
            'subnets' => array_values($fullest->map(fn (Subnet $subnet): array => [
                'id' => $subnet->id,
                'cidr' => $subnet->cidr(),
                'name' => $subnet->name,
                'vlan' => $subnet->vlan === null ? null : ['vid' => $subnet->vlan->vid, 'name' => $subnet->vlan->name],
                'site' => $subnet->site?->name,
                'documented_count' => (int) $subnet->getAttribute('ip_addresses_count'),
                'usable_count' => Ipv4::usableCount($subnet->prefix_length),
                'url' => route('subnets.show', $subnet),
            ])->all()),
        ];
    }
}
