<?php

// torsade/app/Http/Requests/Network/DeviceTemplateRequest.php

namespace App\Http\Requests\Network;

use App\Enums\DeviceType;
use App\Enums\PoeType;
use App\Enums\PortConnector;
use App\Enums\PortSpeed;
use App\Models\DeviceTemplate;
use App\Rules\PortNamePattern;
use App\Support\PortNaming;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A device template and its groups of ports, saved together.
 */
class DeviceTemplateRequest extends FormRequest
{
    /**
     * The highest budget a template can be given, in watts: far above what
     * a machine can power.
     */
    public const int MAX_POE_BUDGET = 100000;

    public function authorize(): bool
    {
        $template = $this->route('deviceTemplate');

        return (bool) ($template instanceof DeviceTemplate
            ? $this->user()?->can('update', $template)
            : $this->user()?->can('create', DeviceTemplate::class));
    }

    /**
     * The shape of the template and of each group. That the name is unique
     * and that two groups do not give the same name to a port depend on
     * the whole: App\Services\Network\DeviceTemplates checks both.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'device_type' => ['required', Rule::enum(DeviceType::class)],
            'manufacturer' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'poe_budget_watts' => ['nullable', 'integer', 'between:1,'.self::MAX_POE_BUDGET],
            'description' => ['nullable', 'string', 'max:1000'],
            // Possibly empty: a template may be saved before its ports are
            // described.
            'port_groups' => ['present', 'array', 'max:'.DeviceTemplate::MAX_GROUPS],
            'port_groups.*.quantity' => ['required', 'integer', 'between:1,'.PortNaming::MAX_QUANTITY],
            'port_groups.*.name_pattern' => ['required', 'string', 'max:'.PortNaming::MAX_NAME_LENGTH, new PortNamePattern],
            'port_groups.*.first_number' => ['required', 'integer', 'between:0,'.PortNaming::MAX_FIRST_NUMBER],
            'port_groups.*.connector' => ['required', Rule::enum(PortConnector::class)],
            'port_groups.*.max_speed' => ['required', Rule::enum(PortSpeed::class)],
            'port_groups.*.poe_type' => ['required', Rule::enum(PoeType::class)],
            'port_groups.*.poe_label' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach (['quantity', 'name_pattern', 'first_number', 'connector', 'max_speed', 'poe_type', 'poe_label'] as $field) {
            $attributes['port_groups.*.'.$field] = (string) trans('validation.attributes.'.$field);
        }

        return $attributes;
    }

    /**
     * @return array{name: string, device_type: string, manufacturer: string|null, model: string|null, poe_budget_watts: int|null, description: string|null, port_groups: list<array{quantity: int, name_pattern: string, first_number: int, connector: string, max_speed: string, poe_type: string, poe_label: string|null}>}
     */
    public function templateData(): array
    {
        /** @var array<int, array<string, mixed>> $groups */
        $groups = $this->validated('port_groups') ?? [];

        return [
            'name' => (string) $this->validated('name'),
            'device_type' => (string) $this->validated('device_type'),
            'manufacturer' => $this->validated('manufacturer'),
            'model' => $this->validated('model'),
            'poe_budget_watts' => $this->validated('poe_budget_watts') === null ? null : (int) $this->validated('poe_budget_watts'),
            'description' => $this->validated('description'),
            'port_groups' => array_values(array_map(
                fn (array $group): array => [
                    'quantity' => (int) $group['quantity'],
                    'name_pattern' => (string) $group['name_pattern'],
                    'first_number' => (int) $group['first_number'],
                    'connector' => (string) $group['connector'],
                    'max_speed' => (string) $group['max_speed'],
                    'poe_type' => (string) $group['poe_type'],
                    'poe_label' => $group['poe_label'] ?? null,
                ],
                $groups,
            )),
        ];
    }
}
