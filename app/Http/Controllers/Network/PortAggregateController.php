<?php

// torsade/app/Http/Controllers/Network/PortAggregateController.php

namespace App\Http\Controllers\Network;

use App\Http\Controllers\Controller;
use App\Http\Requests\Network\PortAggregateRequest;
use App\Models\Device;
use App\Models\PortAggregate;
use App\Services\Network\PortAggregates;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The aggregates of ports of a device, managed from its page. Each route
 * of this controller is behind the aggregate policy (see
 * routes/network.php).
 */
class PortAggregateController extends Controller
{
    public function store(PortAggregateRequest $request, Device $device, PortAggregates $aggregates): RedirectResponse
    {
        $aggregates->create($device, $request->aggregateData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Aggregate created.')]);

        return to_route('devices.show', $device);
    }

    public function update(PortAggregateRequest $request, PortAggregate $portAggregate, PortAggregates $aggregates): RedirectResponse
    {
        $aggregates->update($portAggregate, $request->aggregateData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Aggregate updated.')]);

        return to_route('devices.show', $portAggregate->device_id);
    }

    public function destroy(Request $request, PortAggregate $portAggregate, PortAggregates $aggregates): RedirectResponse
    {
        $aggregates->delete($portAggregate, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Aggregate deleted.')]);

        return to_route('devices.show', $portAggregate->device_id);
    }
}
