<?php

// torsade/app/Http/Requests/Network/DeviceIndexRequest.php

namespace App\Http\Requests\Network;

use App\Enums\DeviceType;
use App\Enums\Status;
use App\Models\Device;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeviceIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', Device::class);
    }

    /**
     * The filters of the list of devices, all optional.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'site' => ['nullable', 'integer', Rule::exists('sites', 'id')],
            'type' => ['nullable', Rule::enum(DeviceType::class)],
            'status' => ['nullable', Rule::enum(Status::class)],
            // Looked for in the name, the model and the serial number.
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }
}
