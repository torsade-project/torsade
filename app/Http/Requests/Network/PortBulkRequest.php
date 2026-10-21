<?php

// torsade/app/Http/Requests/Network/PortBulkRequest.php

namespace App\Http\Requests\Network;

use App\Enums\PortMode;
use App\Models\Port;
use App\Models\Vlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One change asked for the ports of a device ticked in its table.
 */
class PortBulkRequest extends FormRequest
{
    public const string ENABLE = 'enable';

    public const string DISABLE = 'disable';

    /**
     * Give the ports a mode and VLANs, the same for all of them.
     */
    public const string SET_MODE = 'mode';

    public const string CLEAR_MODE = 'clear_mode';

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', Port::class);
    }

    /**
     * That the ports belong to the device, and the rules of a mode and of
     * its VLANs, are checked by App\Services\Network\Ports, which does the
     * action for all the ports or for none.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ports' => ['required', 'array', 'min:1', 'max:'.PortSelectionRequest::MAX_PORTS],
            'ports.*' => ['integer', 'distinct'],
            'action' => ['required', Rule::in([self::ENABLE, self::DISABLE, self::SET_MODE, self::CLEAR_MODE])],
            // Only read when a mode is given to the ports.
            'mode' => ['required_if:action,'.self::SET_MODE, 'nullable', Rule::enum(PortMode::class)],
            'untagged_vlan_id' => ['nullable', 'integer'],
            'tagged_vlan_ids' => ['nullable', 'array', 'max:'.Vlan::MAX_VID],
            'tagged_vlan_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * The mode and the VLANs asked for the ports.
     *
     * @return array{mode: string|null, untagged_vlan_id: int|null, tagged_vlan_ids: list<int>}
     */
    public function modeData(): array
    {
        return [
            'mode' => $this->validated('mode'),
            'untagged_vlan_id' => $this->validated('untagged_vlan_id') === null ? null : (int) $this->validated('untagged_vlan_id'),
            'tagged_vlan_ids' => array_values(array_map(intval(...), (array) $this->validated('tagged_vlan_ids'))),
        ];
    }

    /**
     * @return list<int>
     */
    public function portIds(): array
    {
        return array_values(array_map(intval(...), (array) $this->validated('ports')));
    }

    public function action(): string
    {
        return (string) $this->validated('action');
    }
}
