<?php

// torsade/app/Models/Site.php

namespace App\Models;

use App\Contracts\Auditable;
use App\Enums\Status;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A place with its own network. VLAN numbers and names are unique within a
 * site, not across the installation. A site has a status like the objects
 * of its network: it can be designed before it exists, and stay documented
 * once closed.
 *
 * @property int $id
 * @property string $name
 * @property string|null $code
 * @property int|null $number
 * @property Status $status
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'code', 'number', 'status', 'description'])]
class Site extends Model implements Auditable
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    /**
     * The lowest and the highest number a site can be given.
     */
    public const int MIN_NUMBER = 0;

    public const int MAX_NUMBER = 65535;

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
            'number' => 'integer',
            'status' => Status::class,
        ];
    }

    /**
     * @return HasMany<Vlan, $this>
     */
    public function vlans(): HasMany
    {
        return $this->hasMany(Vlan::class);
    }

    /**
     * The subnets of the site, with or without a VLAN.
     *
     * @return HasMany<Subnet, $this>
     */
    public function subnets(): HasMany
    {
        return $this->hasMany(Subnet::class);
    }

    /**
     * The blocks of addresses set aside for the site.
     *
     * @return HasMany<AddressBlock, $this>
     */
    public function addressBlocks(): HasMany
    {
        return $this->hasMany(AddressBlock::class);
    }

    /**
     * @return HasMany<Building, $this>
     */
    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class);
    }

    /**
     * The machines of the site, kept in one of its rooms or not.
     *
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * How a short text names the site, as the summary of a port does before
     * the VLANs of the site: its code, or its name when it has no code.
     */
    public function shortName(): string
    {
        return $this->code === null || $this->code === '' ? $this->name : $this->code;
    }

    /**
     * How the activity log names the site, kept after it is deleted.
     */
    public function auditLabel(): string
    {
        return $this->name;
    }

    /**
     * The values the activity log compares before and after a change.
     *
     * @return array<string, scalar|null>
     */
    public function auditValues(): array
    {
        return [
            'name' => $this->name,
            'code' => $this->code,
            'number' => $this->number,
            'status' => $this->status->value,
            'description' => $this->description,
        ];
    }
}
