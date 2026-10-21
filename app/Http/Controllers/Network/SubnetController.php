<?php

// torsade/app/Http/Controllers/Network/SubnetController.php

namespace App\Http\Controllers\Network;

use App\Enums\DeviceType;
use App\Enums\IpAssignment;
use App\Enums\RangeKind;
use App\Enums\Status;
use App\Enums\SubnetKind;
use App\Http\Controllers\Controller;
use App\Http\Presenters\NetworkPresenter;
use App\Http\Requests\Network\IpAddressBulkRequest;
use App\Http\Requests\Network\SubnetCreateRequest;
use App\Http\Requests\Network\SubnetIndexRequest;
use App\Http\Requests\Network\SubnetRequest;
use App\Http\Requests\Network\SubnetShowRequest;
use App\Models\Device;
use App\Models\IpAddress;
use App\Models\IpRange;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\Vlan;
use App\Services\Network\AddressPlan;
use App\Services\Network\Deviations;
use App\Services\Network\StandardBreakdown;
use App\Services\Network\Subnets;
use App\Support\Actor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Subnets. Each route of this controller is behind the subnet policy (see
 * routes/network.php): reading for every active account, writing for editors
 * and administrators.
 */
class SubnetController extends Controller
{
    public function index(SubnetIndexRequest $request, Deviations $deviations): Response
    {
        /** @var array{site?: int|string|null, kind?: string|null} $filters */
        $filters = $request->validated();

        $site = $filters['site'] ?? null;

        // Read once for the whole list: where each subnet lies among them,
        // and what the addressing rule expects.
        $siteBlocks = $deviations->siteBlocks();
        $scheme = $deviations->scheme();

        return Inertia::render('subnets/Index', [
            'subnets' => Subnet::query()
                ->with(['vlan.site', 'site'])
                ->withCount('ipAddresses')
                // The subnets of one site, or those that belong to none.
                ->when($site === SubnetIndexRequest::WITHOUT_SITE, fn (Builder $query) => $query->whereNull('site_id'))
                ->when(
                    $site !== null && $site !== SubnetIndexRequest::WITHOUT_SITE,
                    fn (Builder $query) => $query->where('site_id', $site),
                )
                ->when($filters['kind'] ?? null, fn (Builder $query, string $kind) => $query->where('kind', $kind))
                // Binary addresses sort in numeric order.
                ->orderBy('network')
                ->orderBy('prefix_length')
                ->get()
                ->map(fn (Subnet $subnet): array => [
                    ...NetworkPresenter::subnet($subnet),
                    'deviations' => $deviations->ofSubnet($subnet, $siteBlocks, $scheme),
                ]),
            'filters' => [
                'site' => $site === null ? '' : (string) $site,
                'kind' => $filters['kind'] ?? '',
            ],
            'sites' => $this->sites(),
            'kinds' => SubnetKind::options(),
        ]);
    }

    public function create(SubnetCreateRequest $request, Deviations $deviations): Response
    {
        $vlan = $request->validated('vlan');
        $site = $request->validated('site');

        return Inertia::render('subnets/Form', [
            'subnet' => null,
            // What the addressing rule works out: with a VLAN chosen, the
            // form proposes the subnet the rule expects for it.
            'rule' => $deviations->scheme()?->toArray(),
            'vlans' => $this->availableVlans(),
            'sites' => $this->sites(),
            'kinds' => SubnetKind::options(),
            'statuses' => Status::options(),
            'prefixes' => NetworkPresenter::prefixOptions(),
            // Coming from the page of a VLAN, the VLAN is already chosen;
            // from the page of a site, the site is.
            'vlan' => $vlan === null ? null : (int) $vlan,
            'site' => $site === null ? null : (int) $site,
        ]);
    }

    public function store(SubnetRequest $request, Subnets $subnets): RedirectResponse
    {
        $subnet = $subnets->create($request->subnetData(), Actor::fromRequest($request), $request->applyBreakdown());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subnet created.')]);

        return to_route('subnets.show', $subnet);
    }

