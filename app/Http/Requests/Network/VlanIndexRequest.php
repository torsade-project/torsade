<?php

// torsade/app/Http/Requests/Network/VlanIndexRequest.php

namespace App\Http\Requests\Network;

use App\Enums\Status;
use App\Models\Vlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VlanIndexRequest extends FormRequest
{
    /**
     * The value of the catalogue filter that asks for the VLANs attached to
     * no entry.
     */
    public const string OUTSIDE_CATALOG = 'none';

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', Vlan::class);
    }

    /**
     * The filters of the VLAN list, all optional.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'site' => ['nullable', 'integer', Rule::exists('sites', 'id')],
            'status' => ['nullable', Rule::enum(Status::class)],
            // An entry of the catalogue, or the VLANs that follow none.
            'catalog' => $this->query('catalog') === self::OUTSIDE_CATALOG
                ? ['string']
                : ['nullable', 'integer', Rule::exists('catalog_vlans', 'id')],
        ];
    }
}
