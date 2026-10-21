<?php

// torsade/app/Http/Requests/Network/SiteStandardRequest.php

namespace App\Http\Requests\Network;

use App\Enums\Status;
use App\Http\Requests\Network\Concerns\SplitsCidr;
use App\Models\Vlan;
use App\Rules\Ipv4Address;
use App\Services\Network\SiteStandard;
use App\Support\Ipv4;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Applying the standard of the organisation to a site: the entries of the
 * catalogue to create there, each as a VLAN with, when asked, its subnet.
 */
class SiteStandardRequest extends FormRequest
{
    use SplitsCidr;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Vlan::class);
    }

    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');

        if (! is_array($lines)) {
            return;
        }

        // Whatever the encoding of the request, real booleans; and a subnet
        // typed in full, as "10.20.10.0/24", is split like everywhere else.
        $this->merge(['lines' => array_map(
            fn (mixed $line): mixed => is_array($line) ? [
                ...$line,
                ...$this->splitCidr($line['network'] ?? null, $line['prefix_length'] ?? null),
                'with_subnet' => filter_var($line['with_subnet'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'apply_breakdown' => filter_var($line['apply_breakdown'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ] : $line,
            $lines,
        )]);
    }

    /**
     * The shape of the lines only. Whether each VLAN and each subnet can be
     * created depends on stored data: App\Services\Network\SiteStandard
     * hands each line to the services of VLANs and subnets, and tells each
     * refusal on its line.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // One status for everything that is created.
            'status' => ['required', Rule::enum(Status::class)],
            'lines' => ['required', 'array', 'min:1', 'max:'.SiteStandard::MAX_LINES],
            'lines.*.catalog_vlan_id' => ['required', 'integer', 'distinct', Rule::exists('catalog_vlans', 'id')],
            'lines.*.vid' => ['required', 'integer', 'between:'.Vlan::MIN_VID.','.Vlan::MAX_VID],
            'lines.*.name' => ['required', 'string', 'max:64'],
            'lines.*.with_subnet' => ['boolean'],
            'lines.*.network' => ['exclude_unless:lines.*.with_subnet,true', 'required', 'string', new Ipv4Address],
            'lines.*.prefix_length' => ['exclude_unless:lines.*.with_subnet,true', 'required', 'integer', 'between:1,'.Ipv4::MAX_PREFIX],
            'lines.*.gateway' => ['exclude_unless:lines.*.with_subnet,true', 'nullable', 'string', new Ipv4Address],
            // Only the box is sent: the server works the ranges out.
            'lines.*.apply_breakdown' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => __('Tick at least one entry of the catalogue.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lines.*.catalog_vlan_id' => (string) trans('validation.attributes.catalog_vlan_id'),
            'lines.*.vid' => (string) trans('validation.attributes.vid'),
            'lines.*.name' => (string) trans('validation.attributes.name'),
            'lines.*.network' => (string) trans('validation.attributes.network'),
            'lines.*.prefix_length' => (string) trans('validation.attributes.prefix_length'),
            'lines.*.gateway' => (string) trans('validation.attributes.gateway'),
        ];
    }

    public function status(): string
    {
        return (string) $this->validated('status');
    }

    /**
     * The entries chosen, by line, each with the subnet to create when its
     * box is ticked.
     *
     * @return array<int, array{catalog_vlan_id: int, vid: int, name: string, subnet: array{network: string, prefix_length: int, gateway: string|null}|null, apply_breakdown: bool}>
     */
    public function lines(): array
    {
        /** @var array<int, array<string, mixed>> $lines */
        $lines = $this->validated('lines');

        return array_map(
            fn (array $line): array => [
                'catalog_vlan_id' => (int) $line['catalog_vlan_id'],
                'vid' => (int) $line['vid'],
                'name' => (string) $line['name'],
                'subnet' => ($line['with_subnet'] ?? false) === true ? [
                    'network' => (string) $line['network'],
                    'prefix_length' => (int) $line['prefix_length'],
                    'gateway' => $line['gateway'] ?? null,
                ] : null,
                'apply_breakdown' => ($line['apply_breakdown'] ?? false) === true,
            ],
            $lines,
        );
    }
}
