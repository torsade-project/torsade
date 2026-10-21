<?php

// torsade/tests/Feature/Network/RuleWithoutSiteLevelTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Models\AddressBlock;
use App\Models\AddressingRule;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\Vlan;
use App\Services\Network\Deviations;
use App\Support\AddressingScheme;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * An addressing rule without a level of sites: the subnets of VLANs are
 * ranked by VID straight in the block of the organisation, the same for
 * every site. The rule then uses no number of a site and expects no block
 * of a site.
 */
class RuleWithoutSiteLevelTest extends NetworkTestCase
{
    private AddressBlock $block;

    protected function setUp(): void
    {
        parent::setUp();

        $this->block = AddressBlock::factory()->cidr('192.168.0.0/16')->create(['name' => 'Réseau local']);
    }

    /**
     * The example of the documentation: 192.168.<VID>.0/24.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'aggregate_block_id' => $this->block->id,
            'site_prefix_length' => null,
            'vlan_prefix_length' => 24,
            'gateway_position' => 'first',
            'ranges' => [],
            ...$overrides,
        ];
    }

    private function rule(): AddressingRule
    {
        return AddressingRule::factory()->from($this->block)->withoutSiteLevel()->create();
    }

    private function scheme(): AddressingScheme
    {
        $scheme = app(Deviations::class)->scheme();

        $this->assertNotNull($scheme);

        return $scheme;
    }

    private function vlanSubnet(Site $site, int $vid, string $cidr): Subnet
    {
        $vlan = Vlan::factory()->create(['site_id' => $site->id, 'vid' => $vid]);

        return Subnet::factory()->cidr($cidr)->create(['vlan_id' => $vlan->id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function deviationsOf(Subnet $subnet): array
    {
        return (array) $this->actingAs($this->reader())->get(route('subnets.show', $subnet))->assertOk()->inertiaProps('deviations');
    }

    public function test_an_editor_saves_a_rule_without_a_level_of_sites(): void
    {
        $editor = $this->editor();

        // The empty choice of the form.
        $this->actingAs($editor)
            ->put(route('addressing-rule.update'), $this->payload(['site_prefix_length' => '']))
            ->assertRedirect(route('addressing-rule.show'))
            ->assertSessionHasNoErrors();

        $rule = AddressingRule::query()->sole();

        $this->assertNull($rule->site_prefix_length);
        $this->assertSame(24, $rule->vlan_prefix_length);

        $created = $this->lastEntry(ActivityEvent::AddressingRuleCreated);

        $this->assertSame('192.168.0.0/16, /24', $created->getProperty('label'));
        $this->assertNull($created->attribute_changes?->toArray()['attributes']['site_prefix_length']);

        // The page hands the browser a rule without a level of sites, and
        // has nothing to apply site by site.
        Site::factory()->create(['name' => 'Siège', 'number' => 1]);

        $this->actingAs($this->reader())
            ->get(route('addressing-rule.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rule.network', '192.168.0.0')
                ->where('rule.prefix_length', 16)
                ->where('rule.site_prefix_length', null)
                ->where('rule.vlan_prefix_length', 24)
                ->where('application', []));

        // The log tells the level of sites that is gone.
        $this->actingAs($editor)->put(route('addressing-rule.update'), $this->payload(['site_prefix_length' => 20]))->assertSessionHasNoErrors();
        $this->actingAs($editor)->put(route('addressing-rule.update'), $this->payload())->assertSessionHasNoErrors();

        $this->actingAs($this->admin())
            ->get(route('activity-log.index', ['event' => 'addressing_rule.updated']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.subject.title', '192.168.0.0/16, /24')
                ->where('entries.data.0.changes', [['field' => 'Niveau de site', 'old' => '/20', 'new' => '—']]));
    }

    public function test_without_a_level_of_sites_the_subnets_of_vlans_are_cut_out_of_the_block_of_the_organisation(): void
    {
        $editor = $this->editor();

        foreach ([16, 8] as $length) {
            $this->assertRefused(
                $this->actingAs($editor)->put(route('addressing-rule.update'), $this->payload(['vlan_prefix_length' => $length])),
                'vlan_prefix_length',
                "Sans niveau de site, les sous-réseaux de VLAN sont découpés dans le bloc de l'organisation : leur longueur doit être supérieure à 16.",
            );
            $this->assertSame(['vlan_prefix_length'], $this->errorFields());
        }

        $this->assertRefused(
            $this->actingAs($editor)->put(route('addressing-rule.update'), $this->payload(['vlan_prefix_length' => 31])),
            'vlan_prefix_length',
            "Un sous-réseau de VLAN est au plus un /30 : au-delà, il n'a plus de place pour une passerelle et un poste.",
        );

        $this->assertDatabaseCount('addressing_rules', 0);

        // The bounds that are accepted: one bit for the VLANs, a /30.
        foreach ([17, 30] as $length) {
            $this->actingAs($editor)->put(route('addressing-rule.update'), $this->payload(['vlan_prefix_length' => $length]))->assertSessionHasNoErrors();
        }

        // The block of the rule keeps room for its subnets of VLANs.
        $update = fn (string $cidr) => $this->actingAs($editor)->put(
            route('address-blocks.update', $this->block),
            ['network' => $cidr, 'site_id' => null, 'status' => 'active'],
        );

        $this->assertRefused(
            $update('192.168.0.0/30'),
            'prefix_length',
            "La règle d'adressage découpe ce bloc en sous-réseaux de VLAN en /30 : son préfixe doit rester inférieur à 30.",
        );
        $update('192.168.0.0/29')->assertSessionHasNoErrors();
    }

    public function test_the_example_of_the_documentation(): void
    {
        $this->rule();

        $scheme = $this->scheme();

        $this->assertFalse($scheme->hasSiteLevel());
        $this->assertSame(255, $scheme->maxVid());

        foreach ([1 => '192.168.1.0/24', 20 => '192.168.20.0/24', 255 => '192.168.255.0/24'] as $vid => $cidr) {
            $this->assertSame($cidr, AddressingScheme::cidr((array) $scheme->vlanSubnet(null, $vid)), "VLAN {$vid}");
        }

        $this->assertNull($scheme->vlanSubnet(null, 256));

        // On documented VLANs: the expected subnets differ from nothing, a
        // VID the rule does not cover differs from nothing either.
        $site = Site::factory()->create(['name' => 'Siège']);

        foreach ([[1, '192.168.1.0/24'], [20, '192.168.20.0/24'], [255, '192.168.255.0/24'], [256, '10.0.0.0/24']] as [$vid, $cidr]) {
            $this->assertSame([], $this->deviationsOf($this->vlanSubnet($site, $vid, $cidr)), "VLAN {$vid}");
        }

        // One that is elsewhere is told where the rule expects it.
        $this->assertSame(
            [['kind' => 'subnet_differs_from_rule', 'reason' => 'Attendu par la règle : 192.168.30.0/24']],
            $this->deviationsOf($this->vlanSubnet($site, 30, '192.168.130.0/24')),
        );
    }

    public function test_two_sites_expect_the_same_subnets(): void
    {
        $this->rule();

        $first = Site::factory()->create(['name' => 'Siège', 'number' => 1]);
        $second = Site::factory()->create(['name' => 'Annexe']);
        $editor = $this->editor();

        // The forms of a VLAN work out what the rule proposes from the rule
        // they are handed: the number of the site counts for nothing.
        $this->actingAs($editor)
            ->get(route('vlans.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('rule.site_prefix_length', null));

        $scheme = $this->scheme();

        $this->assertSame($scheme->vlanSubnet($first->number, 20), $scheme->vlanSubnet($second->number, 20));

        $store = fn (Site $site, string $network) => $this->actingAs($editor)->post(route('vlans.store'), [
            'site_id' => $site->id,
            'vid' => 20,
            'name' => 'Bureautique',
            'status' => 'active',
            'with_subnet' => true,
            'subnet' => ['network' => $network, 'prefix_length' => 24, 'gateway' => null],
        ]);

        // The first site takes the subnet the rule proposes.
        $store($first, '192.168.20.0')->assertSessionHasNoErrors();

        $taken = Subnet::query()->sole();

        $this->assertSame([], $this->deviationsOf($taken));

        // The second one is proposed the same, which another subnet already
        // is: refused like any overlap, and no VLAN is left behind.
        $this->assertRefused($store($second, '192.168.20.0'), 'subnet.network', 'Ce sous-réseau chevauche 192.168.20.0/24.');
        $this->assertSame(0, Vlan::query()->where('site_id', $second->id)->count());

        // Another subnet for it differs from the rule, which shows on its
        // subnet and on its site.
        $store($second, '192.168.120.0')->assertSessionHasNoErrors();

        $other = Subnet::query()->where('network', inet_pton('192.168.120.0'))->sole();
        $expected = ['kind' => 'subnet_differs_from_rule', 'reason' => 'Attendu par la règle : 192.168.20.0/24'];

        $this->assertSame([$expected], $this->deviationsOf($other));

        $items = (array) $this->actingAs($this->reader())->get(route('sites.show', $second))->assertOk()->inertiaProps('deviations');

        $this->assertContains($expected, collect($items)->firstWhere('id', $other->id)['deviations'] ?? []);
    }

    public function test_without_a_level_of_sites_no_number_nor_block_of_a_site_is_expected(): void
    {
        $this->rule();

        $beyond = Site::factory()->create(['name' => 'Dépôt', 'number' => 300]);
        $numbered = Site::factory()->create(['name' => 'Atelier', 'number' => 20]);
        $editor = $this->editor();

        foreach ([$beyond, $numbered] as $site) {
            $this->assertSame([], $this->actingAs($this->reader())->get(route('sites.show', $site))->assertOk()->inertiaProps('siteDeviations'));
        }

        $this->assertRefused(
            $this->actingAs($editor)->post(route('sites.expected-block.store', $numbered)),
            'block',
            "La règle n'a aucun niveau de site : elle n'attend aucun bloc pour un site.",
        );

        // The window of a site is handed a rule that expects no block.
        $this->actingAs($editor)
            ->get(route('sites.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('rule.site_prefix_length', null));

        $this->assertSame(0, AddressBlock::query()->whereNotNull('site_id')->count());
    }

    public function test_dropping_the_level_of_sites_changes_no_object(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->put(route('addressing-rule.update'), $this->payload(['site_prefix_length' => 20]))->assertSessionHasNoErrors();

        // The site 1 has its block, 192.168.16.0/20, and its VLAN 2 the
        // subnet the rule expects there.
        $site = Site::factory()->create(['name' => 'Siège', 'number' => 1]);
        AddressBlock::factory()->cidr('192.168.16.0/20')->forSite($site)->create();
        $subnet = $this->vlanSubnet($site, 2, '192.168.18.0/24');

        $this->assertSame([], $this->deviationsOf($subnet));

        $everything = fn (): array => collect(['sites', 'address_blocks', 'vlans', 'subnets', 'ip_ranges', 'ip_addresses'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all()])
            ->all();

        $before = $everything();

        $this->actingAs($editor)->put(route('addressing-rule.update'), $this->payload())->assertSessionHasNoErrors();

        // Nothing moves: only what the rule expects does.
        $this->assertSame($before, $everything());
        $this->assertSame(
            [['kind' => 'subnet_differs_from_rule', 'reason' => 'Attendu par la règle : 192.168.2.0/24']],
            $this->deviationsOf($subnet),
        );
        $this->assertSame([], $this->actingAs($this->reader())->get(route('sites.show', $site))->assertOk()->inertiaProps('siteDeviations'));

        // And back.
        $this->actingAs($editor)->put(route('addressing-rule.update'), $this->payload(['site_prefix_length' => 20]))->assertSessionHasNoErrors();

        $this->assertSame($before, $everything());
        $this->assertSame([], $this->deviationsOf($subnet));
    }
}
