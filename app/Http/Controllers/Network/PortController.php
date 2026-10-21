<?php

// torsade/app/Http/Controllers/Network/PortController.php

namespace App\Http\Controllers\Network;

use App\Http\Controllers\Controller;
use App\Http\Requests\Network\PortBulkRequest;
use App\Http\Requests\Network\PortRequest;
use App\Http\Requests\Network\PortSelectionRequest;
use App\Http\Requests\Network\PortSeriesRequest;
use App\Models\Device;
use App\Models\Port;
use App\Services\Network\DeviceMembers;
use App\Services\Network\Ports;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The ports of a device, managed from its page. Each route of this
 * controller is behind the port policy (see routes/network.php).
 */
class PortController extends Controller
{
    /**
     * A series of ports added to a member; on a device without a member,
     * to its member 1, created with them.
     */
    public function store(PortSeriesRequest $request, Device $device, DeviceMembers $members): RedirectResponse
    {
        $added = $members->addPorts($device, $request->memberId(), $request->seriesData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count port added.|:count ports added.', $added)]);

        return to_route('devices.show', $device);
    }

    public function update(PortRequest $request, Port $port, Ports $ports): RedirectResponse
    {
        $ports->update($port, $request->portData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Port updated.')]);

        return to_route('devices.show', $port->device_id);
    }

    public function destroy(Request $request, Port $port, Ports $ports): RedirectResponse
    {
        $ports->delete($port, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Port deleted.')]);

        return to_route('devices.show', $port->device_id);
    }

    /**
     * One change for every port ticked: all of them, or none.
     */
    public function updateMany(PortBulkRequest $request, Device $device, Ports $ports): RedirectResponse
    {
        $actor = Actor::fromRequest($request);
        $ids = $request->portIds();

        $message = match ($request->action()) {
            PortBulkRequest::ENABLE => match ($changed = $ports->setEnabled($device, $ids, true, $actor)) {
                0 => __('These ports were already enabled.'),
                default => trans_choice(':count port enabled.|:count ports enabled.', $changed),
            },
            PortBulkRequest::DISABLE => match ($changed = $ports->setEnabled($device, $ids, false, $actor)) {
                0 => __('These ports were already disabled.'),
                default => trans_choice(':count port disabled.|:count ports disabled.', $changed),
            },
            PortBulkRequest::SET_MODE => match ($changed = $ports->setMode($device, $ids, $request->modeData(), $actor)) {
                0 => __('These ports already had this mode and these VLANs.'),
                default => trans_choice(':count port updated.|:count ports updated.', $changed),
            },
            default => match ($changed = $ports->clearMode($device, $ids, $actor)) {
                0 => __('These ports had no mode.'),
                default => trans_choice('The mode of :count port was removed.|The mode of :count ports was removed.', $changed),
            },
        };

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('devices.show', $device);
    }

    public function destroyMany(PortSelectionRequest $request, Device $device, Ports $ports): RedirectResponse
    {
        $deleted = $ports->deleteMany($device, $request->portIds(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count port deleted.|:count ports deleted.', $deleted)]);

        return to_route('devices.show', $device);
    }
}
