<?php

// torsade/app/Http/Requests/Network/FirstMemberRequest.php

namespace App\Http\Requests\Network;

use App\Models\DeviceMember;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The template chosen for a device that has no member yet: its first
 * member is made from it.
 */
class FirstMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', DeviceMember::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'device_template_id' => ['required', 'integer', Rule::exists('device_templates', 'id')],
        ];
    }
}
