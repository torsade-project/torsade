<?php

// torsade/app/Models/IpRange.php

namespace App\Models;

use App\Casts\AsIpAddress;
use App\Contracts\Auditable;
use App\Enums\RangeKind;
use Database\Factories\IpRangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A range of addresses of a subnet, set aside for one use: handed out by a
 * DHCP server, kept for static addresses, or reserved. Static addresses are
 * kept out of a DHCP range.
 *
 * @property int $id
 * @property int $subnet_id
 * @property RangeKind $kind
 * @property string $start_address
 * @property string $end_address
 * @property string|null $name
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Subnet $subnet
 */
#[Fillable(['subnet_id', 'kind', 'start_address', 'end_address', 'name', 'description'])]
class IpRange extends Model implements Auditable
{
    /** @use HasFactory<IpRangeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => RangeKind::class,
            'start_address' => AsIpAddress::class,
            'end_address' => AsIpAddress::class,
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
     * The range as it is written: "192.168.100.100 – 192.168.100.199".
     */
    public function label(): string
    {
        return $this->start_address.' – '.$this->end_address;
    }

    /**
     * How the activity log names the range, kept after it is deleted.
     */
    public function auditLabel(): string
    {
        return $this->label();
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
            'kind' => $this->kind->value,
            'start_address' => $this->start_address,
            'end_address' => $this->end_address,
            'name' => $this->name,
            'description' => $this->description,
        ];
    }
}
