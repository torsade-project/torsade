<?php

// torsade/app/Http/Requests/Network/VlanRequest.php

namespace App\Http\Requests\Network;

use App\Enums\Status;
use App\Http\Requests\Network\Concerns\SplitsCidr;
use App\Models\Vlan;
use App\Rules\Ipv4Address;
use App\Support\Ipv4;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VlanRequest extends FormRequest
{
    use SplitsCidr;

    public function authorize(): bool
    {
        $vlan = $this->route('vlan');

        return (bool) ($vlan instanceof Vlan
            ? $this->user()?->can('update', $vlan)
            : $this->user()?->can('create', Vlan::class));
    }

    protected function prepareForValidation(): void
    {
        // Whatever the encoding of the request, a real boolean.
        $this->merge(['with_subnet' => $this->boolean('with_subnet')]);

        if (is_array($this->input('subnet'))) {
            $this->merge(['subnet' => [
                ...$this->input('subnet'),
                ...$this->splitCidr($this->input('subnet.network'), $this->input('subnet.prefix_length')),
            ]]);
        }
    }

    /**
     * The number, the name and the entry of the catalogue must be unique
     * within the site: the database refuses a duplicate, and
     * App\Services\Network\Vlans turns the refusal into a message. The rules of the addressing plan are enforced by
     * App\Services\Network\Subnets.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'site_id' => ['required', 'integer', Rule::exists('sites', 'id')],
            // Optional: the entry of the catalogue this VLAN follows.
            'catalog_vlan_id' => ['nullable', 'integer', Rule::exists('catalog_vlans', 'id')],
            'vid' => ['required', 'integer', 'between:'.Vlan::MIN_VID.','.Vlan::MAX_VID],
            'name' => ['required', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::enum(Status::class)],
        ];

        // The subnet can only be created along with a new VLAN; afterwards
        // it is managed on its own.
        if ($this->route('vlan') === null) {
            $rules += [
                'with_subnet' => ['boolean'],
                'subnet' => ['exclude_unless:with_subnet,true', 'required', 'array'],
                'subnet.network' => ['exclude_unless:with_subnet,true', 'required', 'string', new Ipv4Address],
                'subnet.prefix_length' => ['exclude_unless:with_subnet,true', 'required', 'integer', 'between:1,'.Ipv4::MAX_PREFIX],
                'subnet.gateway' => ['exclude_unless:with_subnet,true', 'nullable', 'string', new Ipv4Address],
                // Cuts the subnet into the ranges of the addressing rule.
                // Only the box is sent: the server works the ranges out.
                'subnet.apply_breakdown' => ['exclude_unless:with_subnet,true', 'sometimes', 'boolean'],
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'subnet.network' => (string) trans('validation.attributes.network'),
            'subnet.prefix_length' => (string) trans('validation.attributes.prefix_length'),
            'subnet.gateway' => (string) trans('validation.attributes.gateway'),
        ];
    }

    /**
     * The values of the VLAN itself.
     *
     * @return array{site_id: int, catalog_vlan_id: int|null, vid: int, name: string, description: string|null, status: string}
     */
    public function vlanData(): array
    {
        return [
            'site_id' => $this->integer('site_id'),
            'catalog_vlan_id' => $this->validated('catalog_vlan_id') === null ? null : (int) $this->validated('catalog_vlan_id'),
            'vid' => $this->integer('vid'),
            'name' => (string) $this->validated('name'),
            'description' => $this->validated('description'),
            'status' => (string) $this->validated('status'),
        ];
    }

    /**
     * Whether the subnet created with the VLAN is to be cut into the
     * ranges of the addressing rule.
     */
    public function applyBreakdown(): bool
    {
        return $this->subnetData() !== null && $this->boolean('subnet.apply_breakdown');
    }

    /**
     * The subnet to create with the VLAN, when the section was filled in.
     *
     * @return array{network: string, prefix_length: int, gateway: string|null}|null
     */
    public function subnetData(): ?array
    {
        if ($this->route('vlan') !== null || ! $this->boolean('with_subnet')) {
            return null;
        }

        return [
            'network' => (string) $this->validated('subnet.network'),
            'prefix_length' => (int) $this->validated('subnet.prefix_length'),
            'gateway' => $this->validated('subnet.gateway'),
        ];
    }
}
