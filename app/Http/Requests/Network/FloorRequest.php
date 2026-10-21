<?php

// torsade/app/Http/Requests/Network/FloorRequest.php

namespace App\Http\Requests\Network;

use App\Models\Floor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FloorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $floor = $this->route('floor');

        return (bool) ($floor instanceof Floor
            ? $this->user()?->can('update', $floor)
            : $this->user()?->can('create', Floor::class));
    }

    /**
     * The level and the name must be unique within the building: the
     * database refuses a duplicate, and App\Services\Network\Places turns
     * the refusal into a message.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // -1 for a basement, 0 for the ground floor.
            'level' => ['required', 'integer', 'between:'.Floor::MIN_LEVEL.','.Floor::MAX_LEVEL],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            // Another building of the same site, to move the floor there.
            // Left out, the floor stays where it is.
            'building_id' => ['sometimes', 'required', 'integer', Rule::exists('buildings', 'id')],
        ];
    }

    /**
     * @return array{level: int, name: string, description: string|null, building_id?: int}
     */
    public function floorData(): array
    {
        $data = [
            'level' => $this->integer('level'),
            'name' => (string) $this->validated('name'),
            'description' => $this->validated('description'),
        ];

        // A new floor is created in the building of its address: only an
        // existing floor can be moved.
        if ($this->route('floor') !== null && $this->has('building_id')) {
            $data['building_id'] = $this->integer('building_id');
        }

        return $data;
    }
}
