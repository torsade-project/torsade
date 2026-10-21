<?php

// torsade/app/Http/Requests/Network/SiteExpectedBlockRequest.php

namespace App\Http\Requests\Network;

use App\Models\AddressBlock;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Declaring the block the addressing rule expects for a site. The block is
 * worked out by the server: nothing of it is sent.
 */
class SiteExpectedBlockRequest extends FormRequest
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
            // Says where to go back to: the page of the rule, or the page
            // of the site.
            'from_rule' => ['sometimes', 'boolean'],
        ];
    }
}
