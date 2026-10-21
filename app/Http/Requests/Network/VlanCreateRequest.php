<?php

// torsade/app/Http/Requests/Network/VlanCreateRequest.php

namespace App\Http\Requests\Network;

use App\Models\Vlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Opening the form of a new VLAN, possibly with its site or its entry of
 * the catalogue already chosen.
 */
class VlanCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Vlan::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'site' => ['nullable', 'integer', Rule::exists('sites', 'id')],
            'catalog' => ['nullable', 'integer', Rule::exists('catalog_vlans', 'id')],
        ];
    }
}
