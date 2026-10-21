<?php

// torsade/app/Http/Requests/Network/SiteRequest.php

namespace App\Http\Requests\Network;

use App\Enums\Status;
use App\Models\Site;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SiteRequest extends FormRequest
{
    public const int MAX_CODE_LENGTH = 10;

    public function authorize(): bool
    {
        $site = $this->route('site');

        return (bool) ($site instanceof Site
            ? $this->user()?->can('update', $site)
            : $this->user()?->can('create', Site::class));
    }

    /**
     * The name and the code must be unique: the database refuses a
     * duplicate, and App\Services\Network\Sites turns the refusal into a
     * message. The same service writes the code in capitals.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            // Used in the names of devices: plain letters, digits, hyphens.
            'code' => ['nullable', 'string', 'max:'.self::MAX_CODE_LENGTH, 'regex:/^[A-Za-z0-9-]+$/D'],
            // The rank of the site in the addressing rule. A number the rule
            // does not reach is accepted: it is shown as a deviation.
            'number' => ['nullable', 'integer', 'between:'.Site::MIN_NUMBER.','.Site::MAX_NUMBER],
            'status' => ['required', Rule::enum(Status::class)],
            'description' => ['nullable', 'string', 'max:1000'],
            // Declares, with the site, the block the addressing rule
            // expects for its number.
            'create_block' => ['sometimes', 'boolean'],
            // Says where to go back to: the page of the site, or the list.
            'from_page' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => __('A site code only holds unaccented letters, digits and hyphens.'),
        ];
    }

    /**
     * @return array{name: string, code: string|null, number: int|null, status: string, description: string|null}
     */
    public function siteData(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'code' => $this->validated('code'),
            'number' => $this->validated('number') === null ? null : (int) $this->validated('number'),
            'status' => (string) $this->validated('status'),
            'description' => $this->validated('description'),
        ];
    }
}
