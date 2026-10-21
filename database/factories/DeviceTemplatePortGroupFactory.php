<?php

// torsade/database/factories/DeviceTemplatePortGroupFactory.php

namespace Database\Factories;

use App\Enums\PoeType;
use App\Enums\PortConnector;
use App\Enums\PortSpeed;
use App\Models\DeviceTemplate;
use App\Models\DeviceTemplatePortGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For tests only.
 *
 * @extends Factory<DeviceTemplatePortGroup>
 */
class DeviceTemplatePortGroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_template_id' => DeviceTemplate::factory(),
            'position' => 1,
            'quantity' => 8,
            'name_pattern' => 'port{n}',
            'first_number' => 1,
            'connector' => PortConnector::Rj45,
            'max_speed' => PortSpeed::Gbit1,
            'poe_type' => PoeType::None,
            'poe_label' => null,
        ];
    }
}
