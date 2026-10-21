<?php

// torsade/app/Http/Requests/Network/JackCordRequest.php

namespace App\Http\Requests\Network;

use App\Models\JackCord;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A port of a device plugged into a wall jack, from the line of the port or
 * from the jack: the jack, the port, and what the cord is.
 */
class JackCordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', JackCord::class);
    }

    /**
     * The shape of the request only. That the jack and the port are of one
     * site, that neither is retired, and that neither has a cord yet nor the
     * port a patch cord, depend on stored data: App\Services\Network\JackCords
     * enforces them.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'jack_id' => ['required', 'integer'],
            'port_id' => ['required', 'integer'],
            // "Blue": free text.
            'color' => ['nullable', 'string', 'max:'.JackCord::MAX_COLOR_LENGTH],
            'description' => ['nullable', 'string', 'max:'.JackCord::MAX_DESCRIPTION_LENGTH],
        ];
    }

    public function jackId(): int
    {
        return (int) $this->validated('jack_id');
    }

    public function portId(): int
    {
        return (int) $this->validated('port_id');
    }

    /**
     * @return array{color: string|null, description: string|null}
     */
    public function cordData(): array
    {
        return [
            'color' => $this->validated('color'),
            'description' => $this->validated('description'),
        ];
    }
}
