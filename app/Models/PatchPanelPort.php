<?php

// torsade/app/Models/PatchPanelPort.php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A port of a patch panel: the horizontal cable of one jack ends there at
 * most, and one patch cord leaves from it at most.
 *
 * @property int $id
 * @property int $patch_panel_id
 * @property int $number
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PatchPanel $panel
 * @property-read Jack|null $jack
 * @property-read PatchCord|null $cord
 */
#[Fillable(['patch_panel_id', 'number'])]
class PatchPanelPort extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PatchPanel, $this>
     */
    public function panel(): BelongsTo
    {
        return $this->belongsTo(PatchPanel::class, 'patch_panel_id');
    }

    /**
     * The jack whose horizontal cable ends on the port, if any.
     *
     * @return HasOne<Jack, $this>
     */
    public function jack(): HasOne
    {
        return $this->hasOne(Jack::class);
    }

    /**
     * The patch cord that leaves from the port, if any.
     *
     * @return HasOne<PatchCord, $this>
     */
    public function cord(): HasOne
    {
        return $this->hasOne(PatchCord::class);
    }

    /**
     * How the port is written: its panel and its number, "PB-A 15". The
     * panel must be loaded.
     */
    public function name(): string
    {
        return $this->panel->name.' '.$this->number;
    }

    /**
     * The port with the room of its panel, for the activity log: two rooms
     * may each have a "PB-A". The panel must be loaded with its room, the
     * floor and the building of the room.
     */
    public function auditName(): string
    {
        return $this->panel->room->path().' › '.$this->name();
    }
}
