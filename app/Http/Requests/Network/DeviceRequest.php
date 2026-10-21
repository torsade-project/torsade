<?php

// torsade/app/Http/Requests/Network/DeviceRequest.php

namespace App\Http\Requests\Network;

use App\Enums\DeviceType;
use App\Enums\Status;
use App\Models\Device;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $device = $this->route('device');

        return (bool) ($device instanceof Device
            ? $this->user()?->can('update', $device)
            : $this->user()?->can('create', Device::class));
    }

    /**
     * The shape of a device only. That its name is unique within its site
     * and that its room belongs to that site depend on stored data:
     * App\Services\Network\Devices enforces both.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'site_id' => ['required', 'integer', Rule::exists('sites', 'id')],
            // Optional: a small site often has no plan of its rooms.
            'room_id' => ['nullable', 'integer', Rule::exists('rooms', 'id')],
            // Free text, unlike the host name of an address.
            'name' => ['required', 'string', 'max:'.Device::MAX_NAME_LENGTH],
            'type' => ['required', Rule::enum(DeviceType::class)],
            'manufacturer' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'status' => ['required', Rule::enum(Status::class)],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array{site_id: int, room_id: int|null, name: string, type: string, manufacturer: string|null, model: string|null, serial_number: string|null, status: string, description: string|null}
     */
    public function deviceData(): array
    {
        return [
            'site_id' => $this->integer('site_id'),
            'room_id' => $this->validated('room_id') === null ? null : (int) $this->validated('room_id'),
            'name' => (string) $this->validated('name'),
            'type' => (string) $this->validated('type'),
            'manufacturer' => $this->validated('manufacturer'),
            'model' => $this->validated('model'),
            'serial_number' => $this->validated('serial_number'),
            'status' => (string) $this->validated('status'),
            'description' => $this->validated('description'),
        ];
    }
}
