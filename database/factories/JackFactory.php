<?php

// torsade/database/factories/JackFactory.php

namespace Database\Factories;

use App\Enums\Status;
use App\Models\Floor;
use App\Models\Jack;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For tests only. Its label is drawn as "jack-<number>": no test writes a
 * label of that form by hand.
 *
 * @extends Factory<Jack>
 */
class JackFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'floor_id' => Floor::factory(),
            'label' => 'jack-'.fake()->unique()->numberBetween(1, 99999),
            'location' => null,
            'patch_panel_port_id' => null,
            'description' => null,
            'status' => Status::Active,
        ];
    }
}
