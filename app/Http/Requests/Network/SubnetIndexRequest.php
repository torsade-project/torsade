<?php

// torsade/app/Http/Requests/Network/SubnetIndexRequest.php

namespace App\Http\Requests\Network;

use App\Enums\SubnetKind;
use App\Models\Subnet;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubnetIndexRequest extends FormRequest
{
    /**
     * The value of the site filter that asks for the subnets that belong to
     * no site.
     */
    public const string WITHOUT_SITE = 'none';

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', Subnet::class);
    }

    /**
     * The filters of the list of subnets, both optional.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // A site, or the subnets that have none.
            'site' => $this->query('site') === self::WITHOUT_SITE
                ? ['string']
                : ['nullable', 'integer', Rule::exists('sites', 'id')],
            'kind' => ['nullable', Rule::enum(SubnetKind::class)],
        ];
    }
}
