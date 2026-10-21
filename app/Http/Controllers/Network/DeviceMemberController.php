<?php

// torsade/app/Http/Controllers/Network/DeviceMemberController.php

namespace App\Http\Controllers\Network;

use App\Http\Controllers\Controller;
use App\Http\Requests\Network\DeviceMemberRequest;
use App\Http\Requests\Network\FirstMemberRequest;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Services\Network\DeviceMembers;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The members of a device, managed from its page. Each route of this
 * controller is behind the member policy (see routes/network.php).
 */
class DeviceMemberController extends Controller
{
    public function store(DeviceMemberRequest $request, Device $device, DeviceMembers $members): RedirectResponse
    {
        $member = $members->add($device, $request->memberData(), Actor::fromRequest($request));

        $this->flashAdded($member);

        return to_route('devices.show', $device);
    }

    /**
     * The template chosen for a device without a member: its member 1,
     * primary, with the ports of the template.
     */
    public function storeFirst(FirstMemberRequest $request, Device $device, DeviceMembers $members): RedirectResponse
    {
        $member = $members->addFirst($device, $request->integer('device_template_id'), Actor::fromRequest($request));

        $this->flashAdded($member);

        return to_route('devices.show', $device);
    }

    public function update(DeviceMemberRequest $request, DeviceMember $deviceMember, DeviceMembers $members): RedirectResponse
    {
        $members->update($deviceMember, $request->memberData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member updated.')]);

        return to_route('devices.show', $deviceMember->device_id);
    }

    public function destroy(Request $request, DeviceMember $deviceMember, DeviceMembers $members): RedirectResponse
    {
        $ports = $members->delete($deviceMember, Actor::fromRequest($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $ports === 0
                ? __('Member deleted.')
                : trans_choice('Member deleted with its port.|Member deleted with its :count ports.', $ports),
        ]);

        return to_route('devices.show', $deviceMember->device_id);
    }

    /**
     * Tell that a member was added, with the number of ports it got from
     * its template.
     */
    private function flashAdded(DeviceMember $member): void
    {
        $ports = $member->ports()->count();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $ports === 0
                ? __('Member added.')
                : trans_choice('Member added with :count port.|Member added with :count ports.', $ports),
        ]);
    }
}
