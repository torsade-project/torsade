<?php

// torsade/app/Http/Controllers/Network/LinkController.php

namespace App\Http\Controllers\Network;

use App\Enums\LinkMedium;
use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Presenters\NetworkPresenter;
use App\Http\Requests\Network\LinkCreateRequest;
use App\Http\Requests\Network\LinkIndexRequest;
use App\Http\Requests\Network\LinkRequest;
use App\Models\Device;
use App\Models\Link;
use App\Models\Site;
use App\Services\Network\CabledPaths;
use App\Services\Network\LinkDeviations;
use App\Services\Network\Links;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The links between the ports of devices. Each route of this controller is
 * behind the link policy (see routes/network.php).
 */
class LinkController extends Controller
{
    /**
     * Every link, by the device of its end 1, with where its two ends do
     * not agree. The filters keep the links of a site, whichever of their
     * ends is on it, those of a status, and those with a deviation.
     *
     * The number of queries does not depend on the number of links.
     */
    public function index(LinkIndexRequest $request, LinkDeviations $deviations, CabledPaths $paths): Response
    {
        $site = $request->site();
        $status = $request->status();
        $differing = $request->onlyDeviations();

        $links = Link::query()
            ->with([...LinkDeviations::RELATIONS, 'ends.port.member', 'ends.port.device.site'])
            ->get()
            ->sortBy([
                fn (Link $a, Link $b): int => strnatcasecmp($a->portAt(1)->device->name, $b->portAt(1)->device->name),
                fn (Link $a, Link $b): int => $a->portAt(1)->device_id <=> $b->portAt(1)->device_id,
                fn (Link $a, Link $b): int => $a->portAt(1)->member->position <=> $b->portAt(1)->member->position,
                fn (Link $a, Link $b): int => $a->portAt(1)->position <=> $b->portAt(1)->position,
                fn (Link $a, Link $b): int => $a->id <=> $b->id,
            ])
            ->values();

        // The deviations are worked out on every link, before the filters:
        // whether an aggregate faces one same aggregate is told by all the
        // links between two devices, shown or not. An aggregated link is
        // kept whole, as soon as one of its links is. The cabling of every
        // site tells the path of each link and where it differs.
        $map = $paths->of();

        $lines = array_values(array_filter(
            NetworkPresenter::linkLines($links, $deviations->ofLinks($links, cabling: $map), paths: $map->paths()),
            fn (array $line): bool => ($site === null || array_any($line['links'], fn (array $link): bool => in_array($site, [$link['near']['device']['site']['id'], $link['far']['device']['site']['id']], true)))
                && ($status === null || array_any($line['links'], fn (array $link): bool => $link['status'] === $status))
                && (! $differing || $line['deviations'] !== []),
        ));

        return Inertia::render('links/Index', [
            'links' => $lines,
            'total' => $links->count(),
            'filters' => [
                'site' => $site === null ? '' : (string) $site,
                'status' => $status ?? '',
                'deviations' => $differing ? '1' : '',
            ],
            'sites' => array_values(
                Site::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Site $site): array => ['id' => $site->id, 'name' => $site->name])
                    ->all(),
            ),
            // The statuses of a link, agreed with it.
            'statuses' => Status::options(feminine: true),
            'media' => LinkMedium::options(),
        ]);
    }

    /**
     * Ports of a device linked to as many ports of another one: all of
     * them, or none.
     */
    public function store(LinkCreateRequest $request, Device $device, Links $links): RedirectResponse
    {
        $created = $links->create(
            $device,
            $request->portIds(),
            $request->oppositeDeviceId(),
            $request->oppositePortIds(),
            $request->linkData(),
            Actor::fromRequest($request),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count link created.|:count links created.', $created)]);

        return to_route('devices.show', $device);
    }

    /**
     * A link is changed from the page of one of its two devices or from
     * the list of links: the answer goes back to where it was asked.
     */
    public function update(LinkRequest $request, Link $link, Links $links): RedirectResponse
    {
        $links->update($link, $request->linkData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Link updated.')]);

        return back();
    }

    public function destroy(Request $request, Link $link, Links $links): RedirectResponse
    {
        $links->delete($link, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Link deleted.')]);

        return back();
    }
}
