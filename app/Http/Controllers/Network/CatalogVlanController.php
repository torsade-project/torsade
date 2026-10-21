<?php

// torsade/app/Http/Controllers/Network/CatalogVlanController.php

namespace App\Http\Controllers\Network;

use App\Exceptions\CatalogVlanTaken;
use App\Http\Controllers\Controller;
use App\Http\Presenters\NetworkPresenter;
use App\Http\Requests\Network\CatalogVlanAttachRequest;
use App\Http\Requests\Network\CatalogVlanRequest;
use App\Models\CatalogVlan;
use App\Models\Site;
use App\Models\Vlan;
use App\Services\Network\CatalogVlans;
use App\Services\Network\Deviations;
use App\Support\Actor;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The VLAN catalogue of the organisation. Each route of this controller is
 * behind the policy of the catalogue (see routes/network.php): reading for
 * every active account, writing for editors and administrators.
 */
class CatalogVlanController extends Controller
{
    /**
     * The catalogue as a table: one line per entry, one column per site,
     * and in each cell the VLAN of that site that follows the entry.
     *
     * Three queries, whatever the number of entries, sites and VLANs.
     */
    public function index(Deviations $deviations): Response
    {
        $entries = CatalogVlan::query()
            ->with('vlans')
            ->orderBy('vid')
            ->get();

        return Inertia::render('catalog/Index', [
            'entries' => $entries->map(fn (CatalogVlan $entry): array => NetworkPresenter::catalogEntry($entry, $deviations)),
            'sites' => $this->sites(),
        ]);
    }

    public function store(CatalogVlanRequest $request, CatalogVlans $catalog): RedirectResponse
    {
        $entry = $this->leadingToTheEntryInTheWay(
            fn (): CatalogVlan => $catalog->create($request->entryData(), Actor::fromRequest($request)),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Catalogue entry created.')]);

        // To the page of the entry, where the VLANs that already bear its
        // number can be attached to it.
        return to_route('catalog-vlans.show', $entry);
    }

    /**
     * An entry: the VLANs attached to it, site by site, and those that
     * could be.
     */
    public function show(CatalogVlan $catalogVlan, Deviations $deviations): Response
    {
        $catalogVlan->load('vlans');

        // The VLANs that bear the number of the entry and follow no entry
        // yet, on every site.
        $candidates = Vlan::query()
            ->with('site')
            ->whereNull('catalog_vlan_id')
            ->where('vid', $catalogVlan->vid)
            ->get()
            ->sortBy(fn (Vlan $vlan): string => $vlan->site->name, SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return Inertia::render('catalog/Show', [
            'entry' => NetworkPresenter::catalogEntry($catalogVlan, $deviations),
            'sites' => $this->sites(),
            'candidates' => $candidates->map(fn (Vlan $vlan): array => [
                'id' => $vlan->id,
                'vid' => $vlan->vid,
                'name' => $vlan->name,
                'status' => $vlan->status->value,
                'status_label' => __($vlan->status->label()),
                'site' => ['id' => $vlan->site->id, 'name' => $vlan->site->name],
            ]),
        ]);
    }

    public function update(CatalogVlanRequest $request, CatalogVlan $catalogVlan, CatalogVlans $catalog): RedirectResponse
    {
        $this->leadingToTheEntryInTheWay(function () use ($catalog, $catalogVlan, $request): void {
            $catalog->update($catalogVlan, $request->entryData(), Actor::fromRequest($request));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Catalogue entry updated.')]);

        return to_route('catalog-vlans.show', $catalogVlan);
    }

    public function destroy(Request $request, CatalogVlan $catalogVlan, CatalogVlans $catalog): RedirectResponse
    {
        $catalog->delete($catalogVlan, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Catalogue entry deleted.')]);

        return to_route('catalog-vlans.index');
    }

    /**
     * Attach the chosen VLANs to the entry, all of them or none.
     *
     * The page of a VLAN attaches that VLAN alone the same way, and gets it
     * back: the rule is the same wherever the request comes from.
     */
    public function attach(CatalogVlanAttachRequest $request, CatalogVlan $catalogVlan, CatalogVlans $catalog): RedirectResponse
    {
        $attached = $catalog->attach($catalogVlan, $request->vlanIds(), Actor::fromRequest($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice(':count VLAN attached.|:count VLANs attached.', count($attached)),
        ]);

        if ($request->boolean('from_vlan') && count($attached) === 1) {
            return to_route('vlans.show', $attached[0]);
        }

        return to_route('catalog-vlans.show', $catalogVlan);
    }

    /**
     * When the number or the name of an entry is already the one of another
     * entry, the refusal comes back with that entry: the form shows, under
     * its message, a link to it.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $write
     * @return TReturn
     */
    private function leadingToTheEntryInTheWay(Closure $write): mixed
    {
        try {
            return $write();
        } catch (CatalogVlanTaken $taken) {
            Inertia::flash('catalogConflict', [
                'vid' => $taken->byVid === null ? null : NetworkPresenter::catalogOption($taken->byVid),
                'name' => $taken->byName === null ? null : NetworkPresenter::catalogOption($taken->byName),
            ]);

            throw $taken;
        }
    }

    /**
     * @return list<array{id: int, name: string, code: string|null}>
     */
    private function sites(): array
    {
        return array_values(
            Site::query()->orderBy('name')->get(['id', 'name', 'code'])
                ->map(fn (Site $site): array => ['id' => $site->id, 'name' => $site->name, 'code' => $site->code])
                ->all(),
        );
    }
}
