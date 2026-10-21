<?php

// torsade/database/factories/VlanFactory.php

namespace Database\Factories;

use App\Enums\Status;
use App\Models\Site;
use App\Models\Vlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For tests only. A VLAN that is given no number gets one drawn in a range
 * no test writes by hand: see Database\Factories\DrawnVids.
 *
 * @extends Factory<Vlan>
 */
class VlanFactory extends Factory
{
    /**
     * The number is drawn only when the VLAN is given none: a number written
     * by a test is never taken for a drawn one, which a draw made anyway
     * would make it once in a while. The name follows the number.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'catalog_vlan_id' => null,
            'vid' => fn (): int => DrawnVids::forVlan(),
            'name' => fn (array $attributes): string => 'VLAN-'.$attributes['vid'],
            'description' => null,
            'status' => Status::Active,
        ];
    }
}
