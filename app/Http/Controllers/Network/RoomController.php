<?php

// torsade/app/Http/Controllers/Network/RoomController.php

namespace App\Http\Controllers\Network;

use App\Http\Controllers\Controller;
use App\Http\Requests\Network\RoomRequest;
use App\Models\Floor;
use App\Models\Room;
use App\Services\Network\Places;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The rooms of a floor, managed from the page of its site. Each route of
 * this controller is behind the room policy (see routes/network.php).
 */
class RoomController extends Controller
{
    public function store(RoomRequest $request, Floor $floor, Places $places): RedirectResponse
    {
        $places->createRoom($floor, $request->roomData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room created.')]);

        return to_route('sites.show', $floor->building->site_id);
    }

    public function update(RoomRequest $request, Room $room, Places $places): RedirectResponse
    {
        $places->updateRoom($room, $request->roomData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room updated.')]);

        return to_route('sites.show', $room->siteId());
    }

    public function destroy(Request $request, Room $room, Places $places): RedirectResponse
    {
        $places->deleteRoom($room, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room deleted.')]);

        return to_route('sites.show', $room->siteId());
    }
}
