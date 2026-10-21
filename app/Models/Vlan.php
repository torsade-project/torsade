<?php

// torsade/app/Models/Vlan.php

namespace App\Models;

use App\Contracts\Auditable;
use App\Enums\Status;
use Database\Factories\VlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A VLAN of a site. Whether it is tagged or untagged is not a property of
 * the VLAN: it belongs to each port, or aggregate of ports, that carries
 * it.
 *
 * @property int $id
 * @property int $site_id
 * @property int|null $catalog_vlan_id
 * @property int $vid
 * @property string $name
 * @property string|null $description
 * @property Status $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Site $site
 * @property-read CatalogVlan|null $catalogVlan
 * @property-read Subnet|null $subnet
 */
#[Fillable(['site_id', 'catalog_vlan_id', 'vid', 'name', 'description', 'status'])]
class Vlan extends Model implements Auditable
{
    /** @use HasFactory<VlanFactory> */
    use HasFactory;

    public const int MIN_VID = 1;

    public const int MAX_VID = 4094;

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
            'vid' => 'integer',
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
     * The entry of the catalogue this VLAN follows, if any. The VLAN may
     * differ from it by its number or its name.
     *
     * @return BelongsTo<CatalogVlan, $this>
     */
    public function catalogVlan(): BelongsTo
    {
        return $this->belongsTo(CatalogVlan::class);
    }

    /**
     * A VLAN carries one subnet at most.
     *
     * @return HasOne<Subnet, $this>
     */
    public function subnet(): HasOne
    {
        return $this->hasOne(Subnet::class);
    }

    /**
     * The ports and the aggregates that carry the VLAN, tagged or not.
     *
     * @return HasMany<PortVlan, $this>
     */
    public function portVlans(): HasMany
    {
        return $this->hasMany(PortVlan::class);
    }

    /**
     * The number and the name together, as people say it: "10 — Offices".
     */
    public function label(): string
    {
        return $this->vid.' — '.$this->name;
    }

    /**
     * How the activity log names the VLAN, kept after it is deleted.
     */
    public function auditLabel(): string
    {
        return 'VLAN '.$this->label().' ('.$this->site->name.')';
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
            'catalog' => $this->catalogVlan?->label(),
            'vid' => $this->vid,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status->value,
        ];
    }
}
