<?php

// torsade/app/Http/Requests/Network/DeviceMemberRequest.php

namespace App\Http\Requests\Network;

use App\Enums\MemberRole;
use App\Models\DeviceMember;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeviceMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $member = $this->route('deviceMember');

        return (bool) ($member instanceof DeviceMember
            ? $this->user()?->can('update', $member)
            : $this->user()?->can('create', DeviceMember::class));
    }

    /**
     * The shape of a member only. That its number is free in its device,
     * that the device has one primary and one secondary at most and that
     * its room belongs to the site of the device depend on stored data:
     * App\Services\Network\DeviceMembers enforces them.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'position' => ['required', 'integer', 'between:'.DeviceMember::MIN_POSITION.','.DeviceMember::MAX_POSITION],
            'role' => ['required', Rule::enum(MemberRole::class)],
            // Optional: a member can get its ports one series at a time.
            'device_template_id' => ['nullable', 'integer', Rule::exists('device_templates', 'id')],
            'serial_number' => ['nullable', 'string', 'max:100'],
            // Left empty, the member is where its device is.
            'room_id' => ['nullable', 'integer', Rule::exists('rooms', 'id')],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array{position: int, role: string, device_template_id: int|null, serial_number: string|null, room_id: int|null, description: string|null}
     */
    public function memberData(): array
    {
        return [
            'position' => $this->integer('position'),
            'role' => (string) $this->validated('role'),
            'device_template_id' => $this->validated('device_template_id') === null ? null : (int) $this->validated('device_template_id'),
            'serial_number' => $this->validated('serial_number'),
            'room_id' => $this->validated('room_id') === null ? null : (int) $this->validated('room_id'),
            'description' => $this->validated('description'),
        ];
    }
}
