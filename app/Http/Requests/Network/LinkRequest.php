<?php

// torsade/app/Http/Requests/Network/LinkRequest.php

namespace App\Http\Requests\Network;

use App\Enums\LinkMedium;
use App\Enums\Status;
use App\Models\Link;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What a link is, apart from its ends: its medium, its status and its
 * description. The ends of a link never change.
 */
class LinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        $link = $this->route('link');

        return $link instanceof Link && (bool) $this->user()?->can('update', $link);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return self::linkRules();
    }

    /**
     * The rules shared with the request that creates links.
     *
     * @return array<string, array<mixed>>
     */
    public static function linkRules(): array
    {
        return [
            // Left out or empty: the medium is not known.
            'medium' => ['nullable', Rule::enum(LinkMedium::class)],
            'status' => ['required', Rule::enum(Status::class)],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array{medium: string|null, status: string, description: string|null}
     */
    public function linkData(): array
    {
        return [
            'medium' => $this->validated('medium'),
            'status' => (string) $this->validated('status'),
            'description' => $this->validated('description'),
        ];
    }
}
