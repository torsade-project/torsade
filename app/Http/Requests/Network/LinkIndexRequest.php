<?php

// torsade/app/Http/Requests/Network/LinkIndexRequest.php

namespace App\Http\Requests\Network;

use App\Enums\Status;
use App\Models\Link;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LinkIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', Link::class);
    }

    /**
     * The filters of the list of links, all optional.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The site of one end or of the other.
            'site' => ['nullable', 'integer', Rule::exists('sites', 'id')],
            'status' => ['nullable', Rule::enum(Status::class)],
            // Only the links whose two ends do not agree.
            'deviations' => ['nullable', 'boolean'],
        ];
    }

    public function site(): ?int
    {
        return $this->validated('site') === null ? null : (int) $this->validated('site');
    }

    public function status(): ?string
    {
        return $this->validated('status') === null ? null : (string) $this->validated('status');
    }

    public function onlyDeviations(): bool
    {
        return (bool) $this->validated('deviations');
    }
}
