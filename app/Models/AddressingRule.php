<?php

// torsade/app/Models/AddressingRule.php

namespace App\Models;

use App\Contracts\Auditable;
use App\Enums\GatewayPosition;
use Database\Factories\AddressingRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * How the organisation numbers its networks: a block of its own, cut into
 * one block per site (the rank of a block is the number of its site), each
 * cut into one subnet per VLAN (the rank of a subnet is the VID). It also
 * says how such a subnet is usually cut into ranges.
 *
 * There is one rule at most. It proposes and points out what differs: it
 * never changes an object and no object refers to it.
 *
 * @property int $id
 * @property int $aggregate_block_id
 * @property int|null $site_prefix_length
 * @property int $vlan_prefix_length
 * @property GatewayPosition $gateway_position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AddressBlock $aggregateBlock
 * @property-read Collection<int, AddressingRuleRange> $ranges
 */
// The column that keeps the rule alone in its table is never assigned: it
// has its one value by default.
#[Fillable(['aggregate_block_id', 'site_prefix_length', 'vlan_prefix_length', 'gateway_position'])]
class AddressingRule extends Model implements Auditable
{
    /** @use HasFactory<AddressingRuleFactory> */
    use HasFactory;

    /**
     * Defaults of a new model, matching the defaults of the columns.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'gateway_position' => GatewayPosition::First->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'site_prefix_length' => 'integer',
            'vlan_prefix_length' => 'integer',
            'gateway_position' => GatewayPosition::class,
        ];
    }

    /**
     * The block of the organisation the rule starts from.
     *
     * @return BelongsTo<AddressBlock, $this>
     */
    public function aggregateBlock(): BelongsTo
    {
        return $this->belongsTo(AddressBlock::class, 'aggregate_block_id');
    }

    /**
     * The ranges a subnet of a VLAN is usually cut into, from the lowest.
     *
     * @return HasMany<AddressingRuleRange, $this>
     */
    public function ranges(): HasMany
    {
        return $this->hasMany(AddressingRuleRange::class)->orderBy('start_offset');
    }

    /**
     * How the activity log names the rule, kept after it is deleted:
     * "10.0.0.0/8, /16, /24", or "192.168.0.0/16, /24" without a level of
     * sites.
     */
    public function auditLabel(): string
    {
        return $this->site_prefix_length === null
            ? $this->aggregateBlock->cidr().', /'.$this->vlan_prefix_length
            : $this->aggregateBlock->cidr().', /'.$this->site_prefix_length.', /'.$this->vlan_prefix_length;
    }

    /**
     * The values the activity log compares before and after a change. The
     * ranges are part of the rule: they are written with it, on one line.
     *
     * @return array<string, scalar|null>
     */
    public function auditValues(): array
    {
        $ranges = $this->ranges
            ->map(fn (AddressingRuleRange $range): string => $range->label())
            ->implode(' ; ');

        return [
            'aggregate_block' => $this->aggregateBlock->cidr(),
            'site_prefix_length' => $this->site_prefix_length,
            'vlan_prefix_length' => $this->vlan_prefix_length,
            'gateway_position' => $this->gateway_position->value,
            'ranges' => $ranges === '' ? null : $ranges,
        ];
    }
}
