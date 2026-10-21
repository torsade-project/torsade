<?php

// torsade/app/Http/Requests/Network/HostnameDevicesRequest.php

namespace App\Http\Requests\Network;

use App\Enums\DeviceType;
use App\Enums\Status;
use App\Models\Device;
use App\Services\Network\HostnameDevices;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating the devices of a site from the host names of its addresses: the
 * names chosen, each with the type and the status of the device to create.
 */
class HostnameDevicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Device::class);
    }

    /**
     * The shape of the lines only. Which addresses bear each name, and
     * whether a device of that name is already there, depend on stored
     * data: App\Services\Network\HostnameDevices works both out again when
     * it writes, and tells each refusal on its line.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:'.HostnameDevices::MAX_LINES],
            'lines.*.hostname' => ['required', 'string', 'max:253'],
            'lines.*.type' => ['required', Rule::enum(DeviceType::class)],
            'lines.*.status' => ['required', Rule::enum(Status::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => __('Tick at least one host name.'),
            'lines.max' => trans_choice(
                'Tick :count host name at most at a time.|Tick :count host names at most at a time.',
                HostnameDevices::MAX_LINES,
            ),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lines.*.hostname' => (string) trans('validation.attributes.hostname'),
            'lines.*.type' => (string) trans('validation.attributes.type'),
            'lines.*.status' => (string) trans('validation.attributes.status'),
        ];
    }

    /**
     * The host names chosen, by line, each with the type and the status
     * of its device.
     *
     * @return array<int, array{hostname: string, type: string, status: string}>
     */
    public function lines(): array
    {
        /** @var array<int, array<string, mixed>> $lines */
        $lines = $this->validated('lines');

        return array_map(
            fn (array $line): array => [
                'hostname' => (string) $line['hostname'],
                'type' => (string) $line['type'],
                'status' => (string) $line['status'],
            ],
            $lines,
        );
    }
}
