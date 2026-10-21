<?php

// torsade/app/Http/Requests/Network/AddressBlockRequest.php

namespace App\Http\Requests\Network;

use App\Enums\Status;
use App\Http\Requests\Network\Concerns\SplitsCidr;
use App\Models\AddressBlock;
use App\Rules\Ipv4Address;
use App\Support\Ipv4;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddressBlockRequest extends FormRequest
{
    use SplitsCidr;

    public function authorize(): bool
    {
        $block = $this->route('addressBlock');

        return (bool) ($block instanceof AddressBlock
            ? $this->user()?->can('update', $block)
            : $this->user()?->can('create', AddressBlock::class));
    }

    protected function prepareForValidation(): void
    {
        $this->merge($this->splitCidr($this->input('network'), $this->input('prefix_length')));
    }

    /**
     * The shape of the values only. Where a block may lie (host bits, the
     * other blocks, the subnets around it) depends on stored data: it is
     * enforced by App\Services\Network\AddressBlocks.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'network' => ['required', 'string', new Ipv4Address],
            'prefix_length' => ['required', 'integer', 'between:1,'.Ipv4::MAX_PREFIX],
            // Without a site, the block belongs to the organisation.
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')],
            'name' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::enum(Status::class)],
        ];
    }

    /**
     * @return array{network: string, prefix_length: int, site_id: int|null, name: string|null, description: string|null, status: string}
     */
    public function blockData(): array
    {
        return [
            'network' => (string) $this->validated('network'),
            'prefix_length' => (int) $this->validated('prefix_length'),
            'site_id' => $this->validated('site_id') === null ? null : (int) $this->validated('site_id'),
            'name' => $this->validated('name'),
            'description' => $this->validated('description'),
            'status' => (string) $this->validated('status'),
        ];
    }
}
