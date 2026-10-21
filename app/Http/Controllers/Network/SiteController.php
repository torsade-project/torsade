<?php

// torsade/app/Http/Controllers/Network/SiteController.php

namespace App\Http\Controllers\Network;

use App\Casts\AsIpAddress;
use App\Enums\PanelConnector;
use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Presenters\NetworkPresenter;
use App\Http\Requests\Network\SiteExpectedBlockRequest;
use App\Http\Requests\Network\SiteIndexRequest;
use App\Http\Requests\Network\SiteRequest;
use App\Models\AddressBlock;
use App\Models\Building;
use App\Models\Device;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\Vlan;
use App\Services\Network\AddressingRules;
use App\Services\Network\Deviations;
use App\Services\Network\Sites;
use App\Support\Actor;
use App\Support\Ipv4;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sites. Each route of this controller is behind the site policy (see
 * routes/network.php): reading for every active account, writing for editors
 * and administrators.
 */
class SiteController extends Controller
{
    public function index(SiteIndexRequest $request, Deviations $deviations): Response
    {
        $status = $request->validated('status');

        return Inertia::render('sites/Index', [
            'sites' => Site::query()
                ->when($status, fn (Builder $query, string $status) => $query->where('status', $status))
                ->withCount('vlans')
                // The window of a site says whether the block the rule
                // expects for its number is already declared.
                ->with('addressBlocks')
                ->orderBy('name')
                ->get()
                ->map(fn (Site $site): array => NetworkPresenter::site($site)),
            // What the addressing rule works out, for the window to show
            // the block expected for the number being typed.
            'rule' => $deviations->scheme()?->toArray(),
            'filters' => ['status' => $status ?? ''],
            'statuses' => Status::options(),
        ]);
    }

