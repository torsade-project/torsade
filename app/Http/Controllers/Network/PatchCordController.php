<?php

// torsade/app/Http/Controllers/Network/PatchCordController.php

namespace App\Http\Controllers\Network;

use App\Http\Controllers\Controller;
use App\Http\Requests\Network\PatchCordRequest;
use App\Http\Requests\Network\PatchCordsDeleteRequest;
use App\Http\Requests\Network\PatchCordUpdateRequest;
use App\Models\Device;
use App\Models\PatchCord;
use App\Models\PatchPanel;
use App\Services\Network\PatchCords;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The patch cords, laid, changed and taken away from the page of a panel,
 * and taken away at once from the page of a device.
 * Each route of this controller is behind the patch cord policy (see
 * routes/network.php).
 */
class PatchCordController extends Controller
{
    /**
     * Ports of a panel patched to as many ports of a device: all of them,
     * or none.
     */
    public function store(PatchCordRequest $request, PatchPanel $patchPanel, PatchCords $cords): RedirectResponse
    {
        $created = $cords->create(
            $patchPanel,
            $request->panelPortIds(),
            $request->deviceId(),
            $request->portId(),
            $request->cordData(),
            Actor::fromRequest($request),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count port patched.|:count ports patched.', $created)]);

        return to_route('patch-panels.show', $patchPanel);
    }

    /**
     * The colour and the description of a cord, from the page of its panel.
     * The cord is taken by its identifier, not looked for before the
     * service: one unpatched in the meantime is told in words.
     */
    public function update(PatchCordUpdateRequest $request, int $patchCord, PatchCords $cords): RedirectResponse
    {
        $cords->update($patchCord, $request->cordData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cord updated.')]);

        return back();
    }

    /**
     * The ports of a panel ticked on its page unpatched at once: those
     * without a cord are left as they are.
     */
    public function destroyMany(PatchCordsDeleteRequest $request, PatchPanel $patchPanel, PatchCords $cords): RedirectResponse
    {
        $deleted = $cords->deleteMany($patchPanel, $request->panelPortIds(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count cord removed.|:count cords removed.', $deleted)]);

        return to_route('patch-panels.show', $patchPanel);
    }

    /**
     * Every port of a device unpatched at once, from the page of the
     * device: what moving it to another site asks for first.
     */
    public function destroyAllOf(Request $request, Device $device, PatchCords $cords): RedirectResponse
    {
        $deleted = $cords->deleteAllOf($device, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count cord removed.|:count cords removed.', $deleted)]);

        return to_route('devices.show', $device);
    }

    /**
     * A cord is taken away from the page of its panel: the answer goes back
     * to where it was asked.
     */
    public function destroy(Request $request, PatchCord $patchCord, PatchCords $cords): RedirectResponse
    {
        $cords->delete($patchCord, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Port unpatched.')]);

        return back();
    }
}
