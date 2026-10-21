<?php

// torsade/app/Models/Link.php

namespace App\Models;

use App\Contracts\Auditable;
use App\Enums\LinkMedium;
use App\Enums\Status;
use Database\Factories\LinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A link between two ports of two different devices: what is plugged into
 * what, whatever the cables and the patch panels in between. Its two ends
 * are lines of their own, so that the database keeps a port out of a second
 * link.
 *
 * @property int $id
 * @property LinkMedium|null $medium
 * @property string|null $description
 * @property Status $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, LinkEnd> $ends
 */
#[Fillable(['medium', 'description', 'status'])]
class Link extends Model implements Auditable
{
    /** @use HasFactory<LinkFactory> */
    use HasFactory;

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
            'medium' => LinkMedium::class,
            'status' => Status::class,
        ];
    }

    /**
     * Its two ends, the first one first.
     *
     * @return HasMany<LinkEnd, $this>
     */
    public function ends(): HasMany
    {
        return $this->hasMany(LinkEnd::class)->orderBy('end');
    }

    /**
     * The port at one of its two ends.
     */
    public function portAt(int $end): Port
    {
        $found = $this->ends->first(fn (LinkEnd $one): bool => $one->end === $end);

        if ($found === null) {
            throw new LogicException('A link has an end 1 and an end 2.');
        }

        return $found->port;
    }

    /**
     * Its two ports. Seen from a device, the port of that device comes
     * first; from nowhere in particular, its end 1 does.
     *
     * @return array{0: Port, 1: Port}
     */
    public function portsFrom(?int $deviceId = null): array
    {
        $first = $this->portAt(1);
        $second = $this->portAt(2);

        return $deviceId !== null && $second->device_id === $deviceId && $first->device_id !== $deviceId
            ? [$second, $first]
            : [$first, $second];
    }

    /**
     * How the activity log names the link, kept after it is deleted: its
     * two ends, each a port with its device.
     */
    public function auditLabel(): string
    {
        return $this->portAt(1)->auditLabel().' ↔ '.$this->portAt(2)->auditLabel();
    }

    /**
     * The values the activity log compares before and after a change. The
     * two ends are part of the link: they are written with it.
     *
     * @return array<string, scalar|null>
     */
    public function auditValues(): array
    {
        return [
            'end_1' => $this->portAt(1)->auditLabel(),
            'end_2' => $this->portAt(2)->auditLabel(),
            'medium' => $this->medium?->value,
            'status' => $this->status->value,
            'description' => $this->description,
        ];
    }
}
