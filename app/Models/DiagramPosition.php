<?php

// torsade/app/Models/DiagramPosition.php

namespace App\Models;

use App\Enums\DiagramNode;
use App\Enums\DiagramView;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Where a node was placed by hand on a diagram of a site: a device; on the
 * logical diagram, a subnet or the internet; on the physical diagram, a
 * patch panel. The top left corner of its
 * box, in the units of the drawing, on a grid of 8. A node has one place
 * per diagram; one without is laid out with the others.
 *
 * @property int $id
 * @property int $site_id
 * @property DiagramView $view
 * @property DiagramNode $node
 * @property int|null $device_id
 * @property int|null $subnet_id
 * @property int|null $patch_panel_id
 * @property-read int|null $internet_node
 * @property int $x
 * @property int $y
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Site $site
 * @property-read Device|null $device
 * @property-read Subnet|null $subnet
 * @property-read PatchPanel|null $patchPanel
 */
#[Fillable(['site_id', 'view', 'node', 'device_id', 'subnet_id', 'patch_panel_id', 'x', 'y'])]
class DiagramPosition extends Model
{
    /**
     * The step of the grid the positions sit on.
     */
    public const int GRID = 8;

    /**
     * How far from the origin a position may be, either way.
     */
    public const int LIMIT = 1_000_000;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'view' => DiagramView::class,
            'node' => DiagramNode::class,
            'x' => 'integer',
            'y' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return BelongsTo<Subnet, $this>
     */
    public function subnet(): BelongsTo
    {
        return $this->belongsTo(Subnet::class);
    }

    /**
     * @return BelongsTo<PatchPanel, $this>
     */
    public function patchPanel(): BelongsTo
    {
        return $this->belongsTo(PatchPanel::class);
    }
}
