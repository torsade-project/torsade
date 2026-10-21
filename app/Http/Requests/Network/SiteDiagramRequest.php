<?php

// torsade/app/Http/Requests/Network/SiteDiagramRequest.php

namespace App\Http\Requests\Network;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The page of a diagram of a site: the site is in its address.
 */
abstract class SiteDiagramRequest extends FormRequest
{
    /**
     * The site whose diagram is shown; the first one when none is given.
     * One that does not exist is a page not found, not a refusal: a link
     * may outlive the site it was shared for.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'site' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function site(): ?int
    {
        return $this->validated('site') === null ? null : (int) $this->validated('site');
    }
}
