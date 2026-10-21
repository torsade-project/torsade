<?php

// torsade/database/factories/DeviceTemplateFactory.php

namespace Database\Factories;

use App\Enums\DeviceType;
use App\Models\DeviceTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For tests only.
 *
 * @extends Factory<DeviceTemplate>
 */
class DeviceTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Template '.fake()->unique()->numberBetween(1, 99999),
            'device_type' => DeviceType::Switch,
            'manufacturer' => null,
            'model' => null,
            'poe_budget_watts' => null,
            'description' => null,
        ];
    }

    /**
     * A switch with so many copper ports named "{m}/0/{n}" and so many
     * fibre ports named "{m}/1/{n}": the usual example.
     */
    public function switch(int $copper = 24, int $fibre = 4): static
    {
        $groups = [
            ['position' => 1, 'quantity' => $copper, 'name_pattern' => '{m}/0/{n}', 'first_number' => 1, 'connector' => 'rj45', 'max_speed' => '1g', 'poe_type' => '802.3at'],
            ['position' => 2, 'quantity' => $fibre, 'name_pattern' => '{m}/1/{n}', 'first_number' => 1, 'connector' => 'sfp_plus', 'max_speed' => '10g', 'poe_type' => 'none'],
        ];

        if ($fibre === 0) {
            array_pop($groups);
        }

        return $this->has(DeviceTemplatePortGroupFactory::new()->count(count($groups))->sequence(...$groups), 'portGroups');
    }
}
