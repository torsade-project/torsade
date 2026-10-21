<?php

// torsade/app/Http/Requests/Network/JackIndexRequest.php

namespace App\Http\Requests\Network;

use App\Enums\Status;
use App\Models\Jack;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class JackIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', Jack::class);
    }

    /**
     * The filters of the list of jacks, all optional.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'site' => ['nullable', 'integer', Rule::exists('sites', 'id')],
            'building' => ['nullable', 'integer', Rule::exists('buildings', 'id')],
            'floor' => ['nullable', 'integer', Rule::exists('floors', 'id')],
            'status' => ['nullable', Rule::enum(Status::class)],
            // Only the jacks with a deviation of their cabling.
            'deviations' => ['nullable', 'boolean'],
            // Looked for in the label and the location.
            'search' => ['nullable', 'string', 'max:100'],
            // The site whose panels the window of a jack offers.
            'panels' => ['nullable', 'integer', Rule::exists('sites', 'id')],
            // The site whose devices the window "Plug a device" offers, and
            // the device whose free ports it offers.
            'plug_site' => ['nullable', 'integer'],
            'plug_device' => ['nullable', 'integer'],
        ];
    }

    public function site(): ?int
    {
        return $this->validated('site') === null ? null : (int) $this->validated('site');
    }

    public function building(): ?int
    {
        return $this->validated('building') === null ? null : (int) $this->validated('building');
    }

    public function floor(): ?int
    {
        return $this->validated('floor') === null ? null : (int) $this->validated('floor');
    }

    public function status(): ?string
    {
        return $this->validated('status') === null ? null : (string) $this->validated('status');
    }

    public function onlyDeviations(): bool
    {
        return (bool) $this->validated('deviations');
    }

    public function search(): string
    {
        return trim((string) $this->validated('search'));
    }

    public function panelsSite(): ?int
    {
        return $this->validated('panels') === null ? null : (int) $this->validated('panels');
    }

    public function plugSite(): ?int
    {
        return $this->validated('plug_site') === null ? null : (int) $this->validated('plug_site');
    }

    public function plugDevice(): ?int
    {
        return $this->validated('plug_device') === null ? null : (int) $this->validated('plug_device');
    }
}
