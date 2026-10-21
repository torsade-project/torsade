<?php

// torsade/app/Http/Requests/Network/SiteIndexRequest.php

namespace App\Http\Requests\Network;

use App\Enums\Status;
use App\Models\Site;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SiteIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', Site::class);
    }

    /**
     * The filter of the list of sites, optional.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(Status::class)],
        ];
    }
}
