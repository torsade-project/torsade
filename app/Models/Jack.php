<?php

// torsade/app/Models/Jack.php

namespace App\Models;

use App\Contracts\Auditable;
use App\Enums\Status;
use Database\Factories\JackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A wall jack, on the floor where it is. Its horizontal cable, laid once,
 * ends on a port of a patch panel of the same site. A device whose ports
 * are documented is plugged into it by a jack cord, one at most; the other
 * machines plugged into it are told by their addresses.
 *
 * @property int $id
 * @property int $floor_id
 * @property string $label
 * @property string|null $location
 * @property int|null $patch_panel_port_id
 * @property string|null $description
 * @property Status $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Floor $floor
 * @property-read PatchPanelPort|null $panelPort
 * @property-read JackCord|null $cord
 * @property-read Collection<int, IpAddress> $ipAddresses
 */
#[Fillable(['floor_id', 'label', 'location', 'patch_panel_port_id', 'description', 'status'])]
class Jack extends Model implements Auditable
{
    /** @use HasFactory<JackFactory> */
    use HasFactory;

    /**
     * The longest label a jack can have.
     */
    public const int MAX_LABEL_LENGTH = 32;

    /**
     * The longest location a jack can have.
     */
    public const int MAX_LOCATION_LENGTH = 100;

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
            'status' => Status::class,
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
     * The port of a panel its horizontal cable ends on, if it is cabled.
     *
     * @return BelongsTo<PatchPanelPort, $this>
     */
    public function panelPort(): BelongsTo
    {
        return $this->belongsTo(PatchPanelPort::class, 'patch_panel_port_id');
    }

    /**
     * The jack cord of the device plugged into it, if any: one at most.
     *
     * @return HasOne<JackCord, $this>
     */
    public function cord(): HasOne
    {
        return $this->hasOne(JackCord::class);
    }

    /**
     * The addresses of the machines plugged into it.
     *
     * @return HasMany<IpAddress, $this>
     */
    public function ipAddresses(): HasMany
    {
        return $this->hasMany(IpAddress::class);
    }

    /**
     * Where the jack is, as a jack is always written: "Main building › 1st
     * floor › B.15". The floor and its building must be loaded.
     */
    public function path(): string
    {
        return $this->floor->path().' › '.$this->label;
    }

    /**
     * The site the jack belongs to, through its floor and its building.
     */
    public function siteId(): int
    {
        return $this->floor->building->site_id;
    }

    /**
     * How the activity log names the jack, kept after it is deleted.
     */
    public function auditLabel(): string
    {
        return $this->path().' ('.$this->floor->building->site->name.')';
    }

    /**
     * The values the activity log compares before and after a change. Its
     * port of a panel is part of it: the horizontal cable is laid with it.
     *
     * @return array<string, scalar|null>
     */
    public function auditValues(): array
    {
        return [
            'floor' => $this->floor->path(),
            'label' => $this->label,
            'location' => $this->location,
            'panel_port' => $this->panelPort?->auditName(),
            'status' => $this->status->value,
            'description' => $this->description,
        ];
    }
}