    /**
     * A site: its blocks, its VLANs with the entry of the catalogue each
     * one follows, its subnets that no VLAN carries, its places, its
     * devices, and what differs from the standard of the organisation.
     *
     * The number of queries does not depend on what the site holds.
     */
    public function show(Site $site, Deviations $deviations): Response
    {
        $site->loadCount('vlans');

        $blocks = $site->addressBlocks()->orderBy('network')->get();
        $blocks->each(fn (AddressBlock $block) => $block->setRelation('site', $site));
        $site->setRelation('addressBlocks', $blocks);

        $vlans = $site->vlans()
            ->with(['catalogVlan', 'subnet' => fn ($query) => $query->withCount('ipAddresses')])
            ->orderBy('vid')
            ->get();
        $vlans->each(fn (Vlan $vlan) => $vlan->setRelation('site', $site));

        // The subnets of the site that no VLAN carries: a carrier link, a
        // tunnel, a loopback.
        $standalone = $site->subnets()
            ->whereNull('vlan_id')
            ->withCount('ipAddresses')
            ->orderBy('network')
            ->get();
        $standalone->each(fn (Subnet $subnet) => $subnet->setRelation('site', $site)->setRelation('vlan', null));

        // What lies in the blocks of the site, whatever its site: how much
        // of each block is taken, and the subnets of other sites in them.
        $inBlocks = $this->subnetsIn($blocks);

        $siteBlocks = $deviations->siteBlocks();

        // What the addressing rule expects, if there is one: of the site
        // itself, and of each subnet a VLAN carries.
        $scheme = $deviations->scheme();
        $ofSite = $deviations->ofSite($site, $scheme);

        $items = [];

        foreach ($vlans as $vlan) {
            $items[] = $this->deviationItem('vlan', $vlan->id, __('VLAN :label', ['label' => $vlan->label()]), $deviations->ofVlan($vlan));

            if ($vlan->subnet !== null) {
                $vlan->subnet->setRelation('vlan', $vlan)->setRelation('site', $site);

                $items[] = $this->deviationItem('subnet', $vlan->subnet->id, $vlan->subnet->cidr(), $deviations->ofSubnet($vlan->subnet, $siteBlocks, $scheme));
            }
        }

        foreach ($standalone as $subnet) {
            $items[] = $this->deviationItem('subnet', $subnet->id, $subnet->cidr(), $deviations->ofSubnet($subnet, $siteBlocks, $scheme));
        }

        foreach ($deviations->ofForeignSubnetsIn($blocks, $inBlocks) as $foreign) {
            $items[] = $this->deviationItem('subnet', $foreign['subnet']->id, $foreign['subnet']->cidr(), $foreign['deviations']);
        }

        return Inertia::render('sites/Show', [
            'site' => NetworkPresenter::site($site),
            'blocks' => $blocks->map(fn (AddressBlock $block): array => [
                ...NetworkPresenter::addressBlock($block),
                'depth' => 0,
                'occupied' => (int) $inBlocks
                    ->filter(fn (Subnet $subnet): bool => Ipv4::prefixContains($block->network, $block->prefix_length, $subnet->network, $subnet->prefix_length))
                    ->sum(fn (Subnet $subnet): int => Ipv4::size($subnet->prefix_length)),
            ]),
            'vlans' => $vlans->map(fn (Vlan $vlan): array => NetworkPresenter::vlan($vlan, $deviations)),
            'subnets' => $standalone->map(fn (Subnet $subnet): array => NetworkPresenter::subnet($subnet)),
            'deviations' => array_values(array_filter($items, fn (array $item): bool => $item['deviations'] !== [])),
            // How the site itself differs from the addressing rule.
            'siteDeviations' => $ofSite,
            'rule' => $scheme?->toArray(),
            'statuses' => Status::options(),
            // The buildings of the site, each with its floors and their
            // rooms, and the patch panels kept in each room.
            'places' => $site->buildings()
                ->with([
                    'floors' => fn ($query) => $query->withCount('jacks'),
                    'floors.rooms' => fn ($query) => $query->withCount('devices'),
                    'floors.rooms.patchPanels' => fn ($query) => $query->withCount(['ports as occupied_count' => fn ($query) => $query->where(fn ($query) => $query->has('jack')->orHas('cord'))]),
                ])
                ->get()
                ->sortBy(fn (Building $building): string => $building->name, SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->map(fn (Building $building): array => NetworkPresenter::building($building)),
            'devices' => DeviceController::listed(Device::query()->where('site_id', $site->id)),
            'panelConnectors' => PanelConnector::options(),
            // The statuses of a jack, agreed with it.
            'jackStatuses' => Status::options(feminine: true),
            // The panels of the site with their free ports: what a jack can
            // be cabled to, looked for when a window of jacks asks.
            'panelChoices' => Inertia::optional(fn (): array => JackController::panelChoices($site->id)),
        ]);
    }

    public function store(SiteRequest $request, Sites $sites): RedirectResponse
    {
        $sites->create($request->siteData(), Actor::fromRequest($request), $request->boolean('create_block'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Site created.')]);

        return to_route('sites.index');
    }

    public function update(SiteRequest $request, Site $site, Sites $sites): RedirectResponse
    {
        $sites->update($site, $request->siteData(), Actor::fromRequest($request), $request->boolean('create_block'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Site updated.')]);

        // Back to where the site was edited from: its page, or the list.
        return $request->boolean('from_page') ? to_route('sites.show', $site) : to_route('sites.index');
    }

    public function destroy(Request $request, Site $site, Sites $sites): RedirectResponse
    {
        $sites->delete($site, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Site deleted.')]);

        return to_route('sites.index');
    }

    /**
     * Declare the block the addressing rule expects for a site: the action
     * offered where that block is reported missing.
     */
    public function storeExpectedBlock(SiteExpectedBlockRequest $request, Site $site, AddressingRules $rules): RedirectResponse
    {
        $block = $rules->createExpectedBlock($site, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Block :block created.', ['block' => $block->cidr()])]);

        // Back to where it was asked from: the page of the rule, or the
        // page of the site.
        return $request->boolean('from_rule') ? to_route('addressing-rule.show') : to_route('sites.show', $site);
    }

    /**
     * The subnets that lie in the given blocks, each with its site, in one
     * query whatever the number of blocks.
     *
     * @param  Collection<int, AddressBlock>  $blocks
     * @return Collection<int, Subnet>
     */
    private function subnetsIn(Collection $blocks): Collection
    {
        if ($blocks->isEmpty()) {
            return new Collection;
        }

        return Subnet::query()
            ->where('ip_version', 4)
            ->where(function ($query) use ($blocks): void {
                foreach ($blocks as $block) {
                    // A subnet that starts inside a block and is not larger
                    // than it lies inside it.
                    $query->orWhere(fn ($inside) => $inside
                        ->whereBetween('network', [
                            AsIpAddress::toBinary($block->network),
                            AsIpAddress::toBinary(Ipv4::broadcast($block->network, $block->prefix_length)),
                        ])
                        ->where('prefix_length', '>=', $block->prefix_length));
                }
            })
            ->with('site')
            ->orderBy('network')
            ->get();
    }

    /**
     * One line of the deviations of the page: an object and what differs
     * about it.
     *
     * @param  list<array{kind: string, reason: string}>  $deviations
     * @return array{type: string, id: int, label: string, deviations: list<array{kind: string, reason: string}>}
     */
    private function deviationItem(string $type, int $id, string $label, array $deviations): array
    {
        return ['type' => $type, 'id' => $id, 'label' => $label, 'deviations' => $deviations];
    }
}
