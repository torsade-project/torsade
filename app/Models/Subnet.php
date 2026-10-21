<?php

// torsade/app/Models/Subnet.php

namespace App\Models;

use App\Casts\AsIpAddress;
use App\Contracts\Auditable;
use App\Enums\RangeKind;
use App\Enums\Status;
use App\Enums\SubnetKind;
use Database\Factories\SubnetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An IP subnet, with or without a VLAN. The gateway and the ranges belong
 * to the subnet, not to the VLAN. Its site is the site of its VLAN when it
 * has one.
 *
 * @property int $id
 * @property int|null $vlan_id
 * @property int|null $site_id
 * @property SubnetKind $kind
 * @property int $ip_version
 * @property string $network
 * @property int $prefix_length
 * @property string|null $gateway
 * @property string|null $name
 * @property string|null $description
 * @property Status $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Vlan|null $vlan
 * @property-read Site|null $site
 */
// The IP version is not assignable: it follows from the address.
#[Fillable(['vlan_id', 'site_id', 'kind', 'network', 'prefix_length', 'gateway', 'name', 'description', 'status'])]
class Subnet extends Model implements Auditable
{
    /** @use HasFactory<SubnetFactory> */
    use HasFactory;

    /**
     * Defaults of a new model, matching the defaults of the columns.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => SubnetKind::Segment->value,
        'ip_version' => 4,
        'status' => Status::Active->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => SubnetKind::class,
            'ip_version' => 'integer',
            'network' => AsIpAddress::class,
            'prefix_length' => 'integer',
            'gateway' => AsIpAddress::class,
            'status' => Status::class,
        ];
    }

    /**
     * @return BelongsTo<Vlan, $this>
     */
    public function vlan(): BelongsTo
    {
        return $this->belongsTo(Vlan::class);
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return HasMany<IpRange, $this>
     */
    public function ipRanges(): HasMany
    {
        return $this->hasMany(IpRange::class);
    }

    /**
     * The ranges handed out by a DHCP server: the gateway and the static
     * addresses stay out of them.
     *
     * @return HasMany<IpRange, $this>
     */
    public function dhcpRanges(): HasMany
    {
        return $this->ipRanges()->where('kind', RangeKind::Dhcp);
    }

    /**
     * @return HasMany<IpAddress, $this>
     */
    public function ipAddresses(): HasMany
    {
        return $this->hasMany(IpAddress::class);
    }

    /**
     * The subnet in CIDR notation: "192.168.100.0/24".
     */
    public function cidr(): string
    {
        return $this->network.'/'.$this->prefix_length;
    }

    /**
     * How the activity log names the subnet, kept after it is deleted.
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
            'kind' => $this->kind->value,
            'gateway' => $this->gateway,
            'vlan' => $this->vlan?->auditLabel(),
            'site' => $this->site?->name,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status->value,
        ];
    }
}
