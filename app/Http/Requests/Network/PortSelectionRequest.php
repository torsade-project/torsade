<?php

// torsade/app/Http/Requests/Network/PortSelectionRequest.php

namespace App\Http\Requests\Network;

use App\Models\Port;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The ports of a device ticked in its table, to be deleted together.
 */
class PortSelectionRequest extends FormRequest
{
    /**
     * How many ports one action takes at most: more than the largest stack
     * holds.
     */
    public const int MAX_PORTS = 2048;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('delete', Port::class);
    }

    /**
     * That the ports belong to the device is checked by
     * App\Services\Network\Ports, which does the action for all of them or
     * for none.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ports' => ['required', 'array', 'min:1', 'max:'.self::MAX_PORTS],
            'ports.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return list<int>
     */
    public function portIds(): array
    {
        return array_values(array_map(intval(...), (array) $this->validated('ports')));
    }
}
