<?php

// torsade/app/Http/Requests/Network/JackRequest.php

namespace App\Http\Requests\Network;

use App\Enums\Status;
use App\Models\Jack;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class JackRequest extends FormRequest
{
    public function authorize(): bool
    {
        $jack = $this->route('jack');

        return (bool) ($jack instanceof Jack
            ? $this->user()?->can('update', $jack)
            : $this->user()?->can('create', Jack::class));
    }

    /**
     * The shape of a jack only. That its label is free on its floor, and
     * that its port of a panel is of the same site and free, depend on
     * stored data: App\Services\Network\Jacks enforces them.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // "B.15": free text.
            'label' => ['required', 'string', 'max:'.Jack::MAX_LABEL_LENGTH],
            // "Office 12, by the window".
            'location' => ['nullable', 'string', 'max:'.Jack::MAX_LOCATION_LENGTH],
            // Left out or empty: the jack is not cabled to a panel.
            'patch_panel_port_id' => ['nullable', 'integer'],
            'status' => ['required', Rule::enum(Status::class)],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array{label: string, location: string|null, patch_panel_port_id: int|null, status: string, description: string|null}
     */
    public function jackData(): array
    {
        return [
            'label' => (string) $this->validated('label'),
            'location' => $this->validated('location'),
            'patch_panel_port_id' => $this->validated('patch_panel_port_id') === null ? null : (int) $this->validated('patch_panel_port_id'),
            'status' => (string) $this->validated('status'),
            'description' => $this->validated('description'),
        ];
    }
}
