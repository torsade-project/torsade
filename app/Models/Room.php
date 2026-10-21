<?php

// torsade/app/Models/Room.php

namespace App\Models;

use App\Contracts\Auditable;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A room of a floor. A technical room holds a rack, switches or patch
 * panels; a device may as well be kept in any other room.
 *
 * @property int $id
 * @property int $floor_id
 * @property string $name
 * @property bool $is_technical
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Floor $floor
 */
#[Fillable(['floor_id', 'name', 'is_technical', 'description'])]
class Room extends Model implements Auditable
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    /**
     * Defaults of a new model, matching the defaults of the columns.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_technical' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_technical' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Floor, $this>
     */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    /**
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * The patch panels kept in the room, by name.
     *
     * @return HasMany<PatchPanel, $this>
     */
    public function patchPanels(): HasMany
    {
        return $this->hasMany(PatchPanel::class)->orderBy('name');
    }

    /**
     * Where the room is, as a place is always written: "Main building ›
     * Ground floor › Technical room". The floor and its building must be
     * loaded.
     */
    public function path(): string
    {
        return $this->floor->path().' › '.$this->name;
    }

    /**
     * The site the room belongs to, through its floor and its building.
     */
    public function siteId(): int
    {
        return $this->floor->building->site_id;
    }

    /**
     * How the activity log names the room, kept after it is deleted.
     */
    public function auditLabel(): string
    {
        return $this->path().' ('.$this->floor->building->site->name.')';
    }

    /**
     * The values the activity log compares before and after a change.
     *
     * @return array<string, scalar|null>
     */
    public function auditValues(): array
    {
        return [
            'floor' => $this->floor->path(),
            'name' => $this->name,
            'is_technical' => $this->is_technical,
            'description' => $this->description,
        ];
    }
}
