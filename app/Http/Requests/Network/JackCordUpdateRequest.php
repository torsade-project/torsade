<?php

// torsade/app/Http/Requests/Network/JackCordUpdateRequest.php

namespace App\Http\Requests\Network;

use App\Models\JackCord;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The colour and the description of a jack cord: its two ends do not
 * change.
 */
class JackCordUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', JackCord::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'color' => ['nullable', 'string', 'max:'.JackCord::MAX_COLOR_LENGTH],
            'description' => ['nullable', 'string', 'max:'.JackCord::MAX_DESCRIPTION_LENGTH],
        ];
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
