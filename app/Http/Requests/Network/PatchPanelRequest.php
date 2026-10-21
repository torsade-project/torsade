<?php

// torsade/app/Http/Requests/Network/PatchPanelRequest.php

namespace App\Http\Requests\Network;

use App\Enums\PanelConnector;
use App\Enums\Status;
use App\Models\PatchPanel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PatchPanelRequest extends FormRequest
{
    public function authorize(): bool
    {
        $panel = $this->route('patchPanel');

        return (bool) ($panel instanceof PatchPanel
            ? $this->user()?->can('update', $panel)
            : $this->user()?->can('create', PatchPanel::class));
    }

    /**
     * The shape of a panel only. That its name is free in its room, and
     * that the ports a smaller number takes away have neither a jack nor a
     * cord, depend on stored data: App\Services\Network\PatchPanels
     * enforces them.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // "PB-A", as written on the panel.
            'name' => ['required', 'string', 'max:'.PatchPanel::MAX_NAME_LENGTH],
            'port_count' => ['required', 'integer', 'min:1', 'max:'.PatchPanel::MAX_PORTS],
            'connector' => ['required', Rule::enum(PanelConnector::class)],
            // "6A".
            'category' => ['nullable', 'string', 'max:16'],
            'status' => ['required', Rule::enum(Status::class)],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array{name: string, port_count: int, connector: string, category: string|null, status: string, description: string|null}
     */
    public function panelData(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'port_count' => (int) $this->validated('port_count'),
            'connector' => (string) $this->validated('connector'),
            'category' => $this->validated('category'),
            'status' => (string) $this->validated('status'),
            'description' => $this->validated('description'),
        ];
    }
}
