<?php

// torsade/app/Http/Controllers/Network/BuildingController.php

namespace App\Http\Controllers\Network;

use App\Http\Controllers\Controller;
use App\Http\Requests\Network\BuildingRequest;
use App\Models\Building;
use App\Models\Site;
use App\Services\Network\Places;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The buildings of a site, managed from the page of that site. Each route
 * of this controller is behind the building policy (see
 * routes/network.php).
 */
class BuildingController extends Controller
{
    public function store(BuildingRequest $request, Site $site, Places $places): RedirectResponse
    {
        $places->createBuilding($site, $request->buildingData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Building created.')]);

        return to_route('sites.show', $site);
    }

    public function update(BuildingRequest $request, Building $building, Places $places): RedirectResponse
    {
        $places->updateBuilding($building, $request->buildingData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Building updated.')]);

        return to_route('sites.show', $building->site_id);
    }

    public function destroy(Request $request, Building $building, Places $places): RedirectResponse
    {
        $places->deleteBuilding($building, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Building deleted.')]);

        return to_route('sites.show', $building->site_id);
    }
}
