<?php

// torsade/app/Http/Requests/Network/SubnetCreateRequest.php

namespace App\Http\Requests\Network;

use App\Models\Subnet;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Opening the form of a new subnet, possibly with its VLAN or its site
 * already chosen.
 */
class SubnetCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Subnet::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'vlan' => ['nullable', 'integer', Rule::exists('vlans', 'id')],
            'site' => ['nullable', 'integer', Rule::exists('sites', 'id')],
        ];
    }
}
