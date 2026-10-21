<?php

// torsade/app/Http/Requests/Network/BuildingRequest.php

namespace App\Http\Requests\Network;

use App\Models\Building;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BuildingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $building = $this->route('building');

        return (bool) ($building instanceof Building
            ? $this->user()?->can('update', $building)
            : $this->user()?->can('create', Building::class));
    }

    /**
     * The name and the code must be unique within the site: the database
     * refuses a duplicate, and App\Services\Network\Places turns the
     * refusal into a message. The same service writes the code in capitals.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            // Used in labels: plain letters, digits, hyphens.
            'code' => ['nullable', 'string', 'max:'.SiteRequest::MAX_CODE_LENGTH, 'regex:/^[A-Za-z0-9-]+$/D'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => __('A building code only holds unaccented letters, digits and hyphens.'),
        ];
    }

    /**
     * @return array{name: string, code: string|null, description: string|null}
     */
    public function buildingData(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'code' => $this->validated('code'),
            'description' => $this->validated('description'),
        ];
    }
}
