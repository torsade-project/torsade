<?php

// torsade/database/factories/SubnetFactory.php

namespace Database\Factories;

use App\Enums\Status;
use App\Enums\SubnetKind;
use App\Models\Subnet;
use App\Models\Vlan;
use App\Support\Ipv4;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For tests only.
 *
 * @extends Factory<Subnet>
 */
class SubnetFactory extends Factory
{
    /**
     * A /24 without VLAN, taken from 10.0.0.0/8 so that two subnets made by
     * the factory never overlap.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $index = fake()->unique()->numberBetween(0, 65535);

        return [
            'vlan_id' => null,
            'site_id' => null,
            'kind' => SubnetKind::Segment,
            'network' => '10.'.intdiv($index, 256).'.'.($index % 256).'.0',
            'prefix_length' => 24,
            'gateway' => null,
            'name' => null,
            'description' => null,
            'status' => Status::Active,
        ];
    }

    public function configure(): static
    {
        // A subnet carried by a VLAN is on the site of that VLAN.
        return $this->afterMaking(function (Subnet $subnet): void {
            if ($subnet->vlan_id !== null) {
                $subnet->site_id = Vlan::query()->whereKey($subnet->vlan_id)->value('site_id');
            }
        });
    }

    public function kind(SubnetKind $kind): static
    {
        return $this->state(fn (array $attributes) => ['kind' => $kind]);
    }

    /**
     * The subnet written in CIDR notation, as in "192.168.100.0/24".
     */
    public function cidr(string $cidr): static
    {
        $parsed = Ipv4::parseCidr($cidr) ?? ['address' => $cidr, 'prefix' => 24];

        return $this->state(fn (array $attributes) => [
            'network' => $parsed['address'],
            'prefix_length' => $parsed['prefix'],
        ]);
    }
}
