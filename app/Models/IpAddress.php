<?php

// torsade/app/Models/IpAddress.php

namespace App\Models;

use App\Casts\AsIpAddress;
use App\Contracts\Auditable;
use App\Enums\DeviceType;
use App\Enums\IpAssignment;
use App\Enums\Status;
use Database\Factories\IpAddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A documented IP address. Only the address is mandatory. Attached to a
 * device, it is one of its interfaces, and it keeps its host name and its
 * type, which describe that interface; the machines that are not declared
 * as devices are known by the host name of their addresses alone.
 *
 * @property int $id
 * @property int $subnet_id
 * @property string $address
 * @property string|null $hostname
 * @property string|null $mac_address
 * @property DeviceType|null $device_type
 * @property IpAssignment $assignment
 * @property int|null $device_id
 * @property int|null $jack_id
 * @property string|null $description
 * @property Status $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Subnet $subnet
 * @property-read Device|null $device
 * @property-read Jack|null $jack
 */
#[Fillable(['subnet_id', 'address', 'hostname', 'mac_address', 'device_type', 'assignment', 'device_id', 'jack_id', 'description', 'status'])]
class IpAddress extends Model implements Auditable
{
    /** @use HasFactory<IpAddressFactory> */
    use HasFactory;

    /**
     * Defaults of a new model, matching the defaults of the columns.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'assignment' => IpAssignment::Static->value,
        'status' => Status::Active->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'address' => AsIpAddress::class,
            'device_type' => DeviceType::class,
            'assignment' => IpAssignment::class,
            'status' => Status::class,
        ];
    }

    /**
     * @return BelongsTo<Subnet, $this>
     */
    public function subnet(): BelongsTo
    {
        return $this->belongsTo(Subnet::class);
    }

    /**
     * The device the address is an interface of, if any.
     *
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * The jack the machine is plugged into, if it is known.
     *
     * @return BelongsTo<Jack, $this>
     */
    public function jack(): BelongsTo
    {
        return $this->belongsTo(Jack::class);
    }

    /**
     * How the activity log names the address, kept after it is deleted.
     */
    public function auditLabel(): string
    {
        return $this->address;
    }

    /**
     * The values the activity log compares before and after a change.
     * Addresses are text here, never the stored binary form.
     *
     * @return array<string, scalar|null>
     */
    public function auditValues(): array
    {
        return [
            'subnet' => $this->subnet->cidr(),
            'address' => $this->address,
            'hostname' => $this->hostname,
            'mac_address' => $this->mac_address,
            'device_type' => $this->device_type?->value,
            'assignment' => $this->assignment->value,
            // With its site: two sites may each have a device of that name.
            'device' => $this->device?->label(),
            // With its place and its site: two floors may each have a "B.15".
            'jack' => $this->jack?->auditLabel(),
            'status' => $this->status->value,
            'description' => $this->description,
        ];
    }
}
