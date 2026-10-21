<?php

// torsade/app/Http/Requests/Network/SubnetShowRequest.php

namespace App\Http\Requests\Network;

use App\Models\Jack;
use App\Models\Subnet;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The page of a subnet, and what the window of an address looks for: what
 * is typed in its field "Jack".
 */
class SubnetShowRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subnet = $this->route('subnet');

        return $subnet instanceof Subnet && (bool) $this->user()?->can('view', $subnet);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // A part of the label of a jack.
            'jack_search' => ['nullable', 'string', 'max:'.Jack::MAX_LABEL_LENGTH],
        ];
    }

    public function jackSearch(): string
    {
        return trim((string) $this->validated('jack_search'));
    }
}
