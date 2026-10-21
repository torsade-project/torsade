<?php

// torsade/app/Models/DeviceTemplate.php

namespace App\Models;

use App\Contracts\Auditable;
use App\Enums\DeviceType;
use Database\Factories\DeviceTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What a kind of machine is made of: its ports, by groups of ports that are
 * alike. A template is for any device that has ports, a switch as well as
 * a firewall or a server. The ports of a device are copied from it once,
 * when a member is added: changing the template afterwards changes no
 * device.
 *
 * @property int $id
 * @property string $name
 * @property DeviceType $device_type
 * @property string|null $manufacturer
 * @property string|null $model
 * @property int|null $poe_budget_watts
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'device_type', 'manufacturer', 'model', 'poe_budget_watts', 'description'])]
class DeviceTemplate extends Model implements Auditable
{
    /** @use HasFactory<DeviceTemplateFactory> */
    use HasFactory;

    /**
     * How many groups of ports a template can have.
     */
    public const int MAX_GROUPS = 20;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'device_type' => DeviceType::class,
            'poe_budget_watts' => 'integer',
        ];
    }

    /**
     * The groups of ports, in the order the template gives them.
     *
     * @return HasMany<DeviceTemplatePortGroup, $this>
     */
    public function portGroups(): HasMany
    {
        return $this->hasMany(DeviceTemplatePortGroup::class)->orderBy('position');
    }

    /**
     * The members of devices that were made from this template.
     *
     * @return HasMany<DeviceMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(DeviceMember::class);
    }

    /**
     * The manufacturer and the model together, as they are read on a label.
     */
    public function make(): ?string
    {
        $make = trim($this->manufacturer.' '.$this->model);

        return $make === '' ? null : $make;
    }

    /**
     * How the activity log names the template, kept after it is deleted.
     */
    public function auditLabel(): string
    {
        return $this->name;
    }

    /**
     * The values the activity log compares before and after a change. The
     * groups of ports are part of the template: they are written with it,
     * on one line.
     *
     * @return array<string, scalar|null>
     */
    public function auditValues(): array
    {
        $groups = $this->portGroups
            ->map(fn (DeviceTemplatePortGroup $group): string => $group->label())
            ->implode(' ; ');

        return [
            'name' => $this->name,
            'device_type' => $this->device_type->value,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'poe_budget_watts' => $this->poe_budget_watts,
            'port_groups' => $groups === '' ? null : $groups,
            'description' => $this->description,
        ];
    }
}
