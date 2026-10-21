<?php

// torsade/app/Models/DeviceTemplatePortGroup.php

namespace App\Models;

use App\Enums\PoeType;
use App\Enums\PortConnector;
use App\Enums\PortSpeed;
use Database\Factories\DeviceTemplatePortGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ports of a template that are alike: so many of them, named after one
 * pattern, with the same connector, speed and power.
 *
 * @property int $id
 * @property int $device_template_id
 * @property int $position
 * @property int $quantity
 * @property string $name_pattern
 * @property int $first_number
 * @property PortConnector $connector
 * @property PortSpeed $max_speed
 * @property PoeType $poe_type
 * @property string|null $poe_label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['device_template_id', 'position', 'quantity', 'name_pattern', 'first_number', 'connector', 'max_speed', 'poe_type', 'poe_label'])]
class DeviceTemplatePortGroup extends Model
{
    /** @use HasFactory<DeviceTemplatePortGroupFactory> */
    use HasFactory;

    /**
     * Defaults of a new model, matching the defaults of the columns.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'first_number' => 1,
        'poe_type' => PoeType::None->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'integer',
            'first_number' => 'integer',
            'connector' => PortConnector::class,
            'max_speed' => PortSpeed::class,
            'poe_type' => PoeType::class,
        ];
    }

    /**
     * @return BelongsTo<DeviceTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DeviceTemplate::class, 'device_template_id');
    }

    /**
     * The group as the activity log writes it, in a form that does not
     * depend on a language: "24 x {m}/0/{n} from 1, rj45 1g 802.3at".
     */
    public function label(): string
    {
        return trim(
            $this->quantity.' x '.$this->name_pattern.' from '.$this->first_number.', '
            .$this->connector->value.' '.$this->max_speed->value.' '.$this->poe_type->value.' '.$this->poe_label,
        );
    }
}
