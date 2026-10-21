<?php

// torsade/app/Http/Requests/Network/PatchCordsDeleteRequest.php

namespace App\Http\Requests\Network;

use App\Models\PatchCord;
use App\Models\PatchPanel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ports of a panel unpatched at once: those ticked, consecutive or not.
 */
class PatchCordsDeleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('delete', PatchCord::class);
    }

    /**
     * That the ports are of the panel, and which of them have a cord,
     * depend on stored data: App\Services\Network\PatchCords enforces it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'panel_port_ids' => ['required', 'array', 'min:1', 'max:'.PatchPanel::MAX_PORTS],
            'panel_port_ids.*' => ['integer', 'distinct'],
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
}
