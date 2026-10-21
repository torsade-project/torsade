<?php

// torsade/app/Http/Requests/SearchRequest.php

namespace App\Http\Requests;

use App\Services\Network\GlobalSearch;
use App\Support\SearchInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What is typed in the search field, without its surrounding spaces (they
 * are trimmed before validation): 1 to 100 characters. The next results
 * of a group are asked for by the group and the page.
 */
class SearchRequest extends FormRequest
{
    public const int MAX_LENGTH = 100;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('view-network');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'max:'.self::MAX_LENGTH],
            'group' => ['nullable', 'string', Rule::in(GlobalSearch::GROUPS)],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.required' => __('Type what you are looking for.'),
            'q.max' => __('A search holds :max characters at most.'),
        ];
    }

    public function searchInput(): SearchInput
    {
        return SearchInput::read((string) $this->validated('q'));
    }

    public function group(): ?string
    {
        $group = $this->validated('group');

        return is_string($group) ? $group : null;
    }

    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }
}
