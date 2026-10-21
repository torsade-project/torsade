<?php

// torsade/app/Models/CatalogVlan.php

namespace App\Models;

use App\Contracts\Auditable;
use Database\Factories\CatalogVlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An entry of the VLAN catalogue of the organisation: the number and the
 * name a VLAN is expected to have on every site. It is a standard, not an
 * object of the network: the VLANs of the sites are attached to it, and may
 * differ from it.
 *
 * @property int $id
 * @property int $vid
 * @property string $name
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['vid', 'name', 'description'])]
class CatalogVlan extends Model implements Auditable
{
    /** @use HasFactory<CatalogVlanFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vid' => 'integer',
        ];
    }

    /**
     * The VLANs of the sites that follow this entry, one per site at most.
     *
     * @return HasMany<Vlan, $this>
     */
    public function vlans(): HasMany
    {
        return $this->hasMany(Vlan::class);
    }

    /**
     * The number and the name together: "10 — Offices".
     */
    public function label(): string
    {
        return $this->vid.' — '.$this->name;
    }

    /**
     * How the activity log names the entry, kept after it is deleted.
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
            'vid' => $this->vid,
            'name' => $this->name,
            'description' => $this->description,
        ];
    }
}
