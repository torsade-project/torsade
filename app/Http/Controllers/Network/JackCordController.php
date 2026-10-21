<?php

// torsade/app/Http/Controllers/Network/JackCordController.php

namespace App\Http\Controllers\Network;

use App\Http\Controllers\Controller;
use App\Http\Requests\Network\JackCordRequest;
use App\Http\Requests\Network\JackCordUpdateRequest;
use App\Models\Device;
use App\Services\Network\JackCords;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The jack cords: a port of a device plugged into a wall jack from the line
 * of the port, from the jack or from a deviation, changed, and taken away;
 * every port of a device unplugged at once from its page. Each answer goes
 * back to where it was asked. Each route of this controller is behind the
 * jack cord policy (see routes/network.php).
 */
class JackCordController extends Controller
{
    public function store(JackCordRequest $request, JackCords $cords): RedirectResponse
    {
        $cords->create($request->jackId(), $request->portId(), $request->cordData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Port plugged into the jack.')]);

        return back();
    }

    /**
     * The colour and the description of a cord. The cord is taken by its
     * identifier, not looked for before the service: one unplugged in the
     * meantime is told in words.
     */
    public function update(JackCordUpdateRequest $request, int $jackCord, JackCords $cords): RedirectResponse
    {
        $cords->update($jackCord, $request->cordData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cord updated.')]);

        return back();
    }

    /**
     * Unplug: taken by its identifier too, a cord unplugged in the meantime
     * leaving nothing to do.
     */
    public function destroy(Request $request, int $jackCord, JackCords $cords): RedirectResponse
    {
        $cords->delete($jackCord, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Port unplugged from the jack.')]);

        return back();
    }

    /**
     * Every port of a device unplugged at once, from the page of the
     * device: what moving it to another site asks for first.
     */
    public function destroyAllOf(Request $request, Device $device, JackCords $cords): RedirectResponse
    {
        $deleted = $cords->deleteAllOf($device, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count cord removed.|:count cords removed.', $deleted)]);

        return to_route('devices.show', $device);
    }
}
