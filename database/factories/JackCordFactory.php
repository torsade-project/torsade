<?php

// torsade/database/factories/JackCordFactory.php

namespace Database\Factories;

use App\Models\Jack;
use App\Models\JackCord;
use App\Models\Port;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For tests only. A cord is nothing without its two ends: a test makes one
 * with between().
 *
 * @extends Factory<JackCord>
 */
class JackCordFactory extends Factory
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
     * A port of a device plugged into a jack.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function between(Jack $jack, Port $port, array $attributes = []): JackCord
    {
        return JackCord::factory()->create(['jack_id' => $jack->id, 'port_id' => $port->id, ...$attributes]);
    }
}
