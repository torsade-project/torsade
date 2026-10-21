<?php

// torsade/app/Models/AddressingRuleRange.php

namespace App\Models;

use App\Enums\RangeKind;
use Database\Factories\AddressingRuleRangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One of the ranges the addressing rule cuts a subnet of a VLAN into. Its
 * bounds are offsets from the network address of the subnet: 2 to 20 is
 * .2 to .20 in a /24.
 *
 * @property int $id
 * @property int $addressing_rule_id
 * @property RangeKind $kind
 * @property int $start_offset
 * @property int $end_offset
 * @property string|null $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['addressing_rule_id', 'kind', 'start_offset', 'end_offset', 'name'])]
class AddressingRuleRange extends Model
{
    /** @use HasFactory<AddressingRuleRangeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => RangeKind::class,
            'start_offset' => 'integer',
            'end_offset' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AddressingRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AddressingRule::class, 'addressing_rule_id');
    }

    /**
     * The range as the activity log writes it, in a form that does not
     * depend on a language: "reserved 2-20 Infrastructure".
     */
    public function label(): string
    {
        return trim($this->kind->value.' '.$this->start_offset.'-'.$this->end_offset.' '.$this->name);
    }
}
