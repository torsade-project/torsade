<?php

// torsade/app/Http/Controllers/Network/AddressBlockController.php

namespace App\Http\Controllers\Network;

use App\Enums\Status;
use App\Enums\SubnetKind;
use App\Http\Controllers\Controller;
use App\Http\Presenters\NetworkPresenter;
use App\Http\Requests\Network\AddressBlockCreateRequest;
use App\Http\Requests\Network\AddressBlockRequest;
use App\Models\AddressBlock;
use App\Models\Site;
use App\Models\Subnet;
use App\Services\Network\AddressBlocks;
use App\Services\Network\Deviations;
use App\Support\Actor;
use App\Support\Ipv4;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Address blocks. Each route of this controller is behind the policy of
 * blocks (see routes/network.php): reading for every active account,
 * writing for editors and administrators.
 */
class AddressBlockController extends Controller
{
    /**
     * The blocks as a tree: those of the organisation, each followed by
     * what it holds, then the blocks of sites that no block of the
     * organisation holds.
     */
    public function index(AddressBlocks $blocks): Response
    {
        $all = AddressBlock::query()
            ->with('site')
            // Binary addresses sort in numeric order; of two blocks that
            // start at the same address, the larger comes first.
            ->orderBy('network')
            ->orderBy('prefix_length')
            ->get();

        $subnets = Subnet::query()->where('ip_version', 4)->get(['id', 'network', 'prefix_length']);

        $tree = $blocks->tree($all);

        return Inertia::render('blocks/Index', [
            'blocks' => array_map(
                fn (array $node): array => [
                    ...NetworkPresenter::addressBlock($node['block']),
                    'depth' => $node['depth'],
                    'occupied' => $this->occupied($node['block'], $subnets),
                ],
                $tree['nested'],
            ),
            'outside' => array_map(
                fn (AddressBlock $block): array => [
                    ...NetworkPresenter::addressBlock($block),
                    'depth' => 0,
                    'occupied' => $this->occupied($block, $subnets),
                ],
                $tree['outside'],
            ),
        ]);
    }

    public function create(AddressBlockCreateRequest $request): Response
    {
        $site = $request->validated('site');

        return Inertia::render('blocks/Form', [
            'block' => null,
            'sites' => $this->sites(),
            'statuses' => Status::options(),
            'prefixes' => NetworkPresenter::prefixOptions(),
            // Coming from the page of a site, the site is already chosen.
            'site' => $site === null ? null : (int) $site,
            ...$this->sitePlans(),
        ]);
    }

    public function store(AddressBlockRequest $request, AddressBlocks $blocks): RedirectResponse
    {
        $block = $blocks->create($request->blockData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Address block created.')]);

        return to_route('address-blocks.show', $block);
    }

    /**
     * A block: the subnets it holds, how much of it they take, what is
     * left, and the subnets that are not where the blocks say.
     *
     * The number of queries does not depend on the number of subnets.
     */
    public function show(AddressBlock $addressBlock, AddressBlocks $blocks, Deviations $deviations): Response
    {
        $addressBlock->load('site');

        $subnets = $blocks->subnetsIn($addressBlock)
            ->load(['vlan.site', 'site'])
            ->loadCount('ipAddresses');

        $siteBlocks = $deviations->siteBlocks();
        $scheme = $deviations->scheme();

        return Inertia::render('blocks/Show', [
            'block' => NetworkPresenter::addressBlock($addressBlock),
            'space' => $blocks->space($addressBlock, $subnets),
            'subnets' => $subnets->map(fn (Subnet $subnet): array => [
                ...NetworkPresenter::subnet($subnet),
                'deviations' => $deviations->ofSubnet($subnet, $siteBlocks, $scheme),
            ]),
        ]);
    }

    public function edit(AddressBlock $addressBlock): Response
    {
        return Inertia::render('blocks/Form', [
            'block' => [
                'id' => $addressBlock->id,
                'cidr' => $addressBlock->cidr(),
                'network' => $addressBlock->network,
                'prefix_length' => $addressBlock->prefix_length,
                'site_id' => $addressBlock->site_id,
                'name' => $addressBlock->name,
                'description' => $addressBlock->description,
                'status' => $addressBlock->status->value,
            ],
            'sites' => $this->sites(),
            'statuses' => Status::options(),
            'prefixes' => NetworkPresenter::prefixOptions(),
            'site' => null,
            ...$this->sitePlans(),
        ]);
    }

    public function update(AddressBlockRequest $request, AddressBlock $addressBlock, AddressBlocks $blocks): RedirectResponse
    {
        $blocks->update($addressBlock, $request->blockData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Address block updated.')]);

        return to_route('address-blocks.show', $addressBlock);
    }

    public function destroy(Request $request, AddressBlock $addressBlock, AddressBlocks $blocks): RedirectResponse
    {
        $blocks->delete($addressBlock, Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Address block deleted.')]);

        return to_route('address-blocks.index');
    }

    /**
     * The addresses of a block that belong to a subnet. Subnets never
     * overlap: their sizes add up.
     *
     * @param  Collection<int, Subnet>  $subnets  Every subnet.
     */
    private function occupied(AddressBlock $block, Collection $subnets): int
    {
        return (int) $subnets
            ->filter(fn (Subnet $subnet): bool => Ipv4::prefixContains($block->network, $block->prefix_length, $subnet->network, $subnet->prefix_length))
            ->sum(fn (Subnet $subnet): int => Ipv4::size($subnet->prefix_length));
    }

    /**
     * What the form needs to say, while a block is being typed, how many
     * subnets of the chosen site it would hold: the segments of every site,
     * the only subnets expected in the blocks of their site, and the blocks
     * the sites already have.
     *
     * The form counts by itself, at each keystroke and without a request.
     * It is a help to the person typing, not a rule: nothing is refused on
     * that ground, here or when the block is saved.
     *
     * @return array{
     *     segments: list<array{site_id: int, network: string, prefix_length: int}>,
     *     siteBlocks: list<array{id: int, site_id: int, network: string, prefix_length: int}>
     * }
     */
    private function sitePlans(): array
    {
        return [
            'segments' => array_values(
                Subnet::query()
                    ->where('kind', SubnetKind::Segment)
                    ->whereNotNull('site_id')
                    ->where('ip_version', 4)
                    ->orderBy('network')
                    ->get(['id', 'site_id', 'network', 'prefix_length'])
                    ->map(fn (Subnet $subnet): array => [
                        'site_id' => (int) $subnet->site_id,
                        'network' => $subnet->network,
                        'prefix_length' => $subnet->prefix_length,
                    ])
                    ->all(),
            ),
            'siteBlocks' => array_values(
                AddressBlock::query()
                    ->whereNotNull('site_id')
                    ->where('ip_version', 4)
                    ->orderBy('network')
                    ->get(['id', 'site_id', 'network', 'prefix_length'])
                    ->map(fn (AddressBlock $block): array => [
                        'id' => $block->id,
                        'site_id' => (int) $block->site_id,
                        'network' => $block->network,
                        'prefix_length' => $block->prefix_length,
                    ])
                    ->all(),
            ),
        ];
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
