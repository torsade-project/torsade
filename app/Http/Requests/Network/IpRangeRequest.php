<?php

// torsade/app/Http/Requests/Network/IpRangeRequest.php

namespace App\Http\Requests\Network;

use App\Enums\RangeKind;
use App\Models\IpRange;
use App\Rules\Ipv4Address;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IpRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $range = $this->route('ipRange');

        return (bool) ($range instanceof IpRange
            ? $this->user()?->can('update', $range)
            : $this->user()?->can('create', IpRange::class));
    }

    /**
     * The shape of the values only. Where the range may lie in its subnet
     * depends on stored data: App\Services\Network\IpRanges enforces it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(RangeKind::class)],
            'start_address' => ['required', 'string', new Ipv4Address],
            'end_address' => ['required', 'string', new Ipv4Address],
            'name' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array{kind: string, start_address: string, end_address: string, name: string|null, description: string|null}
     */
    public function rangeData(): array
    {
        return [
            'kind' => (string) $this->validated('kind'),
            'start_address' => (string) $this->validated('start_address'),
            'end_address' => (string) $this->validated('end_address'),
            'name' => $this->validated('name'),
            'description' => $this->validated('description'),
        ];
    }
}
