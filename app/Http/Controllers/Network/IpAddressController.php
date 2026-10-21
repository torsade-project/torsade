<?php

// torsade/app/Http/Controllers/Network/IpAddressController.php

namespace App\Http\Controllers\Network;

use App\Http\Controllers\Controller;
use App\Http\Requests\Network\IpAddressBulkRequest;
use App\Http\Requests\Network\IpAddressRequest;
use App\Models\IpAddress;
use App\Models\Subnet;
use App\Services\Network\IpAddresses;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * IP addresses, managed from the page of their subnet; one can also be
 * created from the page of the device it is an interface of. Each route of
 * this controller is behind the IP address policy (see routes/network.php).
 */
class IpAddressController extends Controller
{
    public function store(IpAddressRequest $request, Subnet $subnet, IpAddresses $addresses): RedirectResponse
    {
        $address = $addresses->create($subnet, $request->addressData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('IP address created.')]);

        // Back to where the address was created from: the page of its
        // device, or the page of its subnet.
        return $request->boolean('from_device') && $address->device_id !== null
            ? to_route('devices.show', $address->device_id)
            : to_route('subnets.show', $subnet);
    }

    /**
     * The quick entry: every line is checked in this one request, and
     * nothing is saved unless all of them are accepted.
     */
    public function bulk(IpAddressBulkRequest $request, Subnet $subnet, IpAddresses $addresses): RedirectResponse
    {
        $created = $addresses->createMany($subnet, $request->lines(), Actor::fromRequest($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(':count IP address created.|:count IP addresses created.', count($created)),
        ]);

        return to_route('subnets.show', $subnet);
    }

    public function update(IpAddressRequest $request, IpAddress $ipAddress, IpAddresses $addresses): RedirectResponse
    {
        $addresses->update($ipAddress, $request->addressData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('IP address updated.')]);

        return to_route('subnets.show', $ipAddress->subnet_id);
    }

    public function destroy(Request $request, IpAddress $ipAddress, IpAddresses $addresses): RedirectResponse
    {
        $addresses->delete($ipAddress, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('IP address deleted.')]);

        return to_route('subnets.show', $ipAddress->subnet_id);
    }
}
