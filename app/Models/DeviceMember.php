<?php

// torsade/app/Models/DeviceMember.php

namespace App\Models;

use App\Contracts\Auditable;
use App\Enums\MemberRole;
use Database\Factories\DeviceMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One of the boxes a device is made of. A switch alone has one member; a
 * stack has several, managed as one switch, and so has a pair of firewalls
 * managed as one. The ports belong to the members.
 *
 * @property int $id
 * @property int $device_id
 * @property int $position
 * @property MemberRole $role
 * @property int|null $device_template_id
 * @property string|null $serial_number
 * @property int|null $room_id
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Device $device
 * @property-read DeviceTemplate|null $template
 * @property-read Room|null $room
 */
#[Fillable(['device_id', 'position', 'role', 'device_template_id', 'serial_number', 'room_id', 'description'])]
class DeviceMember extends Model implements Auditable
{
    /** @use HasFactory<DeviceMemberFactory> */
    use HasFactory;

    /**
     * The lowest and the highest number a member can have in its device.
     */
    public const int MIN_POSITION = 1;

    public const int MAX_POSITION = 16;

    /**
     * Defaults of a new model, matching the defaults of the columns.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => MemberRole::Member->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'role' => MemberRole::class,
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
     * The template its ports were copied from, if any.
     *
     * @return BelongsTo<DeviceTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DeviceTemplate::class, 'device_template_id');
    }

    /**
     * The room the member is kept in, when it is not the one of its device.
     *
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Its ports, in their order.
     *
     * @return HasMany<Port, $this>
     */
    public function ports(): HasMany
    {
        return $this->hasMany(Port::class)->orderBy('position')->orderBy('id');
    }

    /**
     * How the activity log names the member, kept after it is deleted:
     * its device, then its number.
     */
    public function auditLabel(): string
    {
        return $this->device->label().' › '.__('Member :position', ['position' => $this->position]);
    }

    /**
     * The values the activity log compares before and after a change.
     *
     * @return array<string, scalar|null>
     */
    public function auditValues(): array
    {
        return [
            'device' => $this->device->label(),
            'position' => $this->position,
            'role' => $this->role->value,
            'template' => $this->template?->name,
            'serial_number' => $this->serial_number,
            'room' => $this->room?->path(),
            'description' => $this->description,
        ];
    }
}
