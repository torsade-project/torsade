<?php

// torsade/database/factories/FloorFactory.php

namespace Database\Factories;

use App\Models\Building;
use App\Models\Floor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For tests only. A test that gives several floors to one building gives
 * each one its level.
 *
 * @extends Factory<Floor>
 */
class FloorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'building_id' => Building::factory(),
            'level' => 0,
            'name' => 'Floor '.fake()->unique()->numberBetween(1, 99999),
            'description' => null,
        ];
    }
}
