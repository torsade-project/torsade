<?php

// torsade/app/Models/PatchCord.php

namespace App\Models;

use App\Contracts\Auditable;
use Database\Factories\PatchCordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A patch cord, from a port of a patch panel to a port of a device of the
 * same site. Each of the two ports has one cord at most.
 *
 * @property int $id
 * @property int $patch_panel_port_id
 * @property int $port_id
 * @property string|null $color
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PatchPanelPort $panelPort
 * @property-read Port $port
 */
#[Fillable(['patch_panel_port_id', 'port_id', 'color', 'description'])]
class PatchCord extends Model implements Auditable
{
    /** @use HasFactory<PatchCordFactory> */
    use HasFactory;

    /**
     * The longest colour a cord can be written with.
     */
    public const int MAX_COLOR_LENGTH = 32;

    /**
     * The longest description a cord can have.
     */
    public const int MAX_DESCRIPTION_LENGTH = 255;

    /**
     * @return BelongsTo<PatchPanelPort, $this>
     */
    public function panelPort(): BelongsTo
    {
        return $this->belongsTo(PatchPanelPort::class, 'patch_panel_port_id');
    }

    /**
     * @return BelongsTo<Port, $this>
     */
    public function port(): BelongsTo
    {
        return $this->belongsTo(Port::class);
    }

    /**
     * How the activity log names the cord, kept after it is deleted: its
     * two ends, the port of the panel with its room, the port of the device
     * with its device.
     */
    public function auditLabel(): string
    {
        return $this->panelPort->auditName().' ↔ '.$this->port->auditLabel();
    }

    /**
     * The values the activity log compares before and after a change. The
     * two ends are part of the cord: they are written with it.
     *
     * @return array<string, scalar|null>
     */
    public function auditValues(): array
    {
        return [
            'panel_port' => $this->panelPort->auditName(),
            'port' => $this->port->auditLabel(),
            'color' => $this->color,
            'description' => $this->description,
        ];
    }
}
