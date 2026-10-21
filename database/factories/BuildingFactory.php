<?php

// torsade/database/factories/BuildingFactory.php

namespace Database\Factories;

use App\Models\Building;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For tests only.
 *
 * @extends Factory<Building>
 */
class BuildingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'name' => 'Building '.fake()->unique()->numberBetween(1, 99999),
            'code' => null,
            'description' => null,
        ];
    }
}
