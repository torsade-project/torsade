<?php

// torsade/database/factories/PatchCordFactory.php

namespace Database\Factories;

use App\Models\PatchCord;
use App\Models\PatchPanelPort;
use App\Models\Port;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For tests only. A cord is nothing without its two ends: a test makes one
 * with between().
 *
 * @extends Factory<PatchCord>
 */
class PatchCordFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'color' => null,
            'description' => null,
        ];
    }

    /**
     * A cord from a port of a panel to a port of a device.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function between(PatchPanelPort $panelPort, Port $port, array $attributes = []): PatchCord
    {
        return PatchCord::factory()->create(['patch_panel_port_id' => $panelPort->id, 'port_id' => $port->id, ...$attributes]);
    }
}
