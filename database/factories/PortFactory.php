<?php

// torsade/database/factories/PortFactory.php

namespace Database\Factories;

use App\Enums\PoeType;
use App\Enums\PortConnector;
use App\Enums\PortSpeed;
use App\Models\DeviceMember;
use App\Models\Port;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For tests only. The device of a port is the one of its member: the model
 * writes it by itself.
 *
 * A test that needs the ports of a member in a given order gives each one
 * its place: the place drawn here only keeps a port storable.
 *
 * @extends Factory<Port>
 */
class PortFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 99999);

        return [
            'device_member_id' => DeviceMember::factory(),
            'name' => 'port'.$number,
            // The number that makes the name unique is larger than a place
            // can be: it is brought back into the order of a member.
            'position' => $number % Port::MAX_POSITION + 1,
            'connector' => PortConnector::Rj45,
            'max_speed' => PortSpeed::Gbit1,
            'poe_type' => PoeType::None,
            'poe_label' => null,
            'enabled' => true,
            'mode' => null,
            'port_aggregate_id' => null,
            'description' => null,
        ];
    }

    /**
     * So many ports of a member, named "<member>/0/1" onwards.
     *
     * @return list<Port>
     */
    public static function series(DeviceMember $member, int $quantity, int $module = 0): array
    {
        $ports = [];

        for ($number = 1; $number <= $quantity; $number++) {
            $ports[] = Port::factory()->create([
                'device_member_id' => $member->id,
                'name' => $member->position.'/'.$module.'/'.$number,
                'position' => $module * 1000 + $number,
            ]);
        }

        return $ports;
    }
}
