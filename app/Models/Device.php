<?php

// torsade/app/Models/Device.php

namespace App\Models;

use App\Contracts\Auditable;
use App\Enums\DeviceType;
use App\Enums\Status;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

/**
 * A machine of a site: a firewall, a switch, a server, a printer. The IP
 * addresses attached to it are its interfaces: a machine with two addresses
 * is one device and two addresses. Its boxes are its members, which hold
 * its ports: a stack is one device with several members.
 *
 * @property int $id
 * @property int $site_id
 * @property int|null $room_id
 * @property string $name
 * @property DeviceType $type
 * @property string|null $manufacturer
 * @property string|null $model
 * @property string|null $serial_number
 * @property string|null $description
 * @property Status $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Site $site
 * @property-read Room|null $room
 * @property-read Collection<int, PortVlan> $carriedByPorts
 * @property-read Collection<int, PortVlan> $carriedByAggregates
 */
#[Fillable(['site_id', 'room_id', 'name', 'type', 'manufacturer', 'model', 'serial_number', 'description', 'status'])]
class Device extends Model implements Auditable
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory;

    /**
     * The longest name a device can have.
     */
    public const int MAX_NAME_LENGTH = 100;

    /**
     * Defaults of a new model, matching the defaults of the columns.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => Status::Active->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DeviceType::class,
            'status' => Status::class,
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * The room the device is kept in, if it is known.
     *
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * The interfaces of the device.
     *
     * @return HasMany<IpAddress, $this>
     */
    public function ipAddresses(): HasMany
    {
        return $this->hasMany(IpAddress::class);
    }

    /**
     * The boxes the device is made of, by number. A device without a
     * member has no port.
     *
     * @return HasMany<DeviceMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(DeviceMember::class)->orderBy('position');
    }

    /**
     * The ports of all its members.
     *
     * @return HasMany<Port, $this>
     */
    public function ports(): HasMany
    {
        return $this->hasMany(Port::class);
    }

    /**
     * Its aggregates of ports, by name.
     *
     * @return HasMany<PortAggregate, $this>
     */
    public function aggregates(): HasMany
    {
        return $this->hasMany(PortAggregate::class)->orderBy('name');
    }

    /**
     * The VLANs its ports carry, one line per port and VLAN.
     *
     * @return HasManyThrough<PortVlan, Port, $this>
     */
    public function carriedByPorts(): HasManyThrough
    {
        return $this->hasManyThrough(PortVlan::class, Port::class);
    }

    /**
     * The VLANs its aggregates carry, one line per aggregate and VLAN.
     *
     * @return HasManyThrough<PortVlan, PortAggregate, $this>
     */
    public function carriedByAggregates(): HasManyThrough
    {
        return $this->hasManyThrough(PortVlan::class, PortAggregate::class);
    }

    /**
     * Whether one of its ports or of its aggregates carries the VLAN,
     * whatever the mode: the device is then plugged into its network.
     * "carriedByPorts" and "carriedByAggregates" must be loaded, with that
     * VLAN among them if they are carried.
     */
    public function carriesVlan(int $vlanId): bool
    {
        return $this->carriedByPorts->contains('vlan_id', $vlanId)
            || $this->carriedByAggregates->contains('vlan_id', $vlanId);
    }

    /**
     * The name and the site together: two sites may each have a "SW-01".
     */
    public function label(): string
    {
        return $this->name.' ('.$this->site->name.')';
    }

    /**
     * How the activity log names the device, kept after it is deleted.
     */
    public function auditLabel(): string
    {
        return $this->label();
    }

    /**
     * The values the activity log compares before and after a change.
     *
     * @return array<string, scalar|null>
     */
    public function auditValues(): array
    {
        return [
            'site' => $this->site->name,
            'room' => $this->room?->path(),
            'name' => $this->name,
            'type' => $this->type->value,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'serial_number' => $this->serial_number,
            'status' => $this->status->value,
            'description' => $this->description,
        ];
    }
}
