<?php

// torsade/app/Http/Controllers/Network/AddressingRuleController.php

namespace App\Http\Controllers\Network;

use App\Enums\DeviationKind;
use App\Enums\GatewayPosition;
use App\Enums\RangeKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Network\AddressingRuleRequest;
use App\Models\AddressBlock;
use App\Models\Site;
use App\Services\Network\AddressingRules;
use App\Services\Network\Deviations;
use App\Support\Actor;
use App\Support\AddressingScheme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The addressing rule of the organisation. Each route of this controller is
 * behind the policy of the rule (see routes/network.php): reading for every
 * active account, writing for editors and administrators.
 */
class AddressingRuleController extends Controller
{
    /**
     * The rule, the form that changes it, and how it applies to each site.
     *
     * The number of queries does not depend on the number of sites.
     */
    public function show(AddressingRules $rules, Deviations $deviations): Response
    {
        $rule = $rules->current();
        $scheme = $rule === null ? null : AddressingScheme::fromRule($rule);

        return Inertia::render('addressing-rule/Show', [
            'rule' => $rule === null || $scheme === null ? null : [
                'aggregate_block_id' => $rule->aggregate_block_id,
                'block' => $rule->aggregateBlock->cidr(),
                ...$scheme->toArray(),
                'gateway_label' => __($rule->gateway_position->label()),
            ],
            // The blocks a rule can start from: those of the organisation.
            'blocks' => array_values(
                AddressBlock::query()
                    ->whereNull('site_id')
                    ->where('ip_version', 4)
                    ->orderBy('network')
                    ->orderBy('prefix_length')
                    ->get()
                    ->map(fn (AddressBlock $block): array => [
                        'id' => $block->id,
                        'cidr' => $block->cidr(),
                        'network' => $block->network,
                        'prefix_length' => $block->prefix_length,
                        'name' => $block->name,
                    ])
                    ->all(),
            ),
            'gatewayPositions' => GatewayPosition::options(),
            'rangeKinds' => RangeKind::options(),
            'maxRanges' => AddressingRules::MAX_RANGES,
            // Without a level of sites, the rule expects no block of a site:
            // there is nothing to apply site by site.
            'application' => $scheme === null || ! $scheme->hasSiteLevel() ? [] : $this->application($scheme, $deviations),
        ]);
    }

    /**
     * Create the rule, or change the one there is.
     */
    public function update(AddressingRuleRequest $request, AddressingRules $rules): RedirectResponse
    {
        $rules->save($request->ruleData(), Actor::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Addressing rule saved.')]);

        return to_route('addressing-rule.show');
    }

    public function destroy(Request $request, AddressingRules $rules): RedirectResponse
    {
        $rule = $rules->current();

        if ($rule !== null) {
            $rules->delete($rule, Actor::fromRequest($request));

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Addressing rule deleted.')]);
        }

        return to_route('addressing-rule.show');
    }

    /**
     * How the rule applies to each site: the block it expects, and whether
     * the site has declared it. The numbered sites come first, by number.
     *
     * @return list<array{id: int, name: string, number: int|null, expected_block: string|null, state: string, block_id: int|null, deviation: array{kind: string, reason: string}|null}>
     */
    private function application(AddressingScheme $scheme, Deviations $deviations): array
    {
        $sites = Site::query()->with('addressBlocks')->orderBy('name')->get()
            ->sortBy(fn (Site $site): array => [$site->number === null ? 1 : 0, $site->number ?? 0])
            ->values();

        return array_values($sites->map(function (Site $site) use ($scheme, $deviations): array {
            $expected = $scheme->siteBlock($site->number);
            $deviation = $deviations->ofSite($site, $scheme)[0] ?? null;

            $declared = $expected === null ? null : $site->addressBlocks->first(
                fn (AddressBlock $block): bool => $block->network === $expected['network'] && $block->prefix_length === $expected['prefix_length'],
            );

            return [
                'id' => $site->id,
                'name' => $site->name,
                'number' => $site->number,
                'expected_block' => $expected === null ? null : AddressingScheme::cidr($expected),
                'state' => match (true) {
                    $site->number === null => 'unnumbered',
                    $deviation !== null && $deviation['kind'] === DeviationKind::SiteNumberOutsideRule->value => 'outside',
                    $declared !== null => 'declared',
                    default => 'missing',
                },
                'block_id' => $declared?->id,
                'deviation' => $deviation,
            ];
        })->all());
    }
}
