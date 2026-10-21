<?php

// torsade/app/Http/Requests/Network/IpAddressRequest.php

namespace App\Http\Requests\Network;

use App\Http\Requests\Network\Concerns\IpAddressRules;
use App\Models\IpAddress;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IpAddressRequest extends FormRequest
{
    use IpAddressRules;

    public function authorize(): bool
    {
        $address = $this->route('ipAddress');

        return (bool) ($address instanceof IpAddress
            ? $this->user()?->can('update', $address)
            : $this->user()?->can('create', IpAddress::class));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->ipAddressRules(),
            // Optional: the device this address is an interface of. A
            // device of another site is accepted, and shown as a deviation.
            'device_id' => ['nullable', 'integer', Rule::exists('devices', 'id')],
            // Optional: the wall jack the machine is plugged into. That it
            // is of the site of the subnet depends on stored data:
            // App\Services\Network\IpAddresses enforces it.
            'jack_id' => ['nullable', 'integer'],
            // Says where to go back to: the page of the device the address
            // was created from, or the page of its subnet.
            'from_device' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->ipAddressMessages();
    }

    /**
     * @return array{address: string, hostname: string|null, mac_address: string|null, device_type: string|null, assignment: string|null, device_id?: int|null, jack_id?: int|null, status: string|null, description: string|null}
     */
    public function addressData(): array
    {
        /** @var array{address: string, hostname: string|null, mac_address: string|null, device_type: string|null, assignment: string|null, device_id?: int|null, jack_id?: int|null, status: string|null, description: string|null} $data */
        $data = [
            'address' => (string) $this->validated('address'),
            'hostname' => $this->validated('hostname'),
            'mac_address' => $this->validated('mac_address'),
            'device_type' => $this->validated('device_type'),
            'assignment' => $this->validated('assignment'),
            'status' => $this->validated('status'),
            'description' => $this->validated('description'),
        ];

        // A request that says nothing of the device leaves the address on
        // the device it has; one that sends it empty detaches the address.
        if ($this->has('device_id')) {
            $data['device_id'] = $this->validated('device_id') === null ? null : (int) $this->validated('device_id');
        }

        // The same for the jack: left out, it stays; sent empty, the
        // address is unplugged.
        if ($this->has('jack_id')) {
            $data['jack_id'] = $this->validated('jack_id') === null ? null : (int) $this->validated('jack_id');
        }

        return $data;
    }
}
