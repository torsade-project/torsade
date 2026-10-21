<?php

// torsade/tests/Feature/Network/SiteStandardTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Models\AddressingRule;
use App\Models\CatalogVlan;
use App\Models\IpRange;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\Vlan;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The standard of the organisation applied to a site in one action: the
 * entries of the catalogue the site does not follow yet become its VLANs,
 * with their subnets and their ranges when asked. Everything is created, or
 * nothing is, and each refusal is told on its line.
 */
class SiteStandardTest extends NetworkTestCase
{
    private Site $site;

    private CatalogVlan $offices;

    private CatalogVlan $servers;

    private CatalogVlan $guests;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create(['name' => 'Dépôt', 'number' => 30]);
        $this->servers = CatalogVlan::factory()->create(['vid' => 20, 'name' => 'Serveurs']);
        $this->offices = CatalogVlan::factory()->create(['vid' => 10, 'name' => 'Bureautique']);
        $this->guests = CatalogVlan::factory()->create(['vid' => 40, 'name' => 'Invités']);
    }

    private function rule(): AddressingRule
    {
        return AddressingRule::factory()->withBreakdown()->create();
    }

    /**
     * One line of the page: an entry, as it proposes itself.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function line(CatalogVlan $entry, ?string $cidr = null, array $overrides = []): array
    {
        return [
            'catalog_vlan_id' => $entry->id,
            'vid' => $entry->vid,
            'name' => $entry->name,
            'with_subnet' => $cidr !== null,
            'network' => $cidr,
            'gateway' => null,
            'apply_breakdown' => false,
            ...$overrides,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function apply(array $lines, string $status = 'planned'): TestResponse
    {
        return $this->actingAs($this->editor())->post(route('sites.standard.store', $this->site), ['status' => $status, 'lines' => $lines]);
    }

    // --- The page -----------------------------------------------------------------

    public function test_the_page_proposes_the_entries_the_site_does_not_follow_yet(): void
    {
        $this->rule();

        // The site follows the entry 20 already, with another number.
        Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 120, 'name' => 'Serveurs', 'catalog_vlan_id' => $this->servers->id]);
        // Another site follows the entry 10: that says nothing of this one.
        Vlan::factory()->create(['site_id' => Site::factory()->create()->id, 'vid' => 10, 'name' => 'Bureautique', 'catalog_vlan_id' => $this->offices->id]);

        $this->actingAs($this->editor())
            ->get(route('sites.standard.create', $this->site))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sites/Standard')
                ->where('site', ['id' => $this->site->id, 'name' => 'Dépôt', 'number' => 30])
                // By number, without the entry the site follows.
                ->where('entries', [
                    ['id' => $this->offices->id, 'vid' => 10, 'name' => 'Bureautique'],
                    ['id' => $this->guests->id, 'vid' => 40, 'name' => 'Invités'],
                ])
                ->where('rule.vlan_prefix_length', 24)
                ->has('rule.ranges', 3)
                ->has('statuses', 3)
                // Each line lists the ranges of the breakdown by their kind.
                ->where('rangeKinds', [
                    ['value' => 'dhcp', 'label' => 'DHCP'],
                    ['value' => 'static', 'label' => 'Fixe'],
                    ['value' => 'reserved', 'label' => 'Réservée'],
                ])
                // One status for the whole selection, "planned" at first.
                ->where('defaultStatus', 'planned'));
    }

    public function test_the_page_works_without_a_rule_and_says_when_nothing_is_left(): void
    {
        $this->actingAs($this->editor())
            ->get(route('sites.standard.create', $this->site))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('rule', null)->has('entries', 3));

        foreach ([$this->offices, $this->servers, $this->guests] as $entry) {
            Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => $entry->vid, 'name' => $entry->name, 'catalog_vlan_id' => $entry->id]);
        }

        $this->actingAs($this->editor())
            ->get(route('sites.standard.create', $this->site))
            ->assertInertia(fn (Assert $page) => $page->where('entries', []));
    }

    // --- Applying -----------------------------------------------------------------

    public function test_the_entries_chosen_become_vlans_of_the_site_with_their_subnets_and_ranges(): void
    {
        $editor = $this->editor();

        $this->rule();

        $this->actingAs($editor)
            ->post(route('sites.standard.store', $this->site), ['status' => 'planned', 'lines' => [
                $this->line($this->offices, '10.30.10.0/24', ['gateway' => '10.30.10.1', 'apply_breakdown' => true]),
                // A subnet typed with its length apart, and no ranges.
                $this->line($this->servers, '10.30.20.0', ['prefix_length' => 24, 'gateway' => '10.30.20.1']),
                // A VLAN alone.
                $this->line($this->guests),
            ]])
            ->assertRedirect(route('sites.show', $this->site))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '3 VLAN créés, 2 sous-réseaux créés.');

        $vlans = Vlan::query()->where('site_id', $this->site->id)->with(['subnet', 'catalogVlan'])->orderBy('vid')->get();

        // Each VLAN is attached to its entry, with the one status chosen.
        $this->assertSame(
            [[10, 'Bureautique', 10, 'planned'], [20, 'Serveurs', 20, 'planned'], [40, 'Invités', 40, 'planned']],
            $vlans->map(fn (Vlan $vlan): array => [$vlan->vid, $vlan->name, $vlan->catalogVlan?->vid, $vlan->status->value])->all(),
        );

        // The subnets are on the site, with the status of their VLAN.
        $this->assertSame(
            [['10.30.10.0/24', '10.30.10.1', 'planned', $this->site->id], ['10.30.20.0/24', '10.30.20.1', 'planned', $this->site->id], null],
            $vlans->map(fn (Vlan $vlan): ?array => $vlan->subnet === null ? null : [$vlan->subnet->cidr(), $vlan->subnet->gateway, $vlan->subnet->status->value, $vlan->subnet->site_id])->all(),
        );

        // Only the subnet whose box was ticked is cut into ranges.
        $this->assertSame(3, IpRange::query()->where('subnet_id', $vlans[0]->subnet?->id)->count());
        $this->assertDatabaseCount('ip_ranges', 3);

        // Every object is logged, by the person who applied the standard.
        $this->assertSame(3, $this->countEntries(ActivityEvent::VlanCreated));
        $this->assertSame(2, $this->countEntries(ActivityEvent::SubnetCreated));
        $this->assertSame(3, $this->countEntries(ActivityEvent::IpRangeCreated));

        $logged = $this->lastEntry(ActivityEvent::VlanCreated);

        $this->assertTrue($editor->is($logged->causer));
        $this->assertSame('40 — Invités', $logged->attribute_changes?->toArray()['attributes']['catalog']);

        // Nothing is left to apply.
        $this->actingAs($editor)
            ->get(route('sites.standard.create', $this->site))
            ->assertInertia(fn (Assert $page) => $page->where('entries', []));
    }

    public function test_the_summary_agrees_with_what_was_created(): void
    {
        $this->apply([$this->line($this->offices)], 'active')->assertInertiaFlash('toast.message', '1 VLAN créé.');
        $this->apply([$this->line($this->servers, '10.30.20.0/24')])->assertInertiaFlash('toast.message', '1 VLAN créé, 1 sous-réseau créé.');

        // The status chosen is the one of everything created.
        $this->assertSame(['active', 'planned'], Vlan::query()->orderBy('vid')->pluck('status')->map(fn ($status) => $status->value)->all());
    }

    public function test_the_number_and_the_name_of_an_entry_can_be_changed_on_its_line(): void
    {
        $this->rule();

        $this->apply([
            $this->line($this->offices, '10.30.110.0/24', ['vid' => 110, 'name' => 'Bureaux du dépôt']),
        ])->assertSessionHasNoErrors();

        $vlan = Vlan::query()->with('catalogVlan')->sole();

        // Attached to the entry all the same: what differs is a deviation.
        $this->assertSame([110, 'Bureaux du dépôt', $this->offices->id], [$vlan->vid, $vlan->name, $vlan->catalog_vlan_id]);

        $this->actingAs($this->reader())
            ->get(route('vlans.show', $vlan))
            ->assertInertia(fn (Assert $page) => $page->where('vlan.deviations', [
                ['kind' => 'vlan_vid', 'reason' => 'VID 110, catalogue : 10'],
                ['kind' => 'vlan_name', 'reason' => 'Nom Bureaux du dépôt, catalogue : Bureautique'],
            ]));
    }

    // --- Refusals, each on its line, and nothing is created -----------------------------

    public function test_a_number_already_borne_by_a_vlan_outside_the_catalogue_is_refused_on_its_line(): void
    {
        $existing = Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 10, 'name' => 'Bureautique']);

        $response = $this->apply([
            $this->line($this->servers, '10.30.20.0/24'),
            $this->line($this->offices, '10.30.10.0/24'),
        ]);

        $this->assertRefused(
            $response,
            'lines.1.vid',
            'Le VLAN 10 « Bureautique » existe déjà sur ce site, hors catalogue : rattachez-le depuis sa page.',
        );
        $this->assertSame(['lines.1.vid'], $this->errorFields());

        // All or nothing: the line that was fine is not created either.
        $this->assertSame(1, Vlan::query()->count());
        $this->assertDatabaseCount('subnets', 0);
        $this->assertSame(0, $this->countEntries(ActivityEvent::VlanCreated));
        $this->assertSame(0, $this->countEntries(ActivityEvent::SubnetCreated));
        $this->assertNull($existing->refresh()->catalog_vlan_id);
    }

    public function test_a_subnet_that_overlaps_an_existing_one_is_refused_on_its_line_and_names_it(): void
    {
        $this->subnet('10.30.0.0/20');

        $response = $this->apply([
            $this->line($this->offices, '10.30.10.0/24'),
            $this->line($this->guests, '10.30.40.0/24'),
        ]);

        $this->assertRefused($response, 'lines.0.network', 'Ce sous-réseau chevauche 10.30.0.0/20.');
        $this->assertSame(['lines.0.network'], $this->errorFields());

        $this->assertDatabaseCount('vlans', 0);
        $this->assertDatabaseCount('subnets', 1);

        // Two lines of the same request cannot share addresses either.
        $response = $this->apply([
            $this->line($this->guests, '10.30.40.0/24'),
            $this->line($this->servers, '10.30.40.128/25'),
        ]);

        $this->assertRefused($response, 'lines.1.network', 'Ce sous-réseau chevauche 10.30.40.0/24.');
        $this->assertDatabaseCount('vlans', 0);
    }

    public function test_a_breakdown_that_does_not_fit_is_refused_on_its_line(): void
    {
        $this->rule();

        $response = $this->apply([
            $this->line($this->offices, '10.30.10.0/24', ['apply_breakdown' => true]),
            $this->line($this->servers, '10.30.20.0/26', ['apply_breakdown' => true]),
        ]);

        $this->assertRefused(
            $response,
            'lines.1.apply_breakdown',
            'Le découpage type ne tient pas dans 10.30.20.0/26 : la plage 10.30.20.21 – 10.30.20.99 dépasse ses adresses utilisables (10.30.20.1 – 10.30.20.62).',
        );
        $this->assertSame(['lines.1.apply_breakdown'], $this->errorFields());

        $this->assertDatabaseCount('vlans', 0);
        $this->assertDatabaseCount('subnets', 0);
        $this->assertDatabaseCount('ip_ranges', 0);
        $this->assertSame(0, $this->countEntries(ActivityEvent::IpRangeCreated));
    }

    public function test_every_line_in_the_way_gets_its_own_message_at_once(): void
    {
        $this->rule();

        Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 10, 'name' => 'Ancien réseau']);
        // A VLAN attached to another entry bears the number 40.
        $other = CatalogVlan::factory()->create(['vid' => 99, 'name' => 'Administration']);
        Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 40, 'name' => 'Admin', 'catalog_vlan_id' => $other->id]);

        $response = $this->apply([
            $this->line($this->offices, '10.30.10.0/24'),
            // A gateway outside of its subnet.
            $this->line($this->servers, '10.30.20.0/24', ['gateway' => '10.30.21.1']),
            $this->line($this->guests, '10.30.40.0/24'),
        ]);

        $response->assertRedirect();

        $this->assertSame(['lines.0.vid', 'lines.1.gateway', 'lines.2.vid'], $this->errorFields());
        $this->assertSame('Le VLAN 10 « Ancien réseau » existe déjà sur ce site, hors catalogue : rattachez-le depuis sa page.', $this->error('lines.0.vid'));
        $this->assertSame('Ce site a déjà un VLAN avec ce numéro.', $this->error('lines.2.vid'));

        $this->assertSame(2, Vlan::query()->count());
        $this->assertDatabaseCount('subnets', 0);
    }

    public function test_an_entry_the_site_follows_already_is_refused_on_its_line(): void
    {
        Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 110, 'name' => 'Bureaux', 'catalog_vlan_id' => $this->offices->id]);

        $response = $this->apply([$this->line($this->offices), $this->line($this->servers)]);

        $this->assertRefused(
            $response,
            'lines.0.catalog_vlan_id',
            'Ce site a déjà un VLAN rattaché à cette entrée du catalogue (110 — Bureaux).',
        );
        $this->assertSame(1, Vlan::query()->count());
    }

    public function test_the_shape_of_the_lines_is_checked(): void
    {
        $this->assertRefused($this->apply([]), 'lines', 'Cochez au moins une entrée du catalogue.');
        $this->assertRefused(
            $this->actingAs($this->editor())->post(route('sites.standard.store', $this->site), ['lines' => [$this->line($this->offices)]]),
            'status',
        );
        $this->assertRefused($this->apply([$this->line($this->offices)], 'draft'), 'status');

        foreach ([
            ['lines.0.catalog_vlan_id', ['catalog_vlan_id' => 999999]],
            ['lines.0.vid', ['vid' => 0]],
            ['lines.0.vid', ['vid' => 4095]],
            ['lines.0.name', ['name' => '']],
            ['lines.0.name', ['name' => str_repeat('a', 65)]],
            ['lines.0.network', ['with_subnet' => true, 'network' => '']],
            ['lines.0.network', ['with_subnet' => true, 'network' => '10.30.10']],
            ['lines.0.prefix_length', ['with_subnet' => true, 'network' => '10.30.10.0', 'prefix_length' => 33]],
            ['lines.0.gateway', ['with_subnet' => true, 'network' => '10.30.10.0/24', 'gateway' => 'passerelle']],
        ] as [$field, $wrong]) {
            $this->assertRefused($this->apply([$this->line($this->offices, null, $wrong)]), $field);
        }

        // The same entry on two lines.
        $this->assertRefused($this->apply([$this->line($this->offices), $this->line($this->offices, null, ['vid' => 11, 'name' => 'Bis'])]), 'lines.0.catalog_vlan_id');

        // An address with host bits is refused by the rules of subnets,
        // with the subnet that was probably meant.
        $this->assertRefused(
            $this->apply([$this->line($this->offices, '10.30.10.7/24')]),
            'lines.0.network',
            "10.30.10.7/24 n'est pas une adresse de réseau. Vouliez-vous dire 10.30.10.0/24 ?",
        );

        // What a subnet section holds is ignored while its box is not ticked.
        $this->apply([$this->line($this->offices, null, ['network' => 'n importe quoi', 'gateway' => 'rien', 'apply_breakdown' => true])])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Vlan::query()->count());
        $this->assertDatabaseCount('subnets', 0);
        $this->assertDatabaseCount('ip_ranges', 0);
    }

    public function test_without_a_rule_the_standard_still_creates_vlans_and_subnets(): void
    {
        $this->apply([
            $this->line($this->offices, '192.168.10.0/24', ['gateway' => '192.168.10.254']),
            $this->line($this->servers),
        ])->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', '2 VLAN créés, 1 sous-réseau créé.');

        $this->assertSame('192.168.10.0/24', Subnet::query()->sole()->cidr());

        // The ranges need a rule: asked without one, the line is refused.
        $this->assertRefused(
            $this->apply([$this->line($this->guests, '192.168.40.0/24', ['apply_breakdown' => true])]),
            'lines.0.apply_breakdown',
            "La règle d'adressage n'a pas de découpage type à appliquer.",
        );
    }

    // --- Rights ---------------------------------------------------------------

    public function test_only_editors_and_administrators_apply_the_standard(): void
    {
        $payload = ['status' => 'planned', 'lines' => [$this->line($this->offices)]];

        $this->get(route('sites.standard.create', $this->site))->assertRedirect(route('login'));
        $this->post(route('sites.standard.store', $this->site), $payload)->assertRedirect(route('login'));

        $this->actingAs($this->reader())->get(route('sites.standard.create', $this->site))->assertForbidden();
        $this->actingAs($this->reader())->post(route('sites.standard.store', $this->site), $payload)->assertForbidden();

        $this->assertDatabaseCount('vlans', 0);

        $this->actingAs($this->admin())->get(route('sites.standard.create', $this->site))->assertOk();
        $this->actingAs($this->admin())->post(route('sites.standard.store', $this->site), $payload)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('vlans', 1);

        $this->actingAs($this->editor())->get(route('sites.standard.create', 999999))->assertNotFound();
    }
}
