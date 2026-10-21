<?php

// torsade/database/factories/RoomFactory.php

namespace Database\Factories;

use App\Models\Building;
use App\Models\Floor;
use App\Models\Room;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For tests only.
 *
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'floor_id' => Floor::factory(),
            'name' => 'Room '.fake()->unique()->numberBetween(1, 99999),
            'is_technical' => false,
            'description' => null,
        ];
    }

    public function technical(): static
    {
        return $this->state(fn (array $attributes) => ['is_technical' => true]);
    }

    /**
     * A room of the given site, in a building and on a floor of its own.
     */
    public function inSite(Site $site): static
    {
        return $this->state(fn (array $attributes) => [
            'floor_id' => Floor::factory()->state([
                'building_id' => Building::factory()->state(['site_id' => $site->id]),
            ]),
        ]);
    }
}
