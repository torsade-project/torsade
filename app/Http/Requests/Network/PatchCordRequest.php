<?php

// torsade/app/Http/Requests/Network/PatchCordRequest.php

namespace App\Http\Requests\Network;

use App\Models\PatchCord;
use App\Models\PatchPanel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ports of a panel patched to as many ports of a device: the ports of the
 * panel, the device, the first of its ports, and what the cords are.
 */
class PatchCordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', PatchCord::class);
    }

    /**
     * The shape of the request only. That the ports of the panel follow one
     * another and are not patched, that the device is of the site of the
     * panel, and that it has enough free ports from the first one, depend
     * on stored data: App\Services\Network\PatchCords enforces them.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'panel_port_ids' => ['required', 'array', 'min:1', 'max:'.PatchPanel::MAX_PORTS],
            'panel_port_ids.*' => ['integer', 'distinct'],
            'device_id' => ['required', 'integer'],
            // The port of the device the first port of the panel goes to:
            // the others follow it, in the order of the device.
            'port_id' => ['required', 'integer'],
            // "Blue": free text.
            'color' => ['nullable', 'string', 'max:'.PatchCord::MAX_COLOR_LENGTH],
            'description' => ['nullable', 'string', 'max:'.PatchCord::MAX_DESCRIPTION_LENGTH],
        ];
    }

    /**
     * @return list<int>
     */
    public function panelPortIds(): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->validated('panel_port_ids');

        return array_map(intval(...), $ids);
    }

    public function deviceId(): int
    {
        return (int) $this->validated('device_id');
    }

    public function portId(): int
    {
        return (int) $this->validated('port_id');
    }

    /**
     * @return array{color: string|null, description: string|null}
     */
    public function cordData(): array
    {
        return [
            'color' => $this->validated('color'),
            'description' => $this->validated('description'),
        ];
    }
}
