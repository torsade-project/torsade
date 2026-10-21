<?php

// torsade/app/Http/Requests/Network/RoomRequest.php

namespace App\Http\Requests\Network;

use App\Models\Room;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        $room = $this->route('room');

        return (bool) ($room instanceof Room
            ? $this->user()?->can('update', $room)
            : $this->user()?->can('create', Room::class));
    }

    protected function prepareForValidation(): void
    {
        // Whatever the encoding of the request, a real boolean.
        $this->merge(['is_technical' => $this->boolean('is_technical')]);
    }

    /**
     * The name must be unique within the floor: the database refuses a
     * duplicate, and App\Services\Network\Places turns the refusal into a
     * message.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'is_technical' => ['boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
            // Another floor of the same site, to move the room there. Left
            // out, the room stays where it is.
            'floor_id' => ['sometimes', 'required', 'integer', Rule::exists('floors', 'id')],
        ];
    }

    /**
     * @return array{name: string, is_technical: bool, description: string|null, floor_id?: int}
     */
    public function roomData(): array
    {
        $data = [
            'name' => (string) $this->validated('name'),
            'is_technical' => $this->boolean('is_technical'),
            'description' => $this->validated('description'),
        ];

        // A new room is created on the floor of its address: only an
        // existing room can be moved.
        if ($this->route('room') !== null && $this->has('floor_id')) {
            $data['floor_id'] = $this->integer('floor_id');
        }

        return $data;
    }
}
