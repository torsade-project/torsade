<?php

// torsade/app/Http/Requests/Network/SubnetRequest.php

namespace App\Http\Requests\Network;

use App\Enums\Status;
use App\Enums\SubnetKind;
use App\Http\Requests\Network\Concerns\SplitsCidr;
use App\Models\Subnet;
use App\Rules\Ipv4Address;
use App\Support\Ipv4;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubnetRequest extends FormRequest
{
    use SplitsCidr;

    public function authorize(): bool
    {
        $subnet = $this->route('subnet');

        return (bool) ($subnet instanceof Subnet
            ? $this->user()?->can('update', $subnet)
            : $this->user()?->can('create', Subnet::class));
    }

    protected function prepareForValidation(): void
    {
        $this->merge($this->splitCidr($this->input('network'), $this->input('prefix_length')));
    }

    /**
     * The shape of the values only. The rules of the addressing plan (host
     * bits, overlap, gateway, what its kind accepts, what an existing subnet
     * already holds) depend on stored data: they are enforced by App\Services\Network\Subnets.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'network' => ['required', 'string', new Ipv4Address],
            'prefix_length' => ['required', 'integer', 'between:1,'.Ipv4::MAX_PREFIX],
            // Left out, an existing subnet keeps its kind and a new one is
            // a segment.
            'kind' => ['nullable', Rule::enum(SubnetKind::class)],
            'vlan_id' => ['nullable', 'integer', Rule::exists('vlans', 'id')],
            // Only for a subnet without a VLAN: with one, its site is the
            // site of the VLAN.
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')],
            'gateway' => ['nullable', 'string', new Ipv4Address],
            'name' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::enum(Status::class)],
            // For a new subnet: cuts it into the ranges of the addressing
            // rule. Only the box is sent: the server works the ranges out.
            'apply_breakdown' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Whether the new subnet is to be cut into the ranges of the
     * addressing rule. An existing subnet gets them from its own page.
     */
    public function applyBreakdown(): bool
    {
        return $this->route('subnet') === null && $this->boolean('apply_breakdown');
    }

    /**
     * @return array{network: string, prefix_length: int, kind: string|null, vlan_id: int|null, site_id: int|null, gateway: string|null, name: string|null, description: string|null, status: string}
     */
    public function subnetData(): array
    {
        return [
            'network' => (string) $this->validated('network'),
            'prefix_length' => (int) $this->validated('prefix_length'),
            'kind' => $this->validated('kind'),
            'vlan_id' => $this->validated('vlan_id') === null ? null : (int) $this->validated('vlan_id'),
            'site_id' => $this->validated('site_id') === null ? null : (int) $this->validated('site_id'),
            'gateway' => $this->validated('gateway'),
            'name' => $this->validated('name'),
            'description' => $this->validated('description'),
            'status' => (string) $this->validated('status'),
        ];
    }
}
