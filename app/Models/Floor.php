<?php

// torsade/app/Models/Floor.php

namespace App\Models;

use App\Contracts\Auditable;
use Database\Factories\FloorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A floor of a building. Its level orders the floors: -1 for a basement, 0
 * for the ground floor.
 *
 * @property int $id
 * @property int $building_id
 * @property int $level
 * @property string $name
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Building $building
 */
#[Fillable(['building_id', 'level', 'name', 'description'])]
class Floor extends Model implements Auditable
{
    /** @use HasFactory<FloorFactory> */
    use HasFactory;

    /**
     * The lowest and the highest level a floor can have.
     */
    public const int MIN_LEVEL = -9;

    public const int MAX_LEVEL = 99;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Building, $this>
     */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /**
     * @return HasMany<Room, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /**
     * The wall jacks of the floor.
     *
     * @return HasMany<Jack, $this>
     */
    public function jacks(): HasMany
    {
        return $this->hasMany(Jack::class);
    }

    /**
     * Where the floor is: "Main building › Ground floor". The building must
     * be loaded.
     */
    public function path(): string
    {
        return $this->building->name.' › '.$this->name;
    }

    /**
     * How the activity log names the floor, kept after it is deleted.
     */
    public function auditLabel(): string
    {
        return $this->path().' ('.$this->building->site->name.')';
    }

    /**
     * The values the activity log compares before and after a change.
     *
     * @return array<string, scalar|null>
     */
    public function auditValues(): array
    {
        return [
            'building' => $this->building->name,
            'level' => $this->level,
            'name' => $this->name,
            'description' => $this->description,
        ];
    }
}
