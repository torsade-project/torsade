<?php

// torsade/app/Http/Requests/Network/IpAddressBulkRequest.php

namespace App\Http\Requests\Network;

use App\Http\Requests\Network\Concerns\IpAddressRules;
use App\Models\IpAddress;
use App\Models\Subnet;
use App\Services\Network\IpAddresses;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Several addresses of one subnet, sent together by the quick entry.
 */
class IpAddressBulkRequest extends FormRequest
{
    use IpAddressRules;

    /**
     * An entry stays small enough to be read again before it is saved, and
     * to be checked in one request.
     */
    public const int MAX_LINES = 100;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', IpAddress::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'addresses' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_LINES],
            'addresses.*' => ['required', 'array'],
            ...$this->ipAddressRules('addresses.*.', defaultsAllowed: true),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'addresses.required' => __('Enter at least one address.'),
            // The limit itself is written into the message by the validator.
            'addresses.max' => trans_choice(
                'No more than :max address can be saved at once.|No more than :max addresses can be saved at once.',
                self::MAX_LINES,
            ),
            ...$this->ipAddressMessages('addresses.*.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->ipAddressAttributes('addresses.*.');
    }

    /**
     * The rules of the addressing plan are checked on every line right after
     * the rules above, even when some of those failed: all the messages of an
     * entry then come back together, each on its line and its field.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $subnet = $this->route('subnet');
            $lines = $this->input('addresses');

            if (! $subnet instanceof Subnet || ! is_array($lines) || ! array_is_list($lines) || count($lines) > self::MAX_LINES) {
                return;
            }

            $messages = app(IpAddresses::class)->check($subnet, $this->readableLines($lines));

            foreach ($messages as $key => $message) {
                // One message per field is enough.
                if (! $validator->errors()->has($key)) {
                    $validator->errors()->add($key, $message);
                }
            }
        }];
    }

    /**
     * The lines whose address is at least a text, with nothing but texts in
     * their fields: what can be compared with the addressing plan even
     * before the entry is known to be valid. Line numbers are kept.
     *
     * @param  list<mixed>  $lines
     * @return array<int, array{address: string, hostname: string|null, mac_address: string|null, device_type: string|null, assignment: string|null, status: string|null, description: string|null}>
     */
    private function readableLines(array $lines): array
    {
        $text = fn (mixed $value): ?string => is_string($value) ? $value : null;

        $readable = [];

        foreach ($lines as $number => $line) {
            if (! is_array($line) || ! is_string($line['address'] ?? null)) {
                continue;
            }

            $readable[$number] = [
                'address' => $line['address'],
                'hostname' => $text($line['hostname'] ?? null),
                'mac_address' => $text($line['mac_address'] ?? null),
                'device_type' => $text($line['device_type'] ?? null),
                'assignment' => $text($line['assignment'] ?? null),
                'status' => $text($line['status'] ?? null),
                'description' => $text($line['description'] ?? null),
            ];
        }

        return $readable;
    }

    /**
     * The lines of the entry, in the order they were sent.
     *
     * @return list<array{address: string, hostname?: string|null, mac_address?: string|null, device_type?: string|null, assignment?: string|null, status?: string|null, description?: string|null}>
     */
    public function lines(): array
    {
        /** @var list<array{address: string, hostname?: string|null, mac_address?: string|null, device_type?: string|null, assignment?: string|null, status?: string|null, description?: string|null}> $lines */
        $lines = array_values((array) $this->validated('addresses'));

        return $lines;
    }
}
