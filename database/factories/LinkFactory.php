<?php

// torsade/database/factories/LinkFactory.php

namespace Database\Factories;

use App\Enums\Status;
use App\Models\Link;
use App\Models\LinkEnd;
use App\Models\Port;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For tests only. A link is nothing without its two ends: a test makes one
 * with between().
 *
 * @extends Factory<Link>
 */
class LinkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'medium' => null,
            'description' => null,
            'status' => Status::Active,
        ];
    }

    /**
     * A link between two ports, the first one at its end 1.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function between(Port $first, Port $second, array $attributes = []): Link
    {
        $link = Link::factory()->create($attributes);

        foreach ([1 => $first, 2 => $second] as $end => $port) {
            LinkEnd::query()->create(['link_id' => $link->id, 'port_id' => $port->id, 'end' => $end]);
        }

        return $link;
    }
}
