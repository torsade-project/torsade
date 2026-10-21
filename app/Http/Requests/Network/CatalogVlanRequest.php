<?php

// torsade/app/Http/Requests/Network/CatalogVlanRequest.php

namespace App\Http\Requests\Network;

use App\Models\CatalogVlan;
use App\Models\Vlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CatalogVlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entry = $this->route('catalogVlan');

        return (bool) ($entry instanceof CatalogVlan
            ? $this->user()?->can('update', $entry)
            : $this->user()?->can('create', CatalogVlan::class));
    }

    /**
     * The number and the name must each be unique in the catalogue: the
     * database refuses a duplicate, and App\Services\Network\CatalogVlans
     * turns the refusal into a message.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'vid' => ['required', 'integer', 'between:'.Vlan::MIN_VID.','.Vlan::MAX_VID],
            'name' => ['required', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array{vid: int, name: string, description: string|null}
     */
    public function entryData(): array
    {
        return [
            'vid' => $this->integer('vid'),
            'name' => (string) $this->validated('name'),
            'description' => $this->validated('description'),
        ];
    }
}
