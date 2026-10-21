<?php

// torsade/database/factories/PortAggregateFactory.php

namespace Database\Factories;

use App\Enums\AggregateProtocol;
use App\Models\Device;
use App\Models\PortAggregate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For tests only.
 *
 * @extends Factory<PortAggregate>
 */
class PortAggregateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'name' => 'Po'.fake()->unique()->numberBetween(1, 9999),
            'protocol' => AggregateProtocol::Lacp,
            'mode' => null,
            'description' => null,
        ];
    }
}
