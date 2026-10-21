<?php

// torsade/app/Http/Requests/Network/AddressingRuleRequest.php

namespace App\Http\Requests\Network;

use App\Enums\GatewayPosition;
use App\Enums\RangeKind;
use App\Models\AddressingRule;
use App\Services\Network\AddressingRules;
use App\Support\Ipv4;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The addressing rule and its ranges, saved together.
 */
class AddressingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', AddressingRule::class);
    }

    /**
     * The shape of the rule only. Whether its lengths make sense together,
     * with its block and with its ranges, depends on the values as a
     * whole and on stored data: App\Services\Network\AddressingRules
     * decides, and tells each refusal on its line.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'aggregate_block_id' => ['required', 'integer', Rule::exists('address_blocks', 'id')],
            // Sent empty, the rule has no level of sites; left out, it is
            // refused, so that a level is never dropped by mistake.
            'site_prefix_length' => ['present', 'nullable', 'integer', 'between:1,'.Ipv4::MAX_PREFIX],
            'vlan_prefix_length' => ['required', 'integer', 'between:1,'.Ipv4::MAX_PREFIX],
            'gateway_position' => ['required', Rule::enum(GatewayPosition::class)],
            'ranges' => ['nullable', 'array', 'max:'.AddressingRules::MAX_RANGES],
            'ranges.*.kind' => ['required', Rule::enum(RangeKind::class)],
            // Offsets from the network address of a subnet: no subnet has
            // more addresses than the whole address space.
            'ranges.*.start_offset' => ['required', 'integer', 'between:0,4294967295'],
            'ranges.*.end_offset' => ['required', 'integer', 'between:0,4294967295'],
            'ranges.*.name' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'ranges.*.kind' => (string) trans('validation.attributes.kind'),
            'ranges.*.start_offset' => (string) trans('validation.attributes.start_offset'),
            'ranges.*.end_offset' => (string) trans('validation.attributes.end_offset'),
            'ranges.*.name' => (string) trans('validation.attributes.name'),
        ];
    }

    /**
     * @return array{aggregate_block_id: int, site_prefix_length: int|null, vlan_prefix_length: int, gateway_position: string, ranges: list<array{kind: string, start_offset: int, end_offset: int, name: string|null}>}
     */
    public function ruleData(): array
    {
        /** @var array<int, array{kind: string, start_offset: int|string, end_offset: int|string, name?: string|null}> $ranges */
        $ranges = $this->validated('ranges') ?? [];

        return [
            'aggregate_block_id' => $this->integer('aggregate_block_id'),
            'site_prefix_length' => $this->validated('site_prefix_length') === null ? null : $this->integer('site_prefix_length'),
            'vlan_prefix_length' => $this->integer('vlan_prefix_length'),
            'gateway_position' => (string) $this->validated('gateway_position'),
            'ranges' => array_values(array_map(
                fn (array $range): array => [
                    'kind' => $range['kind'],
                    'start_offset' => (int) $range['start_offset'],
                    'end_offset' => (int) $range['end_offset'],
                    'name' => $range['name'] ?? null,
                ],
                $ranges,
            )),
        ];
    }
}
