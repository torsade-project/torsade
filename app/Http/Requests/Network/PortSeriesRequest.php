<?php

// torsade/app/Http/Requests/Network/PortSeriesRequest.php

namespace App\Http\Requests\Network;

use App\Enums\PoeType;
use App\Enums\PortConnector;
use App\Enums\PortSpeed;
use App\Models\Port;
use App\Rules\PortNamePattern;
use App\Support\PortNaming;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A series of ports added to a member of a device: a pattern for their
 * names, a first and a last number, and what they all are.
 */
class PortSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Port::class);
    }

    /**
     * The shape of the series only. That the member belongs to the device,
     * that the series is not too long and that its names are free in the
     * device depend on stored data: App\Services\Network\Ports and
     * App\Services\Network\DeviceMembers enforce them.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Left out for a device without a member: its first member is
            // created with the ports.
            'device_member_id' => ['nullable', 'integer'],
            // One port alone, its first and its last number being the
            // same, can do without "{n}".
            'name_pattern' => ['required', 'string', 'max:'.PortNaming::MAX_NAME_LENGTH, new PortNamePattern(series: true, single: $this->namesOnePort())],
            'first_number' => ['required', 'integer', 'between:0,'.PortNaming::MAX_FIRST_NUMBER],
            'last_number' => ['required', 'integer', 'gte:first_number', 'max:'.(PortNaming::MAX_FIRST_NUMBER + PortNaming::MAX_QUANTITY - 1)],
            'connector' => ['required', Rule::enum(PortConnector::class)],
            'max_speed' => ['required', Rule::enum(PortSpeed::class)],
            'poe_type' => ['required', Rule::enum(PoeType::class)],
            'poe_label' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * Whether the series is made of one port: the same whole number as its
     * first and as its last.
     */
    private function namesOnePort(): bool
    {
        $first = filter_var($this->input('first_number'), FILTER_VALIDATE_INT);
        $last = filter_var($this->input('last_number'), FILTER_VALIDATE_INT);

        return $first !== false && $first === $last;
    }

    public function memberId(): ?int
    {
        return $this->validated('device_member_id') === null ? null : (int) $this->validated('device_member_id');
    }

    /**
     * @return array{name_pattern: string, first_number: int, last_number: int, connector: string, max_speed: string, poe_type: string, poe_label: string|null}
     */
    public function seriesData(): array
    {
        return [
            'name_pattern' => (string) $this->validated('name_pattern'),
            'first_number' => $this->integer('first_number'),
            'last_number' => $this->integer('last_number'),
            'connector' => (string) $this->validated('connector'),
            'max_speed' => (string) $this->validated('max_speed'),
            'poe_type' => (string) $this->validated('poe_type'),
            'poe_label' => $this->validated('poe_label'),
        ];
    }
}
