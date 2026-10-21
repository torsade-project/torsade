<?php

// torsade/database/factories/PatchPanelFactory.php

namespace Database\Factories;

use App\Enums\PanelConnector;
use App\Enums\Status;
use App\Models\PatchPanel;
use App\Models\PatchPanelPort;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For tests only. A panel is made with its ports, numbered from 1, as the
 * application makes it. Its name is drawn as "panel-<number>": no test
 * writes a name of that form by hand.
 *
 * @extends Factory<PatchPanel>
 */
class PatchPanelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory()->technical(),
            'name' => 'panel-'.fake()->unique()->numberBetween(1, 99999),
            'port_count' => 24,
            'connector' => PanelConnector::Rj45,
            'category' => null,
            'description' => null,
            'status' => Status::Active,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (PatchPanel $panel): void {
            foreach (range(1, $panel->port_count) as $number) {
                PatchPanelPort::query()->create(['patch_panel_id' => $panel->id, 'number' => $number]);
            }
        });
    }
}
