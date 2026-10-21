<?php

// torsade/app/Models/Port.php

namespace App\Models;

use App\Contracts\Auditable;
use App\Enums\PoeType;
use App\Enums\PortConnector;
use App\Enums\PortMode;
use App\Enums\PortSpeed;
use App\Models\Concerns\CarriesVlans;
use Database\Factories\PortFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A port of a member of a device. Its name is free, as the device writes
 * it, and unique within the device. A port of an aggregate has neither a
 * mode nor VLANs of its own: they are those of the aggregate.
 *
 * @property int $id
 * @property int $device_member_id
 * @property int $device_id
 * @property string $name
 * @property int $position
 * @property PortConnector $connector
 * @property PortSpeed $max_speed
 * @property PoeType $poe_type
 * @property string|null $poe_label
 * @property bool $enabled
 * @property PortMode|null $mode
 * @property int|null $port_aggregate_id
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read DeviceMember $member
 * @property-read Device $device
 * @property-read PortAggregate|null $aggregate
 * @property-read LinkEnd|null $linkEnd
 * @property-read PatchCord|null $patchCord
 * @property-read JackCord|null $jackCord
 */
#[Fillable(['device_member_id', 'name', 'position', 'connector', 'max_speed', 'poe_type', 'poe_label', 'enabled', 'mode', 'port_aggregate_id', 'description'])]
class Port extends Model implements Auditable
{
    use CarriesVlans;

    /** @use HasFactory<PortFactory> */
    use HasFactory;

    /**
     * The longest name a port can have.
     */
    public const int MAX_NAME_LENGTH = 32;

    /**
     * The highest place a port can be given in the order of its member.
     */
    public const int MAX_POSITION = 9999;

    /**
     * Defaults of a new model, matching the defaults of the columns.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'poe_type' => PoeType::None->value,
        'enabled' => true,
    ];

    protected static function booted(): void
    {
        // The device of a port is the one of its member, always: it is
        // never given, and written here for the database to keep the names
        // of ports unique within a device.
        static::saving(function (Port $port): void {
            $port->device_id = $port->member->device_id;
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'connector' => PortConnector::class,
            'max_speed' => PortSpeed::class,
            'poe_type' => PoeType::class,
            'enabled' => 'boolean',
            'mode' => PortMode::class,
        ];
    }

    /**
     * @return BelongsTo<DeviceMember, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(DeviceMember::class, 'device_member_id');
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * The aggregate the port is part of, if any.
     *
     * @return BelongsTo<PortAggregate, $this>
     */
    public function aggregate(): BelongsTo
    {
        return $this->belongsTo(PortAggregate::class, 'port_aggregate_id');
    }

    /**
     * @return HasMany<PortVlan, $this>
     */
    public function portVlans(): HasMany
    {
        return $this->hasMany(PortVlan::class);
    }

    /**
     * The end of a link the port is, if it is linked: one at most.
     *
     * @return HasOne<LinkEnd, $this>
     */
    public function linkEnd(): HasOne
    {
        return $this->hasOne(LinkEnd::class);
    }

    /**
     * The patch cord that comes from a port of a panel, if the port is
     * patched: one at most.
     *
     * @return HasOne<PatchCord, $this>
     */
    public function patchCord(): HasOne
    {
        return $this->hasOne(PatchCord::class);
    }

    /**
     * The jack cord that plugs the port into a wall jack, if it is plugged:
     * one at most, and never with a patch cord, a port having one
     * connector.
     *
     * @return HasOne<JackCord, $this>
     */
    public function jackCord(): HasOne
    {
        return $this->hasOne(JackCord::class);
    }

    /**
     * The port at the other end of its link, if it is linked.
     */
    public function linkedPort(): ?Port
    {
        return $this->linkEnd?->link->ends
            ->first(fn (LinkEnd $end): bool => $end->port_id !== $this->id)
            ?->port;
    }

    /**
     * How the activity log names the port, kept after it is deleted: its
     * device, then its name.
     */
    public function auditLabel(): string
    {
        return $this->device->label().' › '.$this->name;
    }

    /**
     * The values the activity log compares before and after a change. The
     * VLANs a port carries are part of it: they are written with it.
     *
     * @return array<string, scalar|null>
     */
    public function auditValues(): array
    {
        return [
            'device' => $this->device->label(),
            'member' => $this->member->position,
            'name' => $this->name,
            'order' => $this->position,
            'connector' => $this->connector->value,
            'max_speed' => $this->max_speed->value,
            'poe_type' => $this->poe_type->value,
            'poe_label' => $this->poe_label,
            'enabled' => $this->enabled,
            'aggregate' => $this->aggregate?->name,
            'mode' => $this->mode?->value,
            'vlans' => $this->vlanAuditValue(),
            'description' => $this->description,
        ];
    }
}
