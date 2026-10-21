<?php

// torsade/app/Http/Requests/Network/AddressBlockCreateRequest.php

namespace App\Http\Requests\Network;

use App\Models\AddressBlock;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Opening the form of a new block, possibly with its site already chosen.
 */
class AddressBlockCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', AddressBlock::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'site' => ['nullable', 'integer', Rule::exists('sites', 'id')],
        ];
    }
}
