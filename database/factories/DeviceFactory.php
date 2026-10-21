<?php

// torsade/database/factories/DeviceFactory.php

namespace Database\Factories;

use App\Enums\DeviceType;
use App\Enums\Status;
use App\Models\Device;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For tests only.
 *
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'room_id' => null,
            'name' => 'device-'.fake()->unique()->numberBetween(1, 99999),
            'type' => DeviceType::Server,
            'manufacturer' => null,
            'model' => null,
            'serial_number' => null,
            'description' => null,
            'status' => Status::Active,
        ];
    }
}
