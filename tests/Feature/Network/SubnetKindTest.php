<?php

// torsade/tests/Feature/Network/SubnetKindTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\SubnetKind;
use App\Models\IpRange;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\Vlan;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The kind of a subnet and its site. A link between two routers or the
 * address of a router is not a local network: each kind says what it
 * accepts, and a subnet carried by a VLAN is on the site of that VLAN.
 */
class SubnetKindTest extends NetworkTestCase
{
    private Site $site;

    private Vlan $vlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create(['name' => 'Head office']);
        $this->vlan = Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 10, 'name' => 'Offices']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(string $cidr, string $kind, array $overrides = []): array
    {
        return ['network' => $cidr, 'kind' => $kind, 'status' => 'active', ...$overrides];
    }

    // --- What each kind accepts -----------------------------------------------

    public function test_the_five_kinds_and_what_each_one_accepts(): void
    {
        $this->assertSame(
            ['segment', 'point_to_point', 'loopback', 'tunnel', 'external'],
            array_column(SubnetKind::cases(), 'value'),
        );

        $accepts = fn (SubnetKind $kind): array => [
            $kind->allowsVlan(), $kind->prefixLengths(), $kind->allowsGateway(), $kind->allowsRanges(),
        ];

        $any = range(1, 32);

        $this->assertSame([true, $any, true, true], $accepts(SubnetKind::Segment));
        $this->assertSame([true, [30, 31], false, false], $accepts(SubnetKind::PointToPoint));
        $this->assertSame([false, [32], false, false], $accepts(SubnetKind::Loopback));
        $this->assertSame([false, $any, true, true], $accepts(SubnetKind::Tunnel));
        $this->assertSame([true, $any, true, true], $accepts(SubnetKind::External));

        $this->assertSame(
            ['Segment', 'Point à point', 'Loopback', 'Tunnel', 'Externe'],
            array_column(SubnetKind::options(), 'label'),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function accepted(): array
    {
        return [
            'a segment of any size' => ['192.168.100.0/24', 'segment', ['gateway' => '192.168.100.254']],
            'a segment in a /31' => ['192.168.100.0/31', 'segment', ['gateway' => '192.168.100.0']],
            'a segment in a /32' => ['192.168.100.9/32', 'segment', []],
            'a point-to-point link in a /31' => ['10.255.0.0/31', 'point_to_point', []],
            'a point-to-point link in a /30' => ['10.255.0.4/30', 'point_to_point', []],
            'a loopback' => ['10.255.255.1/32', 'loopback', []],
            'a tunnel with a gateway' => ['172.20.0.0/24', 'tunnel', ['gateway' => '172.20.0.1']],
            'a tunnel in a /30' => ['172.20.1.0/30', 'tunnel', []],
            'an external subnet with a gateway' => ['203.0.113.0/29', 'external', ['gateway' => '203.0.113.1']],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('accepted')]
    public function test_a_subnet_that_fits_its_kind_is_created(string $cidr, string $kind, array $overrides): void
    {
        $this->actingAs($this->editor())
            ->post(route('subnets.store'), $this->payload($cidr, $kind, $overrides))
            ->assertSessionHasNoErrors();

        $subnet = Subnet::query()->sole();

        $this->assertSame($cidr, $subnet->cidr());
        $this->assertSame($kind, $subnet->kind->value);
        $this->assertSame($kind, $this->lastEntry(ActivityEvent::SubnetCreated)->attribute_changes?->toArray()['attributes']['kind']);
    }

    public function test_a_subnet_whose_kind_is_not_given_is_a_segment(): void
    {
        $this->actingAs($this->editor())
            ->post(route('subnets.store'), ['network' => '192.168.100.0/24', 'status' => 'active'])
            ->assertSessionHasNoErrors();

        $this->assertSame(SubnetKind::Segment, Subnet::query()->sole()->kind);

        $this->assertRefused(
            $this->actingAs($this->editor())->post(route('subnets.store'), $this->payload('192.168.101.0/24', 'bridge')),
            'kind',
        );
    }

    public function test_a_vlan_carries_a_segment_a_point_to_point_link_or_an_external_subnet(): void
    {
        $editor = $this->editor();

        foreach (['segment' => '192.168.100.0/24', 'point_to_point' => '10.255.0.0/31', 'external' => '203.0.113.0/29'] as $kind => $cidr) {
            $vlan = Vlan::factory()->create(['site_id' => $this->site->id]);

            $this->actingAs($editor)
                ->post(route('subnets.store'), $this->payload($cidr, $kind, ['vlan_id' => $vlan->id]))
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('subnets', 3);
    }

    public function test_a_loopback_and_a_tunnel_are_never_carried_by_a_vlan(): void
    {
        $editor = $this->editor();

        $this->assertRefused(
            $this->actingAs($editor)->post(route('subnets.store'), $this->payload('10.255.255.1/32', 'loopback', ['vlan_id' => $this->vlan->id])),
            'vlan_id',
            "Un sous-réseau de type « Loopback » n'est pas porté par un VLAN.",
        );
        $this->assertRefused(
            $this->actingAs($editor)->post(route('subnets.store'), $this->payload('172.20.0.0/24', 'tunnel', ['vlan_id' => $this->vlan->id])),
            'vlan_id',
            "Un sous-réseau de type « Tunnel » n'est pas porté par un VLAN.",
        );

        $this->assertDatabaseCount('subnets', 0);
    }

    public function test_a_point_to_point_link_is_a_slash_30_or_31_and_a_loopback_a_slash_32(): void
    {
        $editor = $this->editor();

        foreach (['10.255.0.0/24', '10.255.0.0/29', '10.255.0.1/32'] as $cidr) {
            $this->assertRefused(
                $this->actingAs($editor)->post(route('subnets.store'), $this->payload($cidr, 'point_to_point')),
                'prefix_length',
                "Le préfixe d'un sous-réseau de type « Point à point » est /30 ou /31.",
            );
        }

        foreach (['10.255.255.0/31', '10.255.255.0/24'] as $cidr) {
            $this->assertRefused(
                $this->actingAs($editor)->post(route('subnets.store'), $this->payload($cidr, 'loopback')),
                'prefix_length',
                "Le préfixe d'un sous-réseau de type « Loopback » est /32.",
            );
        }

        $this->assertDatabaseCount('subnets', 0);
    }

    public function test_a_point_to_point_link_and_a_loopback_have_no_gateway(): void
    {
        $editor = $this->editor();

        $this->assertRefused(
            $this->actingAs($editor)->post(route('subnets.store'), $this->payload('10.255.0.0/31', 'point_to_point', ['gateway' => '10.255.0.0'])),
            'gateway',
            "Un sous-réseau de type « Point à point » n'a pas de passerelle.",
        );
        $this->assertRefused(
            $this->actingAs($editor)->post(route('subnets.store'), $this->payload('10.255.255.1/32', 'loopback', ['gateway' => '10.255.255.1'])),
            'gateway',
            "Un sous-réseau de type « Loopback » n'a pas de passerelle.",
        );

        // Whatever the gateway: it is the kind that refuses it, not where
        // the address lies.
        $this->assertRefused(
            $this->actingAs($editor)->post(route('subnets.store'), $this->payload('10.255.0.0/30', 'point_to_point', ['gateway' => '192.168.1.1'])),
            'gateway',
            "Un sous-réseau de type « Point à point » n'a pas de passerelle.",
        );

        $this->assertDatabaseCount('subnets', 0);
    }

    public function test_every_refusal_of_a_new_subnet_is_told_on_its_field(): void
    {
        $response = $this->actingAs($this->editor())->post(route('subnets.store'), $this->payload('10.255.255.0/24', 'loopback', [
            'vlan_id' => $this->vlan->id,
            'gateway' => '10.255.255.1',
        ]));

        $response->assertRedirect();

        $this->assertEqualsCanonicalizing(['vlan_id', 'prefix_length', 'gateway'], $this->errorFields());
    }

    public function test_a_point_to_point_link_and_a_loopback_are_not_cut_into_ranges(): void
    {
        $editor = $this->editor();

        $link = Subnet::factory()->cidr('10.255.0.0/30')->kind(SubnetKind::PointToPoint)->create();
        $loopback = Subnet::factory()->cidr('10.255.255.1/32')->kind(SubnetKind::Loopback)->create();

        $this->assertRefused(
            $this->actingAs($editor)->post(route('ip-ranges.store', $link), ['kind' => 'static', 'start_address' => '10.255.0.1', 'end_address' => '10.255.0.2']),
            'start_address',
            "Un sous-réseau de type « Point à point » n'a pas de plages.",
        );
        $this->assertRefused(
            $this->actingAs($editor)->post(route('ip-ranges.store', $loopback), ['kind' => 'reserved', 'start_address' => '10.255.255.1', 'end_address' => '10.255.255.1']),
            'start_address',
            "Un sous-réseau de type « Loopback » n'a pas de plages.",
        );

        $this->assertDatabaseCount('ip_ranges', 0);

        // Their addresses are documented like any other.
        $this->actingAs($editor)
            ->post(route('ip-addresses.store', $link), ['address' => '10.255.0.1', 'assignment' => 'static', 'status' => 'active'])
            ->assertSessionHasNoErrors();
        $this->actingAs($editor)
            ->post(route('ip-addresses.store', $loopback), ['address' => '10.255.255.1', 'assignment' => 'static', 'status' => 'active'])
            ->assertSessionHasNoErrors();
    }

    // --- Changing the kind ------------------------------------------------------

    public function test_a_change_of_kind_is_refused_with_everything_that_stands_in_the_way(): void
    {
        $editor = $this->editor();

        $subnet = Subnet::factory()->cidr('192.168.100.0/24')->create([
            'vlan_id' => $this->vlan->id,
            'gateway' => '192.168.100.254',
        ]);
        IpRange::factory()->between('192.168.100.100', '192.168.100.150')->create(['subnet_id' => $subnet->id]);
        IpRange::factory()->static()->between('192.168.100.10', '192.168.100.50')->create(['subnet_id' => $subnet->id]);

        $current = ['vlan_id' => $this->vlan->id, 'gateway' => '192.168.100.254'];

        // To a loopback: its VLAN, its size, its gateway and its ranges.
        $response = $this->actingAs($editor)->put(route('subnets.update', $subnet), $this->payload('192.168.100.0/24', 'loopback', $current));

        $this->assertRefused(
            $response,
            'kind',
            'Ce sous-réseau ne peut pas devenir « Loopback » : il est porté par un VLAN, son préfixe est /24 (attendu : /32), '
            .'il a une passerelle (192.168.100.254), il a 2 plages.',
        );
        // One message, on the kind: it is the change that is refused.
        $this->assertSame(['kind'], $this->errorFields());

        // To a point-to-point link: a VLAN may carry one.
        $this->assertRefused(
            $this->actingAs($editor)->put(route('subnets.update', $subnet), $this->payload('192.168.100.0/24', 'point_to_point', $current)),
            'kind',
            'Ce sous-réseau ne peut pas devenir « Point à point » : son préfixe est /24 (attendu : /30 ou /31), '
            .'il a une passerelle (192.168.100.254), il a 2 plages.',
        );

        // To a tunnel: only its VLAN is in the way.
        $this->assertRefused(
            $this->actingAs($editor)->put(route('subnets.update', $subnet), $this->payload('192.168.100.0/24', 'tunnel', $current)),
            'kind',
            'Ce sous-réseau ne peut pas devenir « Tunnel » : il est porté par un VLAN.',
        );

        $this->assertSame(SubnetKind::Segment, $subnet->refresh()->kind);
        $this->assertSame(0, $this->countEntries(ActivityEvent::SubnetUpdated));
    }

    public function test_what_the_same_request_removes_no_longer_stands_in_the_way(): void
    {
        $editor = $this->editor();
        $subnet = Subnet::factory()->cidr('10.255.0.0/30')->create(['vlan_id' => $this->vlan->id, 'gateway' => '10.255.0.1']);
        IpRange::factory()->static()->between('10.255.0.1', '10.255.0.2')->create(['subnet_id' => $subnet->id]);

        // The gateway is emptied in the same request: only the range is left.
        $this->assertRefused(
            $this->actingAs($editor)->put(route('subnets.update', $subnet), $this->payload('10.255.0.0/30', 'point_to_point', ['vlan_id' => $this->vlan->id])),
            'kind',
            'Ce sous-réseau ne peut pas devenir « Point à point » : il a une plage.',
        );

        IpRange::query()->delete();

        $this->actingAs($editor)
            ->put(route('subnets.update', $subnet), $this->payload('10.255.0.0/30', 'point_to_point', ['vlan_id' => $this->vlan->id]))
            ->assertSessionHasNoErrors();

        $subnet->refresh();

        $this->assertSame(SubnetKind::PointToPoint, $subnet->kind);
        $this->assertNull($subnet->gateway);

        $entry = $this->lastEntry(ActivityEvent::SubnetUpdated)->attribute_changes?->toArray();

        $this->assertSame(['kind' => 'segment', 'gateway' => '10.255.0.1'], $entry['old']);
        $this->assertSame(['kind' => 'point_to_point', 'gateway' => null], $entry['attributes']);
    }

    public function test_a_subnet_changes_kind_freely_when_nothing_stands_in_the_way(): void
    {
        $editor = $this->editor();
        $subnet = Subnet::factory()->cidr('203.0.113.0/29')->create(['gateway' => '203.0.113.1']);
        IpRange::factory()->between('203.0.113.4', '203.0.113.6')->create(['subnet_id' => $subnet->id]);

        foreach (['external', 'tunnel', 'segment'] as $kind) {
            $this->actingAs($editor)
                ->put(route('subnets.update', $subnet), $this->payload('203.0.113.0/29', $kind, ['gateway' => '203.0.113.1']))
                ->assertSessionHasNoErrors();

            $this->assertSame($kind, $subnet->refresh()->kind->value);
        }

        // An update that says nothing of the kind keeps it.
        $subnet->update(['kind' => SubnetKind::External]);

        $this->actingAs($editor)
            ->put(route('subnets.update', $subnet), ['network' => '203.0.113.0/29', 'gateway' => '203.0.113.1', 'status' => 'active'])
            ->assertSessionHasNoErrors();

        $this->assertSame(SubnetKind::External, $subnet->refresh()->kind);
    }

    public function test_a_subnet_of_a_fixed_size_cannot_be_resized_out_of_its_kind(): void
    {
        $subnet = Subnet::factory()->cidr('10.255.0.0/31')->kind(SubnetKind::PointToPoint)->create();

        $this->assertRefused(
            $this->actingAs($this->editor())->put(route('subnets.update', $subnet), $this->payload('10.255.0.0/29', 'point_to_point')),
            'prefix_length',
            "Le préfixe d'un sous-réseau de type « Point à point » est /30 ou /31.",
        );

        $this->actingAs($this->editor())
            ->put(route('subnets.update', $subnet), $this->payload('10.255.0.0/30', 'point_to_point'))
            ->assertSessionHasNoErrors();

        $this->assertSame(30, $subnet->refresh()->prefix_length);
    }

    // --- The site of a subnet -----------------------------------------------------

    public function test_a_subnet_carried_by_a_vlan_is_on_the_site_of_that_vlan(): void
    {
        $editor = $this->editor();
        $elsewhere = Site::factory()->create(['name' => 'Workshop']);

        // Whatever is asked for: the site of the VLAN is imposed.
        $this->actingAs($editor)
            ->post(route('subnets.store'), $this->payload('192.168.100.0/24', 'segment', ['vlan_id' => $this->vlan->id, 'site_id' => $elsewhere->id]))
            ->assertSessionHasNoErrors();

        $subnet = Subnet::query()->sole();

        $this->assertSame($this->site->id, $subnet->site_id);
        $this->assertSame('Head office', $this->lastEntry(ActivityEvent::SubnetCreated)->attribute_changes?->toArray()['attributes']['site']);

        $this->actingAs($editor)
            ->put(route('subnets.update', $subnet), $this->payload('192.168.100.0/24', 'segment', ['vlan_id' => $this->vlan->id, 'site_id' => null]))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->site->id, $subnet->refresh()->site_id);

        // The subnet created along with a VLAN, too.
        $this->actingAs($editor)->post(route('vlans.store'), [
            'site_id' => $elsewhere->id, 'vid' => 20, 'name' => 'Machines', 'status' => 'active',
            'with_subnet' => true, 'subnet' => ['network' => '10.20.20.0/24'],
        ])->assertSessionHasNoErrors();

        $this->assertSame($elsewhere->id, Subnet::query()->where('prefix_length', 24)->whereKeyNot($subnet->id)->sole()->site_id);
    }

    public function test_a_subnet_without_a_vlan_has_the_site_it_is_given_or_none(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)
            ->post(route('subnets.store'), $this->payload('203.0.113.0/29', 'external', ['site_id' => $this->site->id]))
            ->assertSessionHasNoErrors();
        $this->actingAs($editor)
            ->post(route('subnets.store'), $this->payload('172.20.0.0/24', 'tunnel'))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->site->id, Subnet::query()->where('kind', 'external')->sole()->site_id);
        $this->assertNull(Subnet::query()->where('kind', 'tunnel')->sole()->site_id);

        $this->assertRefused(
            $this->actingAs($editor)->post(route('subnets.store'), $this->payload('172.20.1.0/24', 'tunnel', ['site_id' => 999999999])),
            'site_id',
        );
    }

    public function test_a_subnet_detached_from_its_vlan_keeps_the_site_it_is_given(): void
    {
        $editor = $this->editor();
        $subnet = Subnet::factory()->cidr('192.168.100.0/24')->create(['vlan_id' => $this->vlan->id]);

        $this->actingAs($editor)
            ->put(route('subnets.update', $subnet), $this->payload('192.168.100.0/24', 'segment', ['vlan_id' => null, 'site_id' => $this->site->id]))
            ->assertSessionHasNoErrors();

        $subnet->refresh();

        $this->assertNull($subnet->vlan_id);
        $this->assertSame($this->site->id, $subnet->site_id);
    }

    public function test_when_a_vlan_moves_to_another_site_its_subnet_follows_and_both_changes_are_logged(): void
    {
        $editor = $this->editor();
        $workshop = Site::factory()->create(['name' => 'Workshop']);
        $subnet = Subnet::factory()->cidr('192.168.100.0/24')->create(['vlan_id' => $this->vlan->id]);

        $this->assertSame($this->site->id, $subnet->site_id);

        $this->actingAs($editor)
            ->put(route('vlans.update', $this->vlan), ['site_id' => $workshop->id, 'vid' => 10, 'name' => 'Offices', 'status' => 'active'])
            ->assertSessionHasNoErrors();

        $this->assertSame($workshop->id, $this->vlan->refresh()->site_id);
        $this->assertSame($workshop->id, $subnet->refresh()->site_id);

        $vlanEntry = $this->lastEntry(ActivityEvent::VlanUpdated)->attribute_changes?->toArray();

        $this->assertSame(['site' => 'Head office'], $vlanEntry['old']);
        $this->assertSame(['site' => 'Workshop'], $vlanEntry['attributes']);

        $subnetEntry = $this->lastEntry(ActivityEvent::SubnetUpdated);

        $this->assertTrue($subnet->is($subnetEntry->subject));
        $this->assertTrue($editor->is($subnetEntry->causer));
        $this->assertSame(
            [
                'old' => ['vlan' => 'VLAN 10 — Offices (Head office)', 'site' => 'Head office'],
                'attributes' => ['vlan' => 'VLAN 10 — Offices (Workshop)', 'site' => 'Workshop'],
            ],
            $subnetEntry->attribute_changes?->toArray(),
        );
    }

    public function test_a_vlan_that_stays_on_its_site_or_has_no_subnet_moves_nothing(): void
    {
        $editor = $this->editor();
        $workshop = Site::factory()->create(['name' => 'Workshop']);
        Subnet::factory()->cidr('192.168.100.0/24')->create(['vlan_id' => $this->vlan->id]);

        // Renamed, on the same site.
        $this->actingAs($editor)
            ->put(route('vlans.update', $this->vlan), ['site_id' => $this->site->id, 'vid' => 10, 'name' => 'Bureaux', 'status' => 'active'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->countEntries(ActivityEvent::SubnetUpdated));

        // Moved, without a subnet.
        $bare = Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 20, 'name' => 'Servers']);

        $this->actingAs($editor)
            ->put(route('vlans.update', $bare), ['site_id' => $workshop->id, 'vid' => 20, 'name' => 'Servers', 'status' => 'active'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->countEntries(ActivityEvent::SubnetUpdated));
    }

    public function test_a_refused_move_of_a_vlan_leaves_its_subnet_where_it_was(): void
    {
        $workshop = Site::factory()->create(['name' => 'Workshop']);
        Vlan::factory()->create(['site_id' => $workshop->id, 'vid' => 10, 'name' => 'Machines']);
        $subnet = Subnet::factory()->cidr('192.168.100.0/24')->create(['vlan_id' => $this->vlan->id]);

        // The number is taken on the other site.
        $this->assertRefused(
            $this->actingAs($this->editor())->put(route('vlans.update', $this->vlan), [
                'site_id' => $workshop->id, 'vid' => 10, 'name' => 'Offices', 'status' => 'active',
            ]),
            'vid',
        );

        $this->assertSame($this->site->id, $subnet->refresh()->site_id);
        $this->assertSame(0, $this->countEntries(ActivityEvent::SubnetUpdated));
    }

    // --- The form and the list -------------------------------------------------------

    public function test_the_form_offers_the_kinds_the_sites_and_the_vlans_with_their_site(): void
    {
        $editor = $this->editor();
        $workshop = Site::factory()->create(['name' => 'Workshop']);

        $this->actingAs($editor)
            ->get(route('subnets.create', ['site' => $workshop->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('subnets/Form')
                ->where('site', $workshop->id)
                ->where('vlan', null)
                ->where('sites', [
                    ['id' => $this->site->id, 'name' => 'Head office'],
                    ['id' => $workshop->id, 'name' => 'Workshop'],
                ])
                ->has('kinds', 5)
                ->where('kinds.1', [
                    'value' => 'point_to_point',
                    'label' => 'Point à point',
                    'allows_vlan' => true,
                    'allows_gateway' => false,
                    'allows_ranges' => false,
                    'prefix_lengths' => [30, 31],
                ])
                ->where('kinds.2.allows_vlan', false)
                ->where('kinds.2.prefix_lengths', [32])
                // The site a VLAN would give the subnet.
                ->where('vlans.0', ['id' => $this->vlan->id, 'label' => '10 — Offices', 'vid' => 10, 'site' => 'Head office', 'site_id' => $this->site->id, 'site_number' => null])
            );

        $this->assertRefused($this->actingAs($editor)->get(route('subnets.create', ['site' => 999999999])), 'site');

        $subnet = Subnet::factory()->cidr('203.0.113.0/29')->kind(SubnetKind::External)->create(['site_id' => $workshop->id]);

        $this->actingAs($editor)
            ->get(route('subnets.edit', $subnet))
            ->assertInertia(fn (Assert $page) => $page
                ->where('subnet.kind', 'external')
                ->where('subnet.site_id', $workshop->id)
                ->where('subnet.vlan_id', null));
    }

    public function test_the_list_shows_the_site_and_the_kind_and_can_be_filtered_by_them(): void
    {
        $reader = $this->reader();
        $workshop = Site::factory()->create(['name' => 'Workshop']);

        Subnet::factory()->cidr('192.168.100.0/24')->create(['vlan_id' => $this->vlan->id]);
        Subnet::factory()->cidr('203.0.113.0/29')->kind(SubnetKind::External)->create(['site_id' => $workshop->id]);
        Subnet::factory()->cidr('10.255.0.0/31')->kind(SubnetKind::PointToPoint)->create(['site_id' => $workshop->id]);
        Subnet::factory()->cidr('10.255.255.1/32')->kind(SubnetKind::Loopback)->create();

        $this->actingAs($reader)
            ->get(route('subnets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('subnets', 4)
                ->where('filters', ['site' => '', 'kind' => ''])
                ->has('sites', 2)
                ->has('kinds', 5)
                ->where('subnets.0.cidr', '10.255.0.0/31')
                ->where('subnets.0.kind', 'point_to_point')
                ->where('subnets.0.kind_label', 'Point à point')
                ->where('subnets.0.site', ['id' => $workshop->id, 'name' => 'Workshop'])
                ->where('subnets.1.kind_label', 'Loopback')
                ->where('subnets.1.site', null)
                ->where('subnets.2.cidr', '192.168.100.0/24')
                ->where('subnets.2.site.name', 'Head office')
                ->where('subnets.3.kind_label', 'Externe')
            );

        $cidrs = fn (array $query): array => array_column(
            (array) $this->actingAs($reader)->get(route('subnets.index', $query))->inertiaProps('subnets'),
            'cidr',
        );

        $this->assertSame(['10.255.0.0/31', '203.0.113.0/29'], $cidrs(['site' => $workshop->id]));
        $this->assertSame(['192.168.100.0/24'], $cidrs(['site' => $this->site->id]));
        // The subnets that belong to no site.
        $this->assertSame(['10.255.255.1/32'], $cidrs(['site' => 'none']));
        $this->assertSame(['203.0.113.0/29'], $cidrs(['kind' => 'external']));
        $this->assertSame(['10.255.0.0/31'], $cidrs(['site' => $workshop->id, 'kind' => 'point_to_point']));
        $this->assertSame([], $cidrs(['site' => $this->site->id, 'kind' => 'loopback']));

        $this->actingAs($reader)
            ->get(route('subnets.index', ['site' => 'none', 'kind' => 'loopback']))
            ->assertInertia(fn (Assert $page) => $page->where('filters', ['site' => 'none', 'kind' => 'loopback']));

        $this->assertRefused($this->actingAs($reader)->get(route('subnets.index', ['site' => 999999999])), 'site');
        $this->assertRefused($this->actingAs($reader)->get(route('subnets.index', ['site' => 'all'])), 'site');
        $this->assertRefused($this->actingAs($reader)->get(route('subnets.index', ['kind' => 'bridge'])), 'kind');
    }

    public function test_the_page_of_a_subnet_says_what_its_kind_excludes(): void
    {
        $reader = $this->reader();

        $link = Subnet::factory()->cidr('10.255.0.0/31')->kind(SubnetKind::PointToPoint)->create(['site_id' => $this->site->id]);
        $segment = Subnet::factory()->cidr('192.168.100.0/24')->create(['vlan_id' => $this->vlan->id]);

        $this->actingAs($reader)
            ->get(route('subnets.show', $link))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subnet.kind', 'point_to_point')
                ->where('subnet.kind_label', 'Point à point')
                ->where('subnet.site', ['id' => $this->site->id, 'name' => 'Head office'])
                ->where('allowsGateway', false)
                ->where('allowsRanges', false)
                ->where('ipRanges', []));

        $this->actingAs($reader)
            ->get(route('subnets.show', $segment))
            ->assertInertia(fn (Assert $page) => $page
                ->where('subnet.kind_label', 'Segment')
                ->where('subnet.site.name', 'Head office')
                ->where('allowsGateway', true)
                ->where('allowsRanges', true));
    }
}
