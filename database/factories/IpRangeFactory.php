<?php

// torsade/database/factories/IpRangeFactory.php

namespace Database\Factories;

use App\Enums\RangeKind;
use App\Models\IpRange;
use App\Models\Subnet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For tests only. The addresses are given by each test: they depend on the
 * subnet the range belongs to.
 *
 * @extends Factory<IpRange>
 */
class IpRangeFactory extends Factory
{
    /**
     * A DHCP range.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subnet_id' => Subnet::factory(),
            'kind' => RangeKind::Dhcp,
            'start_address' => '192.0.2.100',
            'end_address' => '192.0.2.199',
            'name' => null,
            'description' => null,
        ];
    }

    public function between(string $start, string $end): static
    {
        return $this->state(fn (array $attributes) => [
            'start_address' => $start,
            'end_address' => $end,
        ]);
    }

    /**
     * A range kept for the addresses set on the machines.
     */
    public function static(): static
    {
        return $this->state(fn (array $attributes) => ['kind' => RangeKind::Static]);
    }

    /**
     * A range kept aside.
     */
    public function reserved(): static
    {
        return $this->state(fn (array $attributes) => ['kind' => RangeKind::Reserved]);
    }
}
