<?php

// torsade/app/Http/Requests/Network/CatalogVlanAttachRequest.php

namespace App\Http\Requests\Network;

use App\Models\CatalogVlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Attaching several VLANs to an entry of the catalogue at once.
 */
class CatalogVlanAttachRequest extends FormRequest
{
    /**
     * Far above what an organisation has of sites: an entry is followed by
     * one VLAN per site at most.
     */
    public const int MAX_VLANS = 500;

    public function authorize(): bool
    {
        $entry = $this->route('catalogVlan');

        return $entry instanceof CatalogVlan && (bool) $this->user()?->can('update', $entry);
    }

    /**
     * The shape of the list only. Whether each VLAN can be attached depends
     * on stored data: App\Services\Network\CatalogVlans decides, and tells
     * each refusal on the line of its VLAN.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'vlans' => ['required', 'array', 'min:1', 'max:'.self::MAX_VLANS],
            'vlans.*' => ['required', 'integer', 'distinct'],
            // Sent by the page of a VLAN, which attaches that VLAN alone and
            // wants it back instead of the page of the entry.
            'from_vlan' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'vlans.required' => __('Choose at least one VLAN to attach.'),
        ];
    }

    /**
     * The VLANs to attach, by line.
     *
     * @return array<int, int>
     */
    public function vlanIds(): array
    {
        /** @var array<int, int|string> $vlans */
        $vlans = $this->validated('vlans');

        return array_map(intval(...), $vlans);
    }
}
