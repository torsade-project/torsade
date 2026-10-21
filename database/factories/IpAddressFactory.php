<?php

// torsade/database/factories/IpAddressFactory.php

namespace Database\Factories;

use App\Enums\IpAssignment;
use App\Enums\Status;
use App\Models\IpAddress;
use App\Models\Subnet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For tests only. The address is given by each test: it depends on the
 * subnet it belongs to.
 *
 * @extends Factory<IpAddress>
 */
class IpAddressFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $index = fake()->unique()->numberBetween(1, 65000);

        return [
            'subnet_id' => Subnet::factory(),
            // TEST-NET-2 and TEST-NET-3, reserved for documentation.
            'address' => '198.51.'.intdiv($index, 256).'.'.($index % 256),
            'hostname' => null,
            'mac_address' => null,
            'device_type' => null,
            'assignment' => IpAssignment::Static,
            'device_id' => null,
            'description' => null,
            'status' => Status::Active,
        ];
    }

    public function reservation(): static
    {
        return $this->state(fn (array $attributes) => ['assignment' => IpAssignment::DhcpReservation]);
    }
}
