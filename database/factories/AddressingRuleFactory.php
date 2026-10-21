<?php

// torsade/database/factories/AddressingRuleFactory.php

namespace Database\Factories;

use App\Enums\GatewayPosition;
use App\Models\AddressBlock;
use App\Models\AddressingRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For tests only. An installation has one rule at most: a test creates it
 * once.
 *
 * @extends Factory<AddressingRule>
 */
class AddressingRuleFactory extends Factory
{
    /**
     * The usual rule: 10.<site>.<VLAN>.0/24, gateway on the first address.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'aggregate_block_id' => AddressBlock::factory()->cidr('10.0.0.0/8'),
            'site_prefix_length' => 16,
            'vlan_prefix_length' => 24,
            'gateway_position' => GatewayPosition::First,
        ];
    }

    /**
     * The rule starts from a block that already exists.
     */
    public function from(AddressBlock $block): static
    {
        return $this->state(fn (array $attributes) => ['aggregate_block_id' => $block->id]);
    }

    /**
     * The lengths of the blocks of sites and of the subnets of VLANs.
     */
    public function lengths(int $site, int $vlan): static
    {
        return $this->state(fn (array $attributes) => [
            'site_prefix_length' => $site,
            'vlan_prefix_length' => $vlan,
        ]);
    }

    /**
     * No level of sites: the subnets of VLANs are ranked by VID straight in
     * the block of the organisation.
     */
    public function withoutSiteLevel(int $vlan = 24): static
    {
        return $this->state(fn (array $attributes) => [
            'site_prefix_length' => null,
            'vlan_prefix_length' => $vlan,
        ]);
    }

    public function gateway(GatewayPosition $position): static
    {
        return $this->state(fn (array $attributes) => ['gateway_position' => $position]);
    }

    /**
     * With the ranges of the usual example: reserved 2 to 20, static 21 to
     * 99, DHCP 100 to 200.
     */
    public function withBreakdown(): static
    {
        return $this->has(
            AddressingRuleRangeFactory::new()->count(3)->sequence(
                ['kind' => 'reserved', 'start_offset' => 2, 'end_offset' => 20, 'name' => 'Infrastructure'],
                ['kind' => 'static', 'start_offset' => 21, 'end_offset' => 99, 'name' => 'Adresses fixes'],
                ['kind' => 'dhcp', 'start_offset' => 100, 'end_offset' => 200, 'name' => null],
            ),
            'ranges',
        );
    }
}
