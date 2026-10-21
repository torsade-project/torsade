<?php

// torsade/app/Http/Requests/Network/Concerns/IpAddressRules.php

namespace App\Http\Requests\Network\Concerns;

use App\Enums\DeviceType;
use App\Enums\IpAssignment;
use App\Enums\Status;
use App\Rules\Ipv4Address;
use App\Rules\MacAddressFormat;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The shape of an IP address record, shared by the single form and by each
 * line of the quick entry. Where the address may lie depends on stored data:
 * App\Services\Network\IpAddresses enforces it.
 */
trait IpAddressRules
{
    /**
     * Letters, digits, hyphens, underscores and dots. The underscore is
     * outside RFC 1123 but common in the names of Windows machines.
     */
    private const string HOSTNAME_PATTERN = '/^[A-Za-z0-9._-]+$/D';

    /**
     * @param  string  $prefix  "" for a single record, "addresses.*." for the lines of an entry.
     * @param  bool  $defaultsAllowed  Whether assignment and status may be left out.
     * @return array<string, list<ValidationRule|string>>
     */
    private function ipAddressRules(string $prefix = '', bool $defaultsAllowed = false): array
    {
        $presence = $defaultsAllowed ? 'nullable' : 'required';

        return [
            $prefix.'address' => ['required', 'string', new Ipv4Address],
            $prefix.'hostname' => ['nullable', 'string', 'max:253', 'regex:'.self::HOSTNAME_PATTERN],
            $prefix.'mac_address' => ['nullable', 'string', new MacAddressFormat],
            $prefix.'device_type' => ['nullable', Rule::enum(DeviceType::class)],
            $prefix.'assignment' => [$presence, Rule::enum(IpAssignment::class)],
            $prefix.'status' => [$presence, Rule::enum(Status::class)],
            $prefix.'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function ipAddressMessages(string $prefix = ''): array
    {
        return [
            $prefix.'hostname.regex' => __('A host name only contains letters, digits, hyphens, underscores and dots.'),
        ];
    }

    /**
     * Readable field names for the lines of an entry, whose keys are
     * "addresses.<line>.<field>".
     *
     * @return array<string, string>
     */
    private function ipAddressAttributes(string $prefix): array
    {
        $attributes = [];

        foreach (['address', 'hostname', 'mac_address', 'device_type', 'assignment', 'status', 'description'] as $field) {
            $attributes[$prefix.$field] = (string) trans('validation.attributes.'.$field);
        }

        return $attributes;
    }
}
