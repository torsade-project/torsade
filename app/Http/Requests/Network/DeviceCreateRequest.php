<?php

// torsade/app/Http/Requests/Network/DeviceCreateRequest.php

namespace App\Http\Requests\Network;

use App\Models\Device;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Opening the form of a new device, possibly with its site or its room
 * already chosen.
 */
class DeviceCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Device::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'site' => ['nullable', 'integer', Rule::exists('sites', 'id')],
            'room' => ['nullable', 'integer', Rule::exists('rooms', 'id')],
        ];
    }
}
