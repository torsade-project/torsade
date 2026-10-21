<?php

// torsade/app/Models/JackCord.php

namespace App\Models;

use App\Contracts\Auditable;
use Database\Factories\JackCordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A jack cord, on the side of the desk: a port of a device plugged into a
 * wall jack of the same site, a switch of a meeting room or an access point
 * on the ceiling. A jack has one at most, and so has a port, which then has
 * no patch cord: a port has one connector.
 *
 * @property int $id
 * @property int $jack_id
 * @property int $port_id
 * @property string|null $color
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Jack $jack
 * @property-read Port $port
 */
#[Fillable(['jack_id', 'port_id', 'color', 'description'])]
class JackCord extends Model implements Auditable
{
    /** @use HasFactory<JackCordFactory> */
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
     * @return BelongsTo<Jack, $this>
     */
    public function jack(): BelongsTo
    {
        return $this->belongsTo(Jack::class);
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
     * two ends, the jack with where it is, the port with its device. The
     * floor of the jack must be loaded with its building, the port with
     * its device and the site of the device.
     */
    public function auditLabel(): string
    {
        return $this->jack->path().' ↔ '.$this->port->auditLabel();
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
            'jack' => $this->jack->path(),
            'port' => $this->port->auditLabel(),
            'color' => $this->color,
            'description' => $this->description,
        ];
    }
}
