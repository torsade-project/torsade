<?php

// torsade/database/factories/DeviceMemberFactory.php

namespace Database\Factories;

use App\Enums\MemberRole;
use App\Models\Device;
use App\Models\DeviceMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For tests only. A test that gives several members to one device gives
 * each one its number.
 *
 * @extends Factory<DeviceMember>
 */
class DeviceMemberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'position' => 1,
            'role' => MemberRole::Member,
            'device_template_id' => null,
            'serial_number' => null,
            'room_id' => null,
            'description' => null,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn (array $attributes) => ['role' => MemberRole::Primary]);
    }

    public function secondary(): static
    {
        return $this->state(fn (array $attributes) => ['role' => MemberRole::Secondary]);
    }
}
