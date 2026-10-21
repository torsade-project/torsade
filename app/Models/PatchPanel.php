<?php

// torsade/app/Models/PatchPanel.php

namespace App\Models;

use App\Contracts\Auditable;
use App\Enums\PanelConnector;
use App\Enums\Status;
use Database\Factories\PatchPanelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A patch panel, kept in a room. The horizontal cables of the jacks end on
 * its ports, numbered from 1, and the patch cords leave from them towards
 * the ports of devices.
 *
 * @property int $id
 * @property int $room_id
 * @property string $name
 * @property int $port_count
 * @property PanelConnector $connector
 * @property string|null $category
 * @property string|null $description
 * @property Status $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Room $room
 * @property-read Collection<int, PatchPanelPort> $ports
 */
#[Fillable(['room_id', 'name', 'port_count', 'connector', 'category', 'description', 'status'])]
class PatchPanel extends Model implements Auditable
{
    /** @use HasFactory<PatchPanelFactory> */
    use HasFactory;

    /**
     * The longest name a panel can have.
     */
    public const int MAX_NAME_LENGTH = 64;

    /**
     * The most ports a panel can have.
     */
    public const int MAX_PORTS = 96;

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
            'port_count' => 'integer',
            'connector' => PanelConnector::class,
            'status' => Status::class,
        ];
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Its ports, by number.
     *
     * @return HasMany<PatchPanelPort, $this>
     */
    public function ports(): HasMany
    {
        return $this->hasMany(PatchPanelPort::class)->orderBy('number');
    }

    /**
     * The site the panel belongs to, through its room. The room must be
     * loaded with its floor and its building.
     */
    public function siteId(): int
    {
        return $this->room->siteId();
    }

    /**
     * How the activity log names the panel, kept after it is deleted: where
     * it is, then its name.
     */
    public function auditLabel(): string
    {
        return $this->room->path().' › '.$this->name.' ('.$this->room->floor->building->site->name.')';
    }

    /**
     * The values the activity log compares before and after a change. Its
     * ports are told by their number: they are created with it, added or
     * taken away when that number changes, and deleted with it.
     *
     * @return array<string, scalar|null>
     */
    public function auditValues(): array
    {
        return [
            'room' => $this->room->path(),
            'name' => $this->name,
            'port_count' => $this->port_count,
            'connector' => $this->connector->value,
            'category' => $this->category,
            'status' => $this->status->value,
            'description' => $this->description,
        ];
    }
}
