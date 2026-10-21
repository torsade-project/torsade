<?php

// torsade/database/factories/AddressBlockFactory.php

namespace Database\Factories;

use App\Enums\Status;
use App\Models\AddressBlock;
use App\Models\Site;
use App\Support\Ipv4;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For tests only. The block is given by each test, in CIDR notation: what
 * matters about a block is where it lies.
 *
 * @extends Factory<AddressBlock>
 */
class AddressBlockFactory extends Factory
{
    /**
     * A block of the organisation, a /16 taken from 10.0.0.0/8.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => null,
            'network' => '10.'.fake()->unique()->numberBetween(0, 255).'.0.0',
            'prefix_length' => 16,
            'name' => null,
            'description' => null,
            'status' => Status::Active,
        ];
    }

    /**
     * The block written in CIDR notation, as in "10.1.0.0/16".
     */
    public function cidr(string $cidr): static
    {
        $parsed = Ipv4::parseCidr($cidr) ?? ['address' => $cidr, 'prefix' => 16];

        return $this->state(fn (array $attributes) => [
            'network' => $parsed['address'],
            'prefix_length' => $parsed['prefix'],
        ]);
    }

    /**
     * A block that belongs to a site.
     */
    public function forSite(?Site $site = null): static
    {
        return $this->state(fn (array $attributes) => [
            'site_id' => $site === null ? Site::factory() : $site->id,
        ]);
    }
}
