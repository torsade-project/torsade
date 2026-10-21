<?php

// torsade/app/Http/Requests/Network/PortRequest.php

namespace App\Http\Requests\Network;

use App\Enums\PoeType;
use App\Enums\PortConnector;
use App\Enums\PortMode;
use App\Enums\PortSpeed;
use App\Models\Port;
use App\Models\Vlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PortRequest extends FormRequest
{
    public function authorize(): bool
    {
        $port = $this->route('port');

        return $port instanceof Port && (bool) $this->user()?->can('update', $port);
    }

    /**
     * The shape of a port only. That its name is free in its device, and
     * the rules of its mode and of its VLANs, depend on stored data:
     * App\Services\Network\Ports enforces them.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Free text, as the device writes it: "1/0/18", "Gi2/0/1".
            'name' => ['required', 'string', 'max:'.Port::MAX_NAME_LENGTH],
            'position' => ['required', 'integer', 'between:1,'.Port::MAX_POSITION],
            'connector' => ['required', Rule::enum(PortConnector::class)],
            'max_speed' => ['required', Rule::enum(PortSpeed::class)],
            'poe_type' => ['required', Rule::enum(PoeType::class)],
            'poe_label' => ['nullable', 'string', 'max:32'],
            'enabled' => ['required', 'boolean'],
            'description' => ['nullable', 'string', 'max:255'],
            // Left out or empty: the port has no mode, and carries no VLAN.
            'mode' => ['nullable', Rule::enum(PortMode::class)],
            'untagged_vlan_id' => ['nullable', 'integer'],
            'tagged_vlan_ids' => ['nullable', 'array', 'max:'.Vlan::MAX_VID],
            'tagged_vlan_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return array{name: string, position: int, connector: string, max_speed: string, poe_type: string, poe_label: string|null, enabled: bool, description: string|null, mode: string|null, untagged_vlan_id: int|null, tagged_vlan_ids: list<int>}
     */
    public function portData(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'position' => $this->integer('position'),
            'connector' => (string) $this->validated('connector'),
            'max_speed' => (string) $this->validated('max_speed'),
            'poe_type' => (string) $this->validated('poe_type'),
            'poe_label' => $this->validated('poe_label'),
            'enabled' => $this->boolean('enabled'),
            'description' => $this->validated('description'),
            'mode' => $this->validated('mode'),
            'untagged_vlan_id' => $this->validated('untagged_vlan_id') === null ? null : (int) $this->validated('untagged_vlan_id'),
            'tagged_vlan_ids' => array_values(array_map(intval(...), (array) $this->validated('tagged_vlan_ids'))),
        ];
    }
}
