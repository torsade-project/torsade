<?php

// torsade/app/Http/Requests/Network/DeviceShowRequest.php

namespace App\Http\Requests\Network;

use App\Models\Device;
use App\Models\Jack;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The page of a device, and what its windows look for: what is typed in
 * "Attach an address" to find an address that belongs to no device, or in
 * "Link…" to find the opposite device, and the device chosen there, whose
 * free ports are listed; what is typed in the field "Jack" of the window of
 * a new address; the aggregate in front of which a new one is offered; the
 * two ports cabled to each other between which a link is offered.
 */
class DeviceShowRequest extends FormRequest
{
    public function authorize(): bool
    {
        $device = $this->route('device');

        return $device instanceof Device && (bool) $this->user()?->can('view', $device);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The beginning of an address, or a part of a host name; a
            // part of the name of a device.
            'search' => ['nullable', 'string', 'max:253'],
            // The device a port is being linked to.
            'opposite' => ['nullable', 'integer'],
            // A part of the label of a jack, and the site of the subnet of
            // the new address, whose jacks are offered; left out, the
            // jacks of every site.
            'jack_search' => ['nullable', 'string', 'max:'.Jack::MAX_LABEL_LENGTH],
            'jack_site' => ['nullable', 'integer', Rule::exists('sites', 'id')],
            // An aggregate of another device whose ports in front, on this
            // device, are in no aggregate: the window of a new aggregate
            // opens with them.
            'opposite_of' => ['nullable', 'integer', 'min:1'],
            // A port of this device cabled to a port of another one without
            // a link: the window "Link…" opens with the second one chosen.
            'link_port' => ['nullable', 'integer', 'min:1'],
            'link_to' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function linkPort(): ?int
    {
        return $this->validated('link_port') === null ? null : (int) $this->validated('link_port');
    }

    public function linkTo(): ?int
    {
        return $this->validated('link_to') === null ? null : (int) $this->validated('link_to');
    }

    public function jackSearch(): string
    {
        return trim((string) $this->validated('jack_search'));
    }

    public function jackSite(): ?int
    {
        return $this->validated('jack_site') === null ? null : (int) $this->validated('jack_site');
    }

    public function oppositeOf(): ?int
    {
        return $this->validated('opposite_of') === null ? null : (int) $this->validated('opposite_of');
    }

    public function opposite(): ?int
    {
        return $this->validated('opposite') === null ? null : (int) $this->validated('opposite');
    }

    public function search(): string
    {
        return trim((string) $this->validated('search'));
    }
}