    /**
     * The working screen of a subnet: its header, its ranges, its addresses
     * and what is left free.
     */
    public function show(SubnetShowRequest $request, Subnet $subnet, Subnets $subnets, AddressPlan $plan, Deviations $deviations, StandardBreakdown $breakdown): Response
    {
        $subnet->load(['vlan.site', 'site'])->loadCount('ipAddresses');

        $free = $subnets->freeIntervals($subnet);
        $scheme = $deviations->scheme();

        return Inertia::render('subnets/Show', [
            'subnet' => NetworkPresenter::subnet($subnet),
            'deviations' => $deviations->ofSubnet($subnet, $deviations->siteBlocks(), $scheme),
            // The ranges of the addressing rule, as they would be created
            // in this subnet: offered to those who may create ranges, for
            // a subnet that has none and whose kind accepts some.
            'breakdown' => $scheme !== null && $breakdown->isOffered($subnet, $scheme) && $request->user()?->can('create', IpRange::class) === true
                ? $breakdown->preview($subnet, $scheme)
                : null,
            // Whether a subnet of this kind is cut into ranges at all.
            'allowsRanges' => $subnet->kind->allowsRanges(),
            'allowsGateway' => $subnet->kind->allowsGateway(),
            'ipRanges' => $subnet->ipRanges()->orderBy('start_address')->get()
                ->map(fn (IpRange $range): array => NetworkPresenter::ipRange($range)),
            'rangeKinds' => RangeKind::options(),
            // Each address with the device it is an interface of, and
            // whether that device is on another site than the subnet, or
            // carries its VLAN, and the jack it is plugged into with the path
            // of that jack.
            'addresses' => $subnet->ipAddresses()
                ->with([
                    'device.site',
                    'device.carriedByPorts' => fn ($query) => $query->where('port_vlans.vlan_id', $subnet->vlan_id),
                    'device.carriedByAggregates' => fn ($query) => $query->where('port_vlans.vlan_id', $subnet->vlan_id),
                    ...NetworkPresenter::ADDRESS_JACK,
                ])
                ->orderBy('address')
                ->get()
                ->map(fn (IpAddress $address): array => NetworkPresenter::ipAddress(
                    $address->setRelation('subnet', $subnet),
                    $subnet->gateway,
                    $deviations,
                )),
            // What the window of an address offers as its device: every
            // device, the page showing those of the site of the subnet
            // first.
            'devices' => Device::query()->with('site')->orderBy('name')->orderBy('id')->get()
                ->map(fn (Device $device): array => [
                    'id' => $device->id,
                    'name' => $device->name,
                    'site_id' => $device->site_id,
                    'site' => $device->site->name,
                ]),
            'freeIntervals' => $free,
            'freeCount' => array_sum(array_column($free, 'count')),
            // The address to document next, and where it was taken from.
            'nextFreeAddress' => $subnets->nextFreeAddress($free),
            'addressPlan' => $plan->of($subnet),
            'deviceTypes' => DeviceType::options(),
            'assignments' => IpAssignment::options(),
            // The statuses offered for an IP address, agreed with it.
            'statuses' => Status::options(feminine: true),
            'maxBulkLines' => IpAddressBulkRequest::MAX_LINES,
            // The jacks of the site of the subnet whose label holds what is
            // typed in the field "Jack" of the window of an address.
            'jackChoices' => Inertia::optional(fn (): array => JackController::plugChoices($subnet->site_id, $request->jackSearch())),
        ]);
    }

    public function edit(Subnet $subnet): Response
    {
        return Inertia::render('subnets/Form', [
            'subnet' => [
                'id' => $subnet->id,
                'cidr' => $subnet->cidr(),
                'network' => $subnet->network,
                'prefix_length' => $subnet->prefix_length,
                'kind' => $subnet->kind->value,
                'vlan_id' => $subnet->vlan_id,
                'site_id' => $subnet->site_id,
                'gateway' => $subnet->gateway,
                'name' => $subnet->name,
                'description' => $subnet->description,
                'status' => $subnet->status->value,
            ],
            // An existing subnet is what it is: nothing is proposed for it.
            'rule' => null,
            'vlans' => $this->availableVlans($subnet),
            'sites' => $this->sites(),
            'kinds' => SubnetKind::options(),
            'statuses' => Status::options(),
            'prefixes' => NetworkPresenter::prefixOptions(),
            'vlan' => null,
            'site' => null,
        ]);
    }

    public function update(SubnetRequest $request, Subnet $subnet, Subnets $subnets): RedirectResponse
    {
        $subnets->update($subnet, $request->subnetData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subnet updated.')]);

        return to_route('subnets.show', $subnet);
    }

    public function destroy(Request $request, Subnet $subnet, Subnets $subnets): RedirectResponse
    {
        $subnets->delete($subnet, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subnet deleted.')]);

        return to_route('subnets.index');
    }

    /**
     * The VLANs a subnet can be attached to: those without a subnet, plus
     * the one the given subnet already belongs to. Each one comes with its
     * site, which becomes the site of the subnet, and with what the
     * addressing rule numbers a subnet by: its VID and the number of its
     * site.
     *
     * @return list<array{id: int, label: string, vid: int, site: string, site_id: int, site_number: int|null}>
     */
    private function availableVlans(?Subnet $subnet = null): array
    {
        return array_values(
            Vlan::query()
                ->with('site')
                ->where(fn ($query) => $query
                    ->whereDoesntHave('subnet')
                    ->when($subnet?->vlan_id, fn ($query, int $id) => $query->orWhere('id', $id)))
                ->orderBy('vid')
                ->get()
                // By site, then by number within each site.
                ->sortBy(fn (Vlan $vlan): string => $vlan->site->name, SORT_NATURAL | SORT_FLAG_CASE)
                ->map(fn (Vlan $vlan): array => [
                    'id' => $vlan->id,
                    'label' => $vlan->label(),
                    'vid' => $vlan->vid,
                    'site' => $vlan->site->name,
                    'site_id' => $vlan->site_id,
                    'site_number' => $vlan->site->number,
                ])
                ->all(),
        );
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function sites(): array
    {
        return array_values(
            Site::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Site $site): array => ['id' => $site->id, 'name' => $site->name])
                ->all(),
        );
    }
}
