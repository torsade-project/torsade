<?php

// torsade/app/Http/Requests/Network/JackAttachRequest.php

namespace App\Http\Requests\Network;

use App\Models\Jack;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A jack cabled to a port of a panel, from the page of the panel.
 */
class JackAttachRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', Jack::class);
    }

    /**
     * That the jack exists, is of the site of the panel and is not cabled
     * elsewhere depends on stored data: App\Services\Network\Jacks enforces
     * it.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'jack_id' => ['required', 'integer'],
        ];
    }

    public function jackId(): int
    {
        return (int) $this->validated('jack_id');
    }
}
