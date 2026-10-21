<?php

// torsade/app/Http/Controllers/Network/FloorController.php

namespace App\Http\Controllers\Network;

use App\Http\Controllers\Controller;
use App\Http\Requests\Network\FloorRequest;
use App\Models\Building;
use App\Models\Floor;
use App\Services\Network\Places;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The floors of a building, managed from the page of its site. Each route
 * of this controller is behind the floor policy (see routes/network.php).
 */
class FloorController extends Controller
{
    public function store(FloorRequest $request, Building $building, Places $places): RedirectResponse
    {
        $places->createFloor($building, $request->floorData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Floor created.')]);

        return to_route('sites.show', $building->site_id);
    }

    public function update(FloorRequest $request, Floor $floor, Places $places): RedirectResponse
    {
        $places->updateFloor($floor, $request->floorData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Floor updated.')]);

        return to_route('sites.show', $floor->building->site_id);
    }

    public function destroy(Request $request, Floor $floor, Places $places): RedirectResponse
    {
        $places->deleteFloor($floor, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Floor deleted.')]);

        return to_route('sites.show', $floor->building->site_id);
    }
}
