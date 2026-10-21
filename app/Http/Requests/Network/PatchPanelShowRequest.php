<?php

// torsade/app/Http/Requests/Network/PatchPanelShowRequest.php

namespace App\Http\Requests\Network;

use App\Models\PatchPanel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PatchPanelShowRequest extends FormRequest
{
    public function authorize(): bool
    {
        $panel = $this->route('patchPanel');

        return $panel instanceof PatchPanel && (bool) $this->user()?->can('view', $panel);
    }

    /**
     * What the window "Patch" asks for, optional.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The device whose ports the window lists.
            'device' => ['nullable', 'integer', Rule::exists('devices', 'id')],
        ];
    }

    public function device(): ?int
    {
        return $this->validated('device') === null ? null : (int) $this->validated('device');
    }
}
