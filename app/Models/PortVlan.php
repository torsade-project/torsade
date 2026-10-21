<?php

// torsade/app/Models/PortVlan.php

namespace App\Models;

use Database\Factories\PortVlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A VLAN carried by a port or by an aggregate of ports, tagged or not. The
 * line belongs to one of the two, never to both.
 *
 * @property int $id
 * @property int|null $port_id
 * @property int|null $port_aggregate_id
 * @property int $vlan_id
 * @property bool $tagged
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Port|null $port
 * @property-read PortAggregate|null $aggregate
 * @property-read Vlan $vlan
 */
#[Fillable(['port_id', 'port_aggregate_id', 'vlan_id', 'tagged'])]
class PortVlan extends Model
{
    /** @use HasFactory<PortVlanFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tagged' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Port, $this>
     */
    public function port(): BelongsTo
    {
        return $this->belongsTo(Port::class);
    }

    /**
     * @return BelongsTo<PortAggregate, $this>
     */
    public function aggregate(): BelongsTo
    {
        return $this->belongsTo(PortAggregate::class, 'port_aggregate_id');
    }

    /**
     * @return BelongsTo<Vlan, $this>
     */
    public function vlan(): BelongsTo
    {
        return $this->belongsTo(Vlan::class);
    }
}
