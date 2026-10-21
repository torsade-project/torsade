<?php

// torsade/app/Models/PortAggregate.php

namespace App\Models;

use App\Contracts\Auditable;
use App\Enums\AggregateProtocol;
use App\Enums\PortMode;
use App\Models\Concerns\CarriesVlans;
use Database\Factories\PortAggregateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Several ports of one device used as one link, on one or several of its
 * members. The mode and the VLANs are set on the aggregate: its ports have
 * none of their own, so that there are not two configurations to keep
 * alike.
 *
 * @property int $id
 * @property int $device_id
 * @property string $name
 * @property AggregateProtocol $protocol
 * @property PortMode|null $mode
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Device $device
 */
#[Fillable(['device_id', 'name', 'protocol', 'mode', 'description'])]
class PortAggregate extends Model implements Auditable
{
    use CarriesVlans;

    /** @use HasFactory<PortAggregateFactory> */
    use HasFactory;

    /**
     * The longest name an aggregate can have.
     */
    public const int MAX_NAME_LENGTH = 32;

    /**
     * Defaults of a new model, matching the defaults of the columns.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'protocol' => AggregateProtocol::Lacp->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'protocol' => AggregateProtocol::class,
            'mode' => PortMode::class,
        ];
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Its ports, member by member, in their order.
     *
     * @return HasMany<Port, $this>
     */
    public function ports(): HasMany
    {
        return $this->hasMany(Port::class)->orderBy('device_member_id')->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<PortVlan, $this>
     */
    public function portVlans(): HasMany
    {
        return $this->hasMany(PortVlan::class);
    }

    /**
     * How the activity log names the aggregate, kept after it is deleted:
     * its device, then its name.
     */
    public function auditLabel(): string
    {
        return $this->device->label().' › '.$this->name;
    }

    /**
     * The values the activity log compares before and after a change. Its
     * ports and its VLANs are part of the aggregate: they are written with
     * it.
     *
     * @return array<string, scalar|null>
     */
    public function auditValues(): array
    {
        $ports = $this->ports->map(fn (Port $port): string => $port->name)->implode(', ');

        return [
            'device' => $this->device->label(),
            'name' => $this->name,
            'protocol' => $this->protocol->value,
            'ports' => $ports === '' ? null : $ports,
            'mode' => $this->mode?->value,
            'vlans' => $this->vlanAuditValue(),
            'description' => $this->description,
        ];
    }
}
