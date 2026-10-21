<?php

// torsade/app/Http/Requests/Admin/ActivityLogIndexRequest.php

namespace App\Http\Requests\Admin;

use App\Enums\ActivityEvent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActivityLogIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('view-activity-log');
    }

    /**
     * The filters of the activity log page, all optional.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'event' => ['nullable', Rule::enum(ActivityEvent::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }
}
