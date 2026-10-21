<?php

// torsade/database/factories/CatalogVlanFactory.php

namespace Database\Factories;

use App\Models\CatalogVlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For tests only. An entry that is given no number gets one drawn in a
 * range no test writes by hand: see Database\Factories\DrawnVids.
 *
 * @extends Factory<CatalogVlan>
 */
class CatalogVlanFactory extends Factory
{
    /**
     * The number is drawn only when the entry is given none: a number
     * written by a test is never taken for a drawn one, which a draw made
     * anyway would make it once in a while. The name follows the number.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vid' => fn (): int => DrawnVids::forCatalogue(),
            'name' => fn (array $attributes): string => 'CATALOGUE-'.$attributes['vid'],
            'description' => null,
        ];
    }
}
