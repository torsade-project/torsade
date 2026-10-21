<?php

// torsade/app/Models/LinkEnd.php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One of the two ends of a link: a port. A port is the end of one link at
 * most, whichever end it is.
 *
 * @property int $id
 * @property int $link_id
 * @property int $port_id
 * @property int $end
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Link $link
 * @property-read Port $port
 */
#[Fillable(['link_id', 'port_id', 'end'])]
class LinkEnd extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'end' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Link, $this>
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(Link::class);
    }

    /**
     * @return BelongsTo<Port, $this>
     */
    public function port(): BelongsTo
    {
        return $this->belongsTo(Port::class);
    }
}
