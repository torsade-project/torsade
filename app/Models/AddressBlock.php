<?php

// torsade/app/Models/AddressBlock.php

namespace App\Models;

use App\Casts\AsIpAddress;
use App\Contracts\Auditable;
use App\Enums\Status;
use Database\Factories\AddressBlockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A block of addresses set aside for the organisation, or for one site when
 * it has one. No key links a block to the subnets it holds: which one
 * contains which follows from the addresses.
 *
 * @property int $id
 * @property int|null $site_id
 * @property int $ip_version
 * @property string $network
 * @property int $prefix_length
 * @property string|null $name
 * @property string|null $description
 * @property Status $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Site|null $site
 */
// The IP version is not assignable: it follows from the address.
#[Fillable(['site_id', 'network', 'prefix_length', 'name', 'description', 'status'])]
class AddressBlock extends Model implements Auditable
{
    /** @use HasFactory<AddressBlockFactory> */
    use HasFactory;

    /**
     * Defaults of a new model, matching the defaults of the columns.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'ip_version' => 4,
        'status' => Status::Active->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ip_version' => 'integer',
            'network' => AsIpAddress::class,
            'prefix_length' => 'integer',
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
     * The block in CIDR notation: "10.1.0.0/16".
     */
    public function cidr(): string
    {
        return $this->network.'/'.$this->prefix_length;
    }

    /**
     * How the activity log names the block, kept after it is deleted.
     */
    public function auditLabel(): string
    {
        return $this->cidr();
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
            'network' => $this->network,
            'prefix_length' => $this->prefix_length,
            'site' => $this->site?->name,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status->value,
        ];
    }
}
