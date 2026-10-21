<?php

// torsade/database/factories/SiteFactory.php

namespace Database\Factories;

use App\Enums\Status;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For tests only.
 *
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Site '.fake()->unique()->numberBetween(1, 99999),
            'code' => null,
            'number' => null,
            'status' => Status::Active,
            'description' => null,
        ];
    }
}
