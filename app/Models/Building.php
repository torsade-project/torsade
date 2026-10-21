<?php

// torsade/app/Models/Building.php

namespace App\Models;

use App\Contracts\Auditable;
use Database\Factories\BuildingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A building of a site. Buildings, floors and rooms are places, not objects
 * of the network: they have no status.
 *
 * @property int $id
 * @property int $site_id
 * @property string $name
 * @property string|null $code
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Site $site
 */
#[Fillable(['site_id', 'name', 'code', 'description'])]
class Building extends Model implements Auditable
{
    /** @use HasFactory<BuildingFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return HasMany<Floor, $this>
     */
    public function floors(): HasMany
    {
        return $this->hasMany(Floor::class);
    }

    /**
     * How the activity log names the building, kept after it is deleted.
     */
    public function auditLabel(): string
    {
        return $this->name.' ('.$this->site->name.')';
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
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
        ];
    }
}
