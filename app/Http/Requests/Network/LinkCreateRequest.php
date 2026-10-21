<?php

// torsade/app/Http/Requests/Network/LinkCreateRequest.php

namespace App\Http\Requests\Network;

use App\Models\Link;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ports of a device linked to as many ports of another one: one port on
 * each side for one link, several for a series.
 */
class LinkCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Link::class);
    }

    /**
     * The shape of the series only. That the opposite device exists and is
     * another one, that the ports belong to their device and are free, and
     * that there are as many on each side, depend on stored data:
     * App\Services\Network\Links enforces them, for all the links or for
     * none.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'port_ids' => ['required', 'array', 'min:1', 'max:'.PortSelectionRequest::MAX_PORTS],
            'port_ids.*' => ['integer', 'distinct'],
            'opposite_device_id' => ['required', 'integer'],
            'opposite_port_ids' => ['required', 'array', 'min:1', 'max:'.PortSelectionRequest::MAX_PORTS],
            'opposite_port_ids.*' => ['integer', 'distinct'],
            ...LinkRequest::linkRules(),
        ];
    }

    /**
     * @return list<int>
     */
    public function portIds(): array
    {
        return array_values(array_map(intval(...), (array) $this->validated('port_ids')));
    }

    public function oppositeDeviceId(): int
    {
        return (int) $this->validated('opposite_device_id');
    }

    /**
     * @return list<int>
     */
    public function oppositePortIds(): array
    {
        return array_values(array_map(intval(...), (array) $this->validated('opposite_port_ids')));
    }

    /**
     * @return array{medium: string|null, status: string, description: string|null}
     */
    public function linkData(): array
    {
        return [
            'medium' => $this->validated('medium'),
            'status' => (string) $this->validated('status'),
            'description' => $this->validated('description'),
        ];
    }
}
