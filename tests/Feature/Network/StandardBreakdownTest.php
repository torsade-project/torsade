<?php

// torsade/tests/Feature/Network/StandardBreakdownTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\GatewayPosition;
use App\Enums\SubnetKind;
use App\Models\AddressingRule;
use App\Models\IpAddress;
use App\Models\IpRange;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\Vlan;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The standard breakdown of the addressing rule: its ranges, created in a
 * subnet at once. The server works them out from the rule in force and
 * from the subnet it has saved; the browser only sends a box.
 */
class StandardBreakdownTest extends NetworkTestCase
{
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create(['name' => 'Atelier', 'number' => 20]);
    }

    /**
     * The usual rule with its ranges: reserved 2 to 20, static 21 to 99,
     * DHCP 100 to 200.
     */
    private function rule(GatewayPosition $gateway = GatewayPosition::First): AddressingRule
    {
        return AddressingRule::factory()->gateway($gateway)->withBreakdown()->create();
    }

    /**
     * The ranges of a subnet, as text.
     *
     * @return list<string>
     */
    private function rangesOf(Subnet $subnet): array
    {
        return array_values($subnet->ipRanges()->orderBy('start_address')->get()
            ->map(fn (IpRange $range): string => trim($range->kind->value.' '.$range->label().' '.$range->name))
            ->all());
    }

    private const array USUAL = [
        'reserved 10.20.10.2 – 10.20.10.20 Infrastructure',
        'static 10.20.10.21 – 10.20.10.99 Adresses fixes',
        'dhcp 10.20.10.100 – 10.20.10.200',
    ];

    // --- With a new VLAN ----------------------------------------------------------

    public function test_a_vlan_is_created_with_its_subnet_cut_into_the_ranges_of_the_rule(): void
    {
        $editor = $this->editor();

        $this->rule();

        $this->actingAs($editor)->post(route('vlans.store'), [
            'site_id' => $this->site->id, 'vid' => 10, 'name' => 'Bureautique', 'status' => 'planned',
            'with_subnet' => true,
            // Only the box: no range, no offset.
            'subnet' => ['network' => '10.20.10.0/24', 'gateway' => '10.20.10.1', 'apply_breakdown' => true],
        ])->assertSessionHasNoErrors();

        $subnet = Subnet::query()->sole();

        $this->assertSame(self::USUAL, $this->rangesOf($subnet));

        // Each range is logged, by the person who created the VLAN.
        $this->assertSame(1, $this->countEntries(ActivityEvent::VlanCreated));
        $this->assertSame(1, $this->countEntries(ActivityEvent::SubnetCreated));
        $this->assertSame(3, $this->countEntries(ActivityEvent::IpRangeCreated));
        $this->assertTrue($editor->is($this->lastEntry(ActivityEvent::IpRangeCreated)->causer));
        $this->assertSame('10.20.10.0/24', $this->lastEntry(ActivityEvent::IpRangeCreated)->attribute_changes?->toArray()['attributes']['subnet']);
    }

    public function test_the_ranges_follow_the_subnet_that_is_saved_not_the_one_the_rule_expects(): void
    {
        $this->rule();

        // Another address than the one the rule expects for this VLAN.
        $this->actingAs($this->editor())->post(route('vlans.store'), [
            'site_id' => $this->site->id, 'vid' => 10, 'name' => 'Bureautique', 'status' => 'active',
            'with_subnet' => true,
            'subnet' => ['network' => '192.168.7.0/24', 'apply_breakdown' => true],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            ['reserved 192.168.7.2 – 192.168.7.20 Infrastructure', 'static 192.168.7.21 – 192.168.7.99 Adresses fixes', 'dhcp 192.168.7.100 – 192.168.7.200'],
            $this->rangesOf(Subnet::query()->sole()),
        );
    }

    public function test_offsets_sent_by_the_browser_are_ignored(): void
    {
        $this->rule();

        $this->actingAs($this->editor())->post(route('vlans.store'), [
            'site_id' => $this->site->id, 'vid' => 10, 'name' => 'Bureautique', 'status' => 'active',
            'with_subnet' => true,
            'subnet' => [
                'network' => '10.20.10.0/24', 'apply_breakdown' => true,
                'ranges' => [['kind' => 'dhcp', 'start_offset' => 1, 'end_offset' => 254]],
                'breakdown' => [['kind' => 'dhcp', 'start_address' => '10.20.10.1', 'end_address' => '10.20.10.254']],
            ],
            'ranges' => [['kind' => 'dhcp', 'start_offset' => 1, 'end_offset' => 254]],
        ])->assertSessionHasNoErrors();

        // The ranges of the rule, and nothing of what was sent.
        $this->assertSame(self::USUAL, $this->rangesOf(Subnet::query()->sole()));
    }

    public function test_a_breakdown_that_does_not_fit_is_refused_on_its_box_and_nothing_is_saved(): void
    {
        $this->rule();

        $response = $this->actingAs($this->editor())->post(route('vlans.store'), [
            'site_id' => $this->site->id, 'vid' => 10, 'name' => 'Bureautique', 'status' => 'active',
            'with_subnet' => true,
            'subnet' => ['network' => '10.20.10.0/26', 'apply_breakdown' => true],
        ]);

        $this->assertRefused(
            $response,
            'subnet.apply_breakdown',
            'Le découpage type ne tient pas dans 10.20.10.0/26 : la plage 10.20.10.21 – 10.20.10.99 dépasse ses adresses utilisables (10.20.10.1 – 10.20.10.62).',
        );
        $this->assertSame(['subnet.apply_breakdown'], $this->errorFields());

        // All or nothing: neither VLAN, nor subnet, nor range, nor log.
        $this->assertDatabaseCount('vlans', 0);
        $this->assertDatabaseCount('subnets', 0);
        $this->assertDatabaseCount('ip_ranges', 0);
        $this->assertSame(0, $this->countEntries(ActivityEvent::VlanCreated));
        $this->assertSame(0, $this->countEntries(ActivityEvent::SubnetCreated));

        // Without the box, the same subnet is created without ranges.
        $this->actingAs($this->editor())->post(route('vlans.store'), [
            'site_id' => $this->site->id, 'vid' => 10, 'name' => 'Bureautique', 'status' => 'active',
            'with_subnet' => true,
            'subnet' => ['network' => '10.20.10.0/26', 'apply_breakdown' => false],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('subnets', 1);
        $this->assertDatabaseCount('ip_ranges', 0);
    }

    public function test_a_breakdown_that_breaks_the_rules_of_ranges_is_refused_on_its_box(): void
    {
        // The rule puts the gateway first; this subnet has its gateway in
        // the middle of what would be the DHCP range.
        $this->rule();

        $response = $this->actingAs($this->editor())->post(route('vlans.store'), [
            'site_id' => $this->site->id, 'vid' => 10, 'name' => 'Bureautique', 'status' => 'active',
            'with_subnet' => true,
            'subnet' => ['network' => '10.20.10.0/24', 'gateway' => '10.20.10.150', 'apply_breakdown' => true],
        ]);

        $this->assertRefused(
            $response,
            'subnet.apply_breakdown',
            'La plage 10.20.10.100 – 10.20.10.200 ne peut pas être créée. Une plage DHCP ne peut pas contenir la passerelle (10.20.10.150).',
        );

        $this->assertDatabaseCount('vlans', 0);
        $this->assertDatabaseCount('subnets', 0);
        $this->assertDatabaseCount('ip_ranges', 0);
    }

    public function test_the_box_needs_a_rule_with_ranges(): void
    {
        $payload = [
            'site_id' => $this->site->id, 'vid' => 10, 'name' => 'Bureautique', 'status' => 'active',
            'with_subnet' => true,
            'subnet' => ['network' => '10.20.10.0/24', 'apply_breakdown' => true],
        ];

        // No rule at all.
        $this->assertRefused(
            $this->actingAs($this->editor())->post(route('vlans.store'), $payload),
            'subnet.apply_breakdown',
            "La règle d'adressage n'a pas de découpage type à appliquer.",
        );

        // A rule without ranges.
        AddressingRule::factory()->create();

        $this->assertRefused($this->actingAs($this->editor())->post(route('vlans.store'), $payload), 'subnet.apply_breakdown');

        $this->assertDatabaseCount('vlans', 0);

        // The box is checked like the rest, and ignored without a subnet.
        $this->assertRefused(
            $this->actingAs($this->editor())->post(route('vlans.store'), [...$payload, 'subnet' => ['network' => '10.20.10.0/24', 'apply_breakdown' => 'oui']]),
            'subnet.apply_breakdown',
        );
        $this->actingAs($this->editor())
            ->post(route('vlans.store'), [...$payload, 'with_subnet' => false])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('vlans', 1);
        $this->assertDatabaseCount('subnets', 0);
    }

    // --- With a new subnet --------------------------------------------------------

    public function test_a_subnet_is_created_cut_into_the_ranges_of_the_rule(): void
    {
        $this->rule();

        $vlan = Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 10]);

        $this->actingAs($this->editor())
            ->post(route('subnets.store'), ['network' => '10.20.10.0/24', 'vlan_id' => $vlan->id, 'gateway' => '10.20.10.1', 'status' => 'active', 'apply_breakdown' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame(self::USUAL, $this->rangesOf(Subnet::query()->sole()));
        $this->assertSame(3, $this->countEntries(ActivityEvent::IpRangeCreated));

        // Refused on its box when it does not fit, and nothing is saved.
        $other = Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 11]);

        $this->assertRefused(
            $this->actingAs($this->editor())->post(route('subnets.store'), ['network' => '10.20.11.0/27', 'vlan_id' => $other->id, 'status' => 'active', 'apply_breakdown' => true]),
            'apply_breakdown',
            'Le découpage type ne tient pas dans 10.20.11.0/27 : la plage 10.20.11.21 – 10.20.11.99 dépasse ses adresses utilisables (10.20.11.1 – 10.20.11.30).',
        );
        $this->assertSame(['apply_breakdown'], $this->errorFields());
        $this->assertDatabaseCount('subnets', 1);

        // A kind that has no ranges refuses it too.
        $this->assertRefused(
            $this->actingAs($this->editor())->post(route('subnets.store'), ['network' => '10.255.0.0/31', 'kind' => 'point_to_point', 'status' => 'active', 'apply_breakdown' => true]),
            'apply_breakdown',
            "Un sous-réseau de type « Point à point » n'a pas de plages.",
        );
        $this->assertDatabaseCount('subnets', 1);
    }

    public function test_changing_a_subnet_never_applies_the_breakdown(): void
    {
        $this->rule();

        $subnet = $this->subnet('10.20.10.0/24');

        $this->actingAs($this->editor())
            ->put(route('subnets.update', $subnet), ['network' => '10.20.10.0/24', 'status' => 'active', 'apply_breakdown' => true])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ip_ranges', 0);
    }

    // --- On an existing subnet ----------------------------------------------------

    public function test_the_page_of_a_subnet_offers_the_breakdown_only_when_it_applies(): void
    {
        $subnet = $this->subnet('10.20.10.0/24');

        $offered = fn (Subnet $subnet, string $role = 'editor'): mixed => $this->actingAs($this->{$role}())
            ->get(route('subnets.show', $subnet))->assertOk()->inertiaProps('breakdown');

        // Without a rule, or with a rule that has no range: nothing.
        $this->assertNull($offered($subnet));

        $rule = AddressingRule::factory()->create();

        $this->assertNull($offered($subnet));

        $rule->ranges()->createMany([
            ['kind' => 'reserved', 'start_offset' => 2, 'end_offset' => 20, 'name' => 'Infrastructure'],
            ['kind' => 'static', 'start_offset' => 21, 'end_offset' => 99, 'name' => 'Adresses fixes'],
            ['kind' => 'dhcp', 'start_offset' => 100, 'end_offset' => 200, 'name' => null],
        ]);

        // With ranges in the rule: the ranges that would be created.
        $this->assertSame(
            [
                ['kind' => 'reserved', 'kind_label' => 'Réservée', 'start_address' => '10.20.10.2', 'end_address' => '10.20.10.20', 'name' => 'Infrastructure', 'size' => 19, 'fits' => true],
                ['kind' => 'static', 'kind_label' => 'Fixe', 'start_address' => '10.20.10.21', 'end_address' => '10.20.10.99', 'name' => 'Adresses fixes', 'size' => 79, 'fits' => true],
                ['kind' => 'dhcp', 'kind_label' => 'DHCP', 'start_address' => '10.20.10.100', 'end_address' => '10.20.10.200', 'name' => null, 'size' => 101, 'fits' => true],
            ],
            $offered($subnet),
        );
        $this->assertNotNull($offered($subnet, 'admin'));

        // Not to a reader.
        $this->assertNull($offered($subnet, 'reader'));

        // Not for a kind that has no ranges.
        $link = Subnet::factory()->cidr('10.255.0.0/31')->kind(SubnetKind::PointToPoint)->create();

        $this->assertNull($offered($link));

        // A subnet too small is offered it, with what does not fit marked:
        // the window says why it cannot be applied.
        $small = $this->subnet('10.20.11.0/27');

        $this->assertSame([true, false, false], array_column($offered($small), 'fits'));

        // Not once the subnet has a range.
        IpRange::factory()->between('10.20.10.240', '10.20.10.250')->static()->create(['subnet_id' => $subnet->id]);

        $this->assertNull($offered($subnet));
    }

    public function test_the_breakdown_is_applied_to_an_existing_subnet_and_each_range_is_logged(): void
    {
        $editor = $this->editor();

        $this->rule();

        $subnet = $this->subnet('10.20.10.0/24', ['gateway' => '10.20.10.1']);
        // A static address is fine in the static range, a reservation in
        // the DHCP range.
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.20.10.50']);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.20.10.150', 'assignment' => 'dhcp_reservation']);

        $this->actingAs($editor)
            ->post(route('subnets.breakdown.store', $subnet))
            ->assertRedirect(route('subnets.show', $subnet))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '3 plages créées.');

        $this->assertSame(self::USUAL, $this->rangesOf($subnet));
        $this->assertSame(3, $this->countEntries(ActivityEvent::IpRangeCreated));

        $logged = $this->lastEntry(ActivityEvent::IpRangeCreated);

        $this->assertTrue($editor->is($logged->causer));
        $this->assertSame(
            ['subnet' => '10.20.10.0/24', 'kind' => 'dhcp', 'start_address' => '10.20.10.100', 'end_address' => '10.20.10.200', 'name' => null, 'description' => null],
            $logged->attribute_changes?->toArray()['attributes'],
        );

        // Asked again: the subnet has ranges now.
        $this->assertRefused(
            $this->actingAs($editor)->post(route('subnets.breakdown.store', $subnet)),
            'breakdown',
            "Ce sous-réseau a déjà des plages : le découpage type ne s'applique qu'à un sous-réseau qui n'en a aucune.",
        );
        $this->assertDatabaseCount('ip_ranges', 3);
    }

    public function test_a_dhcp_range_over_the_gateway_or_a_static_address_refuses_the_whole_breakdown(): void
    {
        $editor = $this->editor();

        $this->rule();

        // A static address where the DHCP range would be.
        $subnet = $this->subnet('10.20.10.0/24');
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.20.10.120']);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.20.10.180']);

        $this->assertRefused(
            $this->actingAs($editor)->post(route('subnets.breakdown.store', $subnet)),
            'breakdown',
            "La plage 10.20.10.100 – 10.20.10.200 ne peut pas être créée. Une plage DHCP ne peut pas contenir d'adresses fixes : 10.20.10.120, 10.20.10.180.",
        );

        // The two ranges before it were not kept.
        $this->assertDatabaseCount('ip_ranges', 0);
        $this->assertSame(0, $this->countEntries(ActivityEvent::IpRangeCreated));

        // The gateway where the DHCP range would be.
        $other = $this->subnet('10.20.11.0/24', ['gateway' => '10.20.11.200']);

        $this->assertRefused(
            $this->actingAs($editor)->post(route('subnets.breakdown.store', $other)),
            'breakdown',
            'La plage 10.20.11.100 – 10.20.11.200 ne peut pas être créée. Une plage DHCP ne peut pas contenir la passerelle (10.20.11.200).',
        );
        $this->assertDatabaseCount('ip_ranges', 0);
    }

    public function test_the_breakdown_is_refused_where_it_does_not_apply(): void
    {
        $editor = $this->editor();
        $subnet = $this->subnet('10.20.10.0/24');

        $this->assertRefused(
            $this->actingAs($editor)->post(route('subnets.breakdown.store', $subnet)),
            'breakdown',
            "La règle d'adressage n'a pas de découpage type à appliquer.",
        );

        $this->rule();

        $small = $this->subnet('10.20.11.0/27');
        $link = Subnet::factory()->cidr('10.255.0.0/31')->kind(SubnetKind::PointToPoint)->create();

        $this->assertRefused(
            $this->actingAs($editor)->post(route('subnets.breakdown.store', $small)),
            'breakdown',
            'Le découpage type ne tient pas dans 10.20.11.0/27 : la plage 10.20.11.21 – 10.20.11.99 dépasse ses adresses utilisables (10.20.11.1 – 10.20.11.30).',
        );
        $this->assertRefused(
            $this->actingAs($editor)->post(route('subnets.breakdown.store', $link)),
            'breakdown',
            "Un sous-réseau de type « Point à point » n'a pas de plages.",
        );

        $this->assertDatabaseCount('ip_ranges', 0);
    }

    public function test_the_breakdown_follows_the_rule_in_force(): void
    {
        // The gateway last: the DHCP range may reach the first addresses.
        $rule = AddressingRule::factory()->gateway(GatewayPosition::Last)->create();
        $rule->ranges()->create(['kind' => 'dhcp', 'start_offset' => 1, 'end_offset' => 100, 'name' => 'Postes']);

        $subnet = $this->subnet('10.20.10.0/24', ['gateway' => '10.20.10.254']);

        $this->actingAs($this->editor())
            ->post(route('subnets.breakdown.store', $subnet))
            ->assertInertiaFlash('toast.message', '1 plage créée.');

        $this->assertSame(['dhcp 10.20.10.1 – 10.20.10.100 Postes'], $this->rangesOf($subnet));
    }

    public function test_a_reader_cannot_apply_the_breakdown(): void
    {
        $this->rule();

        $subnet = $this->subnet('10.20.10.0/24');

        $this->post(route('subnets.breakdown.store', $subnet))->assertRedirect(route('login'));
        $this->actingAs($this->reader())->post(route('subnets.breakdown.store', $subnet))->assertForbidden();

        $this->assertDatabaseCount('ip_ranges', 0);
    }

    // --- What the forms get -------------------------------------------------------

    public function test_the_forms_of_a_vlan_and_of_a_subnet_get_what_the_rule_works_out(): void
    {
        $editor = $this->editor();
        $vlan = Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 10, 'name' => 'Bureautique']);

        // Without a rule: nothing to propose.
        $this->actingAs($editor)->get(route('vlans.create'))->assertInertia(fn (Assert $page) => $page->where('rule', null));
        $this->actingAs($editor)->get(route('subnets.create'))->assertInertia(fn (Assert $page) => $page->where('rule', null));

        $this->rule();

        $this->actingAs($editor)
            ->get(route('vlans.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rule.network', '10.0.0.0')
                ->where('rule.site_prefix_length', 16)
                ->where('rule.vlan_prefix_length', 24)
                ->where('rule.gateway_position', 'first')
                ->has('rule.ranges', 3)
                // The number of each site: the rule numbers a subnet by it.
                ->where('sites', [['id' => $this->site->id, 'name' => 'Atelier', 'number' => 20]]));

        $this->actingAs($editor)
            ->get(route('subnets.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('rule.ranges', 3)
                ->where('vlans', [[
                    'id' => $vlan->id, 'label' => '10 — Bureautique', 'vid' => 10,
                    'site' => 'Atelier', 'site_id' => $this->site->id, 'site_number' => 20,
                ]]));

        // What already exists is not proposed anything.
        $this->actingAs($editor)->get(route('vlans.edit', $vlan))->assertInertia(fn (Assert $page) => $page->where('rule', null));
        $this->actingAs($editor)
            ->get(route('subnets.edit', $this->subnet('10.20.10.0/24')))
            ->assertInertia(fn (Assert $page) => $page->where('rule', null));
    }
}
