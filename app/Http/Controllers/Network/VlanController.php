<?php

// torsade/app/Http/Controllers/Network/VlanController.php

namespace App\Http\Controllers\Network;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Presenters\NetworkPresenter;
use App\Http\Requests\Network\VlanCreateRequest;
use App\Http\Requests\Network\VlanIndexRequest;
use App\Http\Requests\Network\VlanRequest;
use App\Models\CatalogVlan;
use App\Models\Site;
use App\Models\Vlan;
use App\Services\Network\CatalogVlans;
use App\Services\Network\Deviations;
use App\Services\Network\VlanCarriers;
use App\Services\Network\Vlans;
use App\Support\Actor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * VLANs. Each route of this controller is behind the VLAN policy (see
 * routes/network.php): reading for every active account, writing for editors
 * and administrators.
 */
class VlanController extends Controller
{
    public function index(VlanIndexRequest $request, Deviations $deviations): Response
    {
        /** @var array{site?: int|string|null, status?: string|null, catalog?: int|string|null} $filters */
        $filters = $request->validated();

        $catalog = $filters['catalog'] ?? null;

        $vlans = Vlan::query()
            ->with(['site', 'catalogVlan', 'subnet' => fn ($query) => $query->withCount('ipAddresses')])
            ->when($filters['site'] ?? null, fn (Builder $query, int|string $site) => $query->where('site_id', $site))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            // The VLANs that follow one entry, or those that follow none.
            ->when($catalog === VlanIndexRequest::OUTSIDE_CATALOG, fn (Builder $query) => $query->whereNull('catalog_vlan_id'))
            ->when(
                $catalog !== null && $catalog !== VlanIndexRequest::OUTSIDE_CATALOG,
                fn (Builder $query) => $query->where('catalog_vlan_id', $catalog),
            )
            ->orderBy('vid')
            ->orderBy('site_id')
            ->get()
            ->map(fn (Vlan $vlan): array => NetworkPresenter::vlan($vlan, $deviations));

        return Inertia::render('vlans/Index', [
            'vlans' => $vlans,
            'filters' => [
                'site' => isset($filters['site']) ? (string) $filters['site'] : '',
                'status' => $filters['status'] ?? '',
                'catalog' => $catalog === null ? '' : (string) $catalog,
            ],
            'sites' => $this->sites(),
            'statuses' => Status::options(),
            'catalogEntries' => $this->catalogEntries(),
        ]);
    }

    public function create(VlanCreateRequest $request, Deviations $deviations): Response
    {
        return Inertia::render('vlans/Form', [
            'vlan' => null,
            // What the addressing rule works out: the form proposes the
            // subnet the rule expects for the site and the number typed.
            'rule' => $deviations->scheme()?->toArray(),
            'sites' => $this->sites(),
            'statuses' => Status::options(),
            'prefixes' => NetworkPresenter::prefixOptions(),
            'catalogEntries' => $this->catalogEntries(),
            // Coming from a filtered list, the site is already chosen.
            'site' => $request->validated('site') === null ? null : (int) $request->validated('site'),
            // Coming from the catalogue, so is the entry.
            'catalogEntry' => $request->validated('catalog') === null ? null : (int) $request->validated('catalog'),
        ]);
    }

    public function store(VlanRequest $request, Vlans $vlans): RedirectResponse
    {
        $vlan = $vlans->create($request->vlanData(), $request->subnetData(), Actor::fromRequest($request), $request->applyBreakdown());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('VLAN created.')]);

        return to_route('vlans.show', $vlan);
    }

    public function show(Request $request, Vlan $vlan, Deviations $deviations, CatalogVlans $catalog, VlanCarriers $carriers): Response
    {
        $vlan->load(['site', 'catalogVlan', 'subnet' => fn ($query) => $query->withCount('ipAddresses')]);

        // A VLAN outside the catalogue whose number is in it: the entry it
        // can be attached to from here, for those who may write the
        // catalogue. The route checks that right again.
        $entry = $catalog->entryFor($vlan);

        // Whether the subnet of the VLAN is the one the addressing rule
        // expects for its site and its number.
        $vlan->subnet?->setRelation('vlan', $vlan)->setRelation('site', $vlan->site);

        return Inertia::render('vlans/Show', [
            'vlan' => NetworkPresenter::vlan($vlan, $deviations),
            'subnetDeviations' => $vlan->subnet === null ? [] : $deviations->againstRule($vlan->subnet, $deviations->scheme()),
            // The ports and the aggregates that carry the VLAN, device by
            // device.
            'carriers' => NetworkPresenter::vlanCarriers($carriers->of($vlan)),
            'attachableEntry' => $entry !== null && $request->user()?->can('update', $entry) === true
                ? NetworkPresenter::catalogOption($entry)
                : null,
        ]);
    }

    public function edit(Vlan $vlan): Response
    {
        return Inertia::render('vlans/Form', [
            'vlan' => [
                'id' => $vlan->id,
                'site_id' => $vlan->site_id,
                'catalog_vlan_id' => $vlan->catalog_vlan_id,
                'vid' => $vlan->vid,
                'name' => $vlan->name,
                'description' => $vlan->description,
                'status' => $vlan->status->value,
            ],
            // The subnet of an existing VLAN is managed on its own page:
            // nothing is proposed here.
            'rule' => null,
            'sites' => $this->sites(),
            'statuses' => Status::options(),
            'prefixes' => [],
            'catalogEntries' => $this->catalogEntries(),
            'site' => null,
            'catalogEntry' => null,
        ]);
    }

    public function update(VlanRequest $request, Vlan $vlan, Vlans $vlans): RedirectResponse
    {
        $vlans->update($vlan, $request->vlanData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('VLAN updated.')]);

        return to_route('vlans.show', $vlan);
    }

    public function destroy(Request $request, Vlan $vlan, Vlans $vlans): RedirectResponse
    {
        $vlans->delete($vlan, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('VLAN deleted.')]);

        return to_route('vlans.index');
    }

    /**
     * The sites, each with its number: the addressing rule gives a site
     * its block, and a VLAN its subnet, from it.
     *
     * @return list<array{id: int, name: string, number: int|null}>
     */
    private function sites(): array
    {
        return array_values(
            Site::query()->orderBy('name')->get(['id', 'name', 'number'])
                ->map(fn (Site $site): array => ['id' => $site->id, 'name' => $site->name, 'number' => $site->number])
                ->all(),
        );
    }

    /**
     * The entries of the catalogue, by number.
     *
     * @return list<array{id: int, vid: int, name: string}>
     */
    private function catalogEntries(): array
    {
        return array_values(
            CatalogVlan::query()->orderBy('vid')->get()
                ->map(fn (CatalogVlan $entry): array => NetworkPresenter::catalogOption($entry))
                ->all(),
        );
    }
}
