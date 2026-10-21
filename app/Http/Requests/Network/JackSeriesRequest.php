<?php

// torsade/app/Http/Requests/Network/JackSeriesRequest.php

namespace App\Http\Requests\Network;

use App\Enums\Status;
use App\Models\Jack;
use App\Models\PatchPanel;
use App\Support\JackLabels;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Jacks of a floor labelled as a series: a prefix, numbers from a first to
 * a last one written with at least so many digits, and a suffix; and,
 * optionally, the panel they are cabled to, from one of its ports.
 */
class JackSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Jack::class);
    }

    /**
     * The shape of the series only. That its labels are free on the floor,
     * that it holds 200 jacks at most, and that the panel is of the site
     * with enough free ports, depend on what is typed together and on
     * stored data: App\Services\Network\Jacks enforces them.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prefix' => ['nullable', 'string', 'max:'.(Jack::MAX_LABEL_LENGTH - 1)],
            'suffix' => ['nullable', 'string', 'max:'.(Jack::MAX_LABEL_LENGTH - 1)],
            'first_number' => ['required', 'integer', 'min:0', 'max:'.JackLabels::MAX_NUMBER],
            'last_number' => ['required', 'integer', 'min:0', 'max:'.JackLabels::MAX_NUMBER, 'gte:first_number'],
            'digits' => ['required', 'integer', 'min:1', 'max:'.JackLabels::MAX_DIGITS],
            // Left out or empty: the jacks are not cabled yet.
            'patch_panel_id' => ['nullable', 'integer'],
            'first_port' => ['nullable', 'integer', 'min:1', 'max:'.PatchPanel::MAX_PORTS],
            'status' => ['required', Rule::enum(Status::class)],
        ];
    }

    /**
     * @return array{prefix: string, suffix: string, first_number: int, last_number: int, digits: int, patch_panel_id: int|null, first_port: int|null, status: string}
     */
    public function seriesData(): array
    {
        return [
            'prefix' => (string) $this->validated('prefix'),
            'suffix' => (string) $this->validated('suffix'),
            'first_number' => (int) $this->validated('first_number'),
            'last_number' => (int) $this->validated('last_number'),
            'digits' => (int) $this->validated('digits'),
            'patch_panel_id' => $this->validated('patch_panel_id') === null ? null : (int) $this->validated('patch_panel_id'),
            'first_port' => $this->validated('first_port') === null ? null : (int) $this->validated('first_port'),
            'status' => (string) $this->validated('status'),
        ];
    }
}
