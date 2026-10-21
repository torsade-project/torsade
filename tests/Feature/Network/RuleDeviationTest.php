<?php

// torsade/tests/Feature/Network/RuleDeviationTest.php

namespace Tests\Feature\Network;

use App\Enums\SubnetKind;
use App\Models\AddressBlock;
use App\Models\AddressingRule;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\Vlan;
use App\Services\Network\Deviations;
use Illuminate\Support\Facades\Schema;

/**
 * The three ways what is documented can differ from the addressing rule:
 * the number of a site, the block of a site, the subnet of a VLAN. They
 * only exist with a rule and where the rule reaches; like the others they
 * are worked out when a page is shown, stored nowhere, and refuse nothing.
 */
class RuleDeviationTest extends NetworkTestCase
{
    private Site $workshop;

    private Deviations $deviations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workshop = Site::factory()->create(['name' => 'Atelier', 'number' => 20]);
        $this->deviations = app(Deviations::class);
    }

    /**
     * The usual rule: site 20, VLAN 10 is expected in 10.20.10.0/24.
     */
    private function rule(): AddressingRule
    {
        return AddressingRule::factory()->create();
    }

    private function vlanSubnet(Site $site, int $vid, string $cidr, SubnetKind $kind = SubnetKind::Segment): Subnet
    {
        $vlan = Vlan::factory()->create(['site_id' => $site->id, 'vid' => $vid]);

        return Subnet::factory()->cidr($cidr)->kind($kind)->create(['vlan_id' => $vlan->id]);
    }

    /**
     * What a page shows as deviations: a list of its own, or the
     * deviations of one of its objects.
     *
     * @return list<array<string, mixed>>
     */
    private function shownOn(string $route, mixed $parameter, string $prop): array
    {
        return (array) $this->actingAs($this->reader())->get(route($route, $parameter))->assertOk()->inertiaProps($prop);
    }

    // --- 1: the number of a site beyond what the rule reaches -----------------------

    public function test_a_number_beyond_the_rule_is_shown_on_the_site_and_on_the_rule(): void
    {
        $this->rule();

        $site = Site::factory()->create(['name' => 'Dépôt', 'number' => 300]);

        $expected = [['kind' => 'site_number_outside_rule', 'reason' => 'Numéro 300 : la règle en permet de 0 à 255']];

        $this->assertSame($expected, $this->deviations->ofSite($site->load('addressBlocks'), $this->deviations->scheme()));
        $this->assertSame($expected, $this->shownOn('sites.show', $site, 'siteDeviations'));

        $row = collect($this->shownOn('addressing-rule.show', [], 'application'))->firstWhere('id', $site->id);

        $this->assertSame('outside', $row['state']);
        $this->assertSame($expected[0], $row['deviation']);

        // The last number the rule reaches is no deviation of that kind.
        $site->update(['number' => 255]);

        $this->assertSame(['site_block_missing'], array_column($this->shownOn('sites.show', $site, 'siteDeviations'), 'kind'));
    }

    // --- 2: the block the rule expects, not declared --------------------------------

    public function test_an_expected_block_that_is_not_declared_is_shown_on_the_site_and_on_the_rule(): void
    {
        $this->rule();

        $expected = [['kind' => 'site_block_missing', 'reason' => 'Bloc attendu par la règle : 10.20.0.0/16']];

        $this->assertSame($expected, $this->shownOn('sites.show', $this->workshop, 'siteDeviations'));

        $row = collect($this->shownOn('addressing-rule.show', [], 'application'))->firstWhere('id', $this->workshop->id);

        $this->assertSame('missing', $row['state']);
        $this->assertSame($expected[0], $row['deviation']);

        // Another block of the site, a larger one or a smaller one, is not
        // the expected one.
        $other = AddressBlock::factory()->cidr('10.20.0.0/17')->forSite($this->workshop)->create();

        $this->assertSame($expected, $this->shownOn('sites.show', $this->workshop, 'siteDeviations'));

        // Nor is the same block declared for the organisation.
        $other->delete();
        $ofOrganisation = AddressBlock::factory()->cidr('10.20.0.0/16')->create();

        $this->assertSame($expected, $this->shownOn('sites.show', $this->workshop, 'siteDeviations'));

        // Declared for the site, nothing is left to say.
        $ofOrganisation->update(['site_id' => $this->workshop->id]);

        $this->assertSame([], $this->shownOn('sites.show', $this->workshop, 'siteDeviations'));
        $this->assertSame('declared', collect($this->shownOn('addressing-rule.show', [], 'application'))->firstWhere('id', $this->workshop->id)['state']);
    }

    // --- 3: the subnet of a VLAN that is not the one the rule expects -----------------

    public function test_a_subnet_that_differs_from_the_rule_is_shown_on_the_subnet_the_vlan_and_the_site(): void
    {
        $this->rule();

        AddressBlock::factory()->cidr('10.20.0.0/16')->forSite($this->workshop)->create();

        $subnet = $this->vlanSubnet($this->workshop, 150, '10.20.50.0/24');

        $expected = [['kind' => 'subnet_differs_from_rule', 'reason' => 'Attendu par la règle : 10.20.150.0/24']];

        $this->assertSame(
            $expected,
            $this->deviations->ofSubnet($subnet->load(['vlan', 'site']), $this->deviations->siteBlocks(), $this->deviations->scheme()),
        );

        // On the subnet: its page, the list, and the page of its block.
        $this->assertSame($expected, $this->shownOn('subnets.show', $subnet, 'deviations'));
        $this->assertSame($expected, $this->shownOn('subnets.index', [], 'subnets.0.deviations'));
        $this->assertSame(
            $expected,
            $this->shownOn('address-blocks.show', AddressBlock::query()->whereNotNull('site_id')->sole(), 'subnets.0.deviations'),
        );
        // On its VLAN.
        $this->assertSame($expected, $this->shownOn('vlans.show', $subnet->vlan_id, 'subnetDeviations'));
        // On its site.
        $this->assertSame(
            [['type' => 'subnet', 'id' => $subnet->id, 'label' => '10.20.50.0/24', 'deviations' => $expected]],
            $this->shownOn('sites.show', $this->workshop, 'deviations'),
        );
    }

    public function test_a_subnet_differs_by_its_address_or_by_its_length(): void
    {
        $this->rule();

        $reason = fn (Subnet $subnet): ?string => $this->shownOn('subnets.show', $subnet, 'deviations')[0]['reason'] ?? null;

        // The right address with another length is another subnet.
        $shorter = $this->vlanSubnet($this->workshop, 10, '10.20.10.0/25');

        $this->assertSame('Attendu par la règle : 10.20.10.0/24', $reason($shorter));

        // The expected subnet, to the bit.
        $exact = $this->vlanSubnet($this->workshop, 30, '10.20.30.0/24');

        $this->assertNull($reason($exact));
        $this->assertSame([], $this->shownOn('vlans.show', $exact->vlan_id, 'subnetDeviations'));

        // Elsewhere altogether: it differs from the rule, whatever the
        // blocks say.
        $elsewhere = $this->vlanSubnet($this->workshop, 40, '192.168.40.0/24');

        $this->assertSame('Attendu par la règle : 10.20.40.0/24', $reason($elsewhere));
    }

    public function test_the_deviations_of_the_blocks_and_of_the_rule_add_up(): void
    {
        $this->rule();

        AddressBlock::factory()->cidr('10.20.0.0/16')->forSite($this->workshop)->create();

        $subnet = $this->vlanSubnet($this->workshop, 10, '192.168.1.0/24');

        $this->assertSame(
            [
                ['kind' => 'subnet_outside_site_blocks', 'reason' => 'Hors des blocs du site (10.20.0.0/16)'],
                ['kind' => 'subnet_differs_from_rule', 'reason' => 'Attendu par la règle : 10.20.10.0/24'],
            ],
            $this->shownOn('subnets.show', $subnet, 'deviations'),
        );
    }

    // --- Where the rule says nothing ----------------------------------------------------

    public function test_the_rule_expects_nothing_where_it_does_not_reach(): void
    {
        $this->rule();

        // A VID the rule does not cover: it is not a deviation.
        $beyond = $this->vlanSubnet($this->workshop, 300, '192.168.30.0/24');
        // A site without a number, and a site whose number is out of reach.
        $unnumbered = $this->vlanSubnet(Site::factory()->create(['name' => 'Annexe']), 10, '192.168.31.0/24');
        $outside = $this->vlanSubnet(Site::factory()->create(['name' => 'Dépôt', 'number' => 300]), 10, '192.168.32.0/24');
        // A subnet that no VLAN carries.
        $standalone = $this->subnet('192.168.33.0/24', ['site_id' => $this->workshop->id]);

        foreach ([$beyond, $unnumbered, $outside, $standalone] as $subnet) {
            $this->assertSame([], $this->shownOn('subnets.show', $subnet, 'deviations'), $subnet->cidr());
        }

        $this->assertSame([], $this->shownOn('vlans.show', $beyond->vlan_id, 'subnetDeviations'));

        // Only a segment is expected anywhere: a link carried by a VLAN is
        // where it is.
        $link = $this->vlanSubnet($this->workshop, 11, '10.255.0.0/31', SubnetKind::PointToPoint);
        $outsideAddresses = $this->vlanSubnet($this->workshop, 12, '203.0.113.8/29', SubnetKind::External);

        $this->assertSame([], $this->shownOn('subnets.show', $link, 'deviations'));
        $this->assertSame([], $this->shownOn('subnets.show', $outsideAddresses, 'deviations'));

        // A VLAN without a subnet has nothing to compare.
        $bare = Vlan::factory()->create(['site_id' => $this->workshop->id, 'vid' => 13]);

        $this->assertSame([], $this->shownOn('vlans.show', $bare, 'subnetDeviations'));
        // A site without a number is no deviation.
        $this->assertSame([], $this->shownOn('sites.show', $unnumbered->site_id, 'siteDeviations'));
    }

    public function test_without_a_rule_nothing_differs_from_it(): void
    {
        $site = Site::factory()->create(['name' => 'Dépôt', 'number' => 300]);
        $subnet = $this->vlanSubnet($this->workshop, 150, '192.168.50.0/24');

        $this->assertNull($this->deviations->scheme());

        $this->assertSame([], $this->shownOn('sites.show', $site, 'siteDeviations'));
        $this->assertSame([], $this->shownOn('sites.show', $this->workshop, 'siteDeviations'));
        $this->assertSame([], $this->shownOn('sites.show', $this->workshop, 'deviations'));
        $this->assertSame([], $this->shownOn('subnets.show', $subnet, 'deviations'));
        $this->assertSame([], $this->shownOn('vlans.show', $subnet->vlan_id, 'subnetDeviations'));
        $this->assertSame([], $this->shownOn('addressing-rule.show', [], 'application'));

        // With a rule they appear; once it is deleted they are gone, and
        // nothing was touched.
        $this->rule();

        $this->assertNotSame([], $this->shownOn('sites.show', $site, 'siteDeviations'));
        $this->assertNotSame([], $this->shownOn('subnets.show', $subnet, 'deviations'));

        $this->actingAs($this->editor())->delete(route('addressing-rule.destroy'))->assertSessionHasNoErrors();

        $this->assertSame([], $this->shownOn('sites.show', $site, 'siteDeviations'));
        $this->assertSame([], $this->shownOn('subnets.show', $subnet, 'deviations'));
        $this->assertSame(300, $site->refresh()->number);
        $this->assertSame('192.168.50.0/24', $subnet->refresh()->cidr());
    }

    // --- Never stored, never in the way ------------------------------------------------

    public function test_the_deviations_of_the_rule_are_stored_nowhere_and_follow_the_rule_at_once(): void
    {
        $rule = $this->rule();
        $subnet = $this->vlanSubnet($this->workshop, 10, '10.20.10.0/24');

        $this->assertSame([], $this->shownOn('subnets.show', $subnet, 'deviations'));

        foreach (Schema::getTableListing() as $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                $this->assertStringNotContainsString('deviation', $column, $table);
                $this->assertStringNotContainsString('expected', $column, $table);
            }
        }

        // The rule changes: what it expects changes with it, and the subnet
        // is left as it is.
        $rule->update(['vlan_prefix_length' => 26]);

        $this->assertSame(
            [['kind' => 'subnet_differs_from_rule', 'reason' => 'Attendu par la règle : 10.20.2.128/26']],
            $this->shownOn('subnets.show', $subnet, 'deviations'),
        );
        $this->assertSame('10.20.10.0/24', $subnet->refresh()->cidr());
    }

    public function test_what_differs_from_the_rule_is_never_refused(): void
    {
        $editor = $this->editor();

        $this->rule();

        // A number the rule does not reach.
        $this->actingAs($editor)->post(route('sites.store'), ['status' => 'active', 'name' => 'Dépôt', 'number' => 300])->assertSessionHasNoErrors();

        // A subnet that is not the one expected for its VLAN, created with
        // the VLAN or after it.
        $this->actingAs($editor)->post(route('vlans.store'), [
            'site_id' => $this->workshop->id, 'vid' => 10, 'name' => 'Bureautique', 'status' => 'active',
            'with_subnet' => true, 'subnet' => ['network' => '192.168.10.0/24'],
        ])->assertSessionHasNoErrors();

        $vlan = Vlan::factory()->create(['site_id' => $this->workshop->id, 'vid' => 20]);

        $this->actingAs($editor)
            ->post(route('subnets.store'), ['network' => '192.168.20.0/24', 'vlan_id' => $vlan->id, 'status' => 'active'])
            ->assertSessionHasNoErrors();

        // A block that is not the expected one.
        $this->actingAs($editor)
            ->post(route('address-blocks.store'), ['network' => '10.99.0.0/16', 'site_id' => $this->workshop->id, 'status' => 'active'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('subnets', 2);
        $this->assertCount(2, $this->shownOn('sites.show', $this->workshop, 'deviations'));
    }
}
