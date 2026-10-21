<?php

// torsade/app/Http/Controllers/Network/SiteStandardController.php

namespace App\Http\Controllers\Network;

use App\Enums\RangeKind;
use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Presenters\NetworkPresenter;
use App\Http\Requests\Network\SiteStandardRequest;
use App\Models\CatalogVlan;
use App\Models\Site;
use App\Services\Network\Deviations;
use App\Services\Network\SiteStandard;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The standard of the organisation applied to a site in one action. Both
 * routes of this controller are behind the right to create VLANs (see
 * routes/network.php): editors and administrators.
 */
class SiteStandardController extends Controller
{
    /**
     * The entries of the catalogue the site does not follow yet, each one
     * ready to become a VLAN of the site.
     */
    public function create(Site $site, SiteStandard $standard, Deviations $deviations): Response
    {
        return Inertia::render('sites/Standard', [
            'site' => ['id' => $site->id, 'name' => $site->name, 'number' => $site->number],
            'entries' => $standard->entriesFor($site)
                ->map(fn (CatalogVlan $entry): array => NetworkPresenter::catalogOption($entry)),
            // What the addressing rule works out, or null: the page then
            // proposes no subnet, and still creates the VLANs.
            'rule' => $deviations->scheme()?->toArray(),
            'statuses' => Status::options(),
            // The kinds of the ranges of the standard breakdown, each line
            // listing those it would create.
            'rangeKinds' => RangeKind::options(),
            // Applying a standard is first a gesture of design.
            'defaultStatus' => Status::Planned->value,
        ]);
    }

    public function store(SiteStandardRequest $request, Site $site, SiteStandard $standard): RedirectResponse
    {
        $created = $standard->apply($site, $request->status(), $request->lines(), Actor::fromRequest($request));

        $summary = trans_choice(':count VLAN created|:count VLANs created', $created['vlans']);

        if ($created['subnets'] > 0) {
            $summary .= ', '.trans_choice(':count subnet created|:count subnets created', $created['subnets']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $summary.'.']);

        return to_route('sites.show', $site);
    }
}
