<?php

// torsade/app/Http/Controllers/Network/IpRangeController.php

namespace App\Http\Controllers\Network;

use App\Http\Controllers\Controller;
use App\Http\Requests\Network\IpRangeRequest;
use App\Models\IpRange;
use App\Models\Subnet;
use App\Services\Network\IpRanges;
use App\Services\Network\StandardBreakdown;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The ranges of a subnet, managed from its page. Each route of this
 * controller is behind the policy of ranges (see routes/network.php).
 */
class IpRangeController extends Controller
{
    public function store(IpRangeRequest $request, Subnet $subnet, IpRanges $ranges): RedirectResponse
    {
        $ranges->create($subnet, $request->rangeData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Range created.')]);

        return to_route('subnets.show', $subnet);
    }

    /**
     * Cut a subnet that has no range into the ranges of the addressing
     * rule: all of them, or none. Nothing is sent but the wish: the ranges
     * are worked out from the rule and from the subnet.
     */
    public function storeBreakdown(Request $request, Subnet $subnet, StandardBreakdown $breakdown): RedirectResponse
    {
        $created = $breakdown->apply($subnet, Actor::fromRequest($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(':count range created.|:count ranges created.', count($created)),
        ]);

        return to_route('subnets.show', $subnet);
    }

    public function update(IpRangeRequest $request, IpRange $ipRange, IpRanges $ranges): RedirectResponse
    {
        $ranges->update($ipRange, $request->rangeData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Range updated.')]);

        return to_route('subnets.show', $ipRange->subnet_id);
    }

    public function destroy(Request $request, IpRange $ipRange, IpRanges $ranges): RedirectResponse
    {
        $ranges->delete($ipRange, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Range deleted.')]);

        return to_route('subnets.show', $ipRange->subnet_id);
    }
}
