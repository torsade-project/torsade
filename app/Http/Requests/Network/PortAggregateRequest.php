<?php

// torsade/app/Http/Requests/Network/PortAggregateRequest.php

namespace App\Http\Requests\Network;

use App\Enums\AggregateProtocol;
use App\Enums\PortMode;
use App\Models\PortAggregate;
use App\Models\Vlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PortAggregateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $aggregate = $this->route('portAggregate');

        return (bool) ($aggregate instanceof PortAggregate
            ? $this->user()?->can('update', $aggregate)
            : $this->user()?->can('create', PortAggregate::class));
    }

    /**
     * The shape of an aggregate only. That its name is free in its device,
     * that its ports belong to the device and to no other aggregate, and
     * the rules of its mode and of its VLANs, depend on stored data:
     * App\Services\Network\PortAggregates enforces them.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // As the device writes it: "Po1", "lag 1", "Trk1".
            'name' => ['required', 'string', 'max:'.PortAggregate::MAX_NAME_LENGTH],
            'protocol' => ['required', Rule::enum(AggregateProtocol::class)],
            'description' => ['nullable', 'string', 'max:1000'],
            // Possibly empty: an aggregate may be declared before its ports.
            'port_ids' => ['nullable', 'array', 'max:'.PortSelectionRequest::MAX_PORTS],
            'port_ids.*' => ['integer', 'distinct'],
            // Left out or empty: the aggregate has no mode, and carries no
            // VLAN.
            'mode' => ['nullable', Rule::enum(PortMode::class)],
            'untagged_vlan_id' => ['nullable', 'integer'],
            'tagged_vlan_ids' => ['nullable', 'array', 'max:'.Vlan::MAX_VID],
            'tagged_vlan_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return array{name: string, protocol: string, description: string|null, port_ids: list<int>, mode: string|null, untagged_vlan_id: int|null, tagged_vlan_ids: list<int>}
     */
    public function aggregateData(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'protocol' => (string) $this->validated('protocol'),
            'description' => $this->validated('description'),
            'port_ids' => array_values(array_map(intval(...), (array) $this->validated('port_ids'))),
            'mode' => $this->validated('mode'),
            'untagged_vlan_id' => $this->validated('untagged_vlan_id') === null ? null : (int) $this->validated('untagged_vlan_id'),
            'tagged_vlan_ids' => array_values(array_map(intval(...), (array) $this->validated('tagged_vlan_ids'))),
        ];
    }
}
