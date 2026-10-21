<?php

// torsade/database/factories/PortVlanFactory.php

namespace Database\Factories;

use App\Models\PortVlan;
use App\Models\Vlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For tests only. Each test says which port or which aggregate carries the
 * VLAN: a line belongs to one of the two.
 *
 * @extends Factory<PortVlan>
 */
class PortVlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'port_id' => null,
            'port_aggregate_id' => null,
            'vlan_id' => Vlan::factory(),
            'tagged' => false,
        ];
    }
}
