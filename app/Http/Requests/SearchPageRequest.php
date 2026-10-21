<?php

// torsade/app/Http/Requests/SearchPageRequest.php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The page of results, whose address can be shared: without a search, it
 * only offers to type one. A search refused is told on the page itself,
 * left empty.
 */
class SearchPageRequest extends SearchRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'q' => ['nullable', 'string', 'max:'.self::MAX_LENGTH],
        ];
    }

    public function hasSearch(): bool
    {
        return $this->validated('q') !== null;
    }

    protected function getRedirectUrl(): string
    {
        return route('search');
    }
}
