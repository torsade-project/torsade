<?php

// torsade/app/Http/Controllers/Network/DeviceAddressController.php

namespace App\Http\Controllers\Network;

use App\Http\Controllers\Controller;
use App\Http\Requests\Network\DeviceAddressRequest;
use App\Models\Device;
use App\Models\IpAddress;
use App\Services\Network\IpAddresses;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The addresses of a device, attached and detached from its page. Each
 * route of this controller asks for the right to update the device (see
 * routes/network.php): its interfaces are changed on its behalf.
 */
class DeviceAddressController extends Controller
{
    /**
     * Attach a documented address that belongs to no device.
     */
    public function store(DeviceAddressRequest $request, Device $device, IpAddresses $addresses): RedirectResponse
    {
        $address = $request->address();

        $addresses->attach($address, $device, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Address :address attached.', ['address' => $address->address])]);

        return to_route('devices.show', $device);
    }

    /**
     * Detach one address. It stays documented in its subnet.
     */
    public function destroy(Request $request, Device $device, IpAddress $ipAddress, IpAddresses $addresses): RedirectResponse
    {
        // An address of another device is not one of this device.
        abort_unless($ipAddress->device_id === $device->id, 404);

        $addresses->detach($ipAddress, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Address :address detached.', ['address' => $ipAddress->address])]);

        return to_route('devices.show', $device);
    }

    /**
     * Detach every address of the device at once: what its deletion asks
     * for first.
     */
    public function destroyAll(Request $request, Device $device, IpAddresses $addresses): RedirectResponse
    {
        $detached = $addresses->detachAll($device, Actor::fromRequest($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(':count address detached.|:count addresses detached.', $detached),
        ]);

        return to_route('devices.show', $device);
    }
}
