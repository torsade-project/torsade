<?php

// torsade/database/factories/AddressingRuleRangeFactory.php

namespace Database\Factories;

use App\Enums\RangeKind;
use App\Models\AddressingRule;
use App\Models\AddressingRuleRange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * For tests only.
 *
 * @extends Factory<AddressingRuleRange>
 */
class AddressingRuleRangeFactory extends Factory
{
    /**
     * A DHCP range from 100 to 200.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'addressing_rule_id' => AddressingRule::factory(),
            'kind' => RangeKind::Dhcp,
            'start_offset' => 100,
            'end_offset' => 200,
            'name' => null,
        ];
    }
}
