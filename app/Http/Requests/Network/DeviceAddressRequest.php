<?php

// torsade/app/Http/Requests/Network/DeviceAddressRequest.php

namespace App\Http\Requests\Network;

use App\Models\Device;
use App\Models\IpAddress;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Attaching a documented address to a device. Whether the address is free
 * depends on stored data: App\Services\Network\IpAddresses checks it.
 */
class DeviceAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        $device = $this->route('device');

        return $device instanceof Device && (bool) $this->user()?->can('update', $device);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ip_address_id' => ['required', 'integer', Rule::exists('ip_addresses', 'id')],
        ];
    }

    public function address(): IpAddress
    {
        return IpAddress::query()->findOrFail($this->integer('ip_address_id'));
    }
}
