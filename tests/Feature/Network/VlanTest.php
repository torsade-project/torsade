<?php

// torsade/tests/Feature/Network/VlanTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\Status;
use App\Models\IpAddress;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\Vlan;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;

class VlanTest extends NetworkTestCase
{
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create(['name' => 'Head office']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'site_id' => $this->site->id,
            'vid' => 10,
            'name' => 'Offices',
            'description' => 'Workstations of the offices',
            'status' => 'active',
            ...$overrides,
        ];
    }

    // --- List ---------------------------------------------------------------

    public function test_the_list_is_sorted_by_vid_and_shows_site_subnet_and_occupancy(): void
    {
        $servers = Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 20, 'name' => 'Servers']);
        Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 5, 'name' => 'Management', 'status' => Status::Planned]);

        $subnet = Subnet::factory()->cidr('192.168.20.0/24')->create(['vlan_id' => $servers->id]);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.20.10']);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.20.11']);

        $this->actingAs($this->reader())
            ->get(route('vlans.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('vlans/Index')
                ->has('vlans', 2)
                ->where('vlans.0.vid', 5)
                ->where('vlans.0.name', 'Management')
                ->where('vlans.0.site.name', 'Head office')
                ->where('vlans.0.status', 'planned')
                ->where('vlans.0.status_label', 'Prévu')
                ->where('vlans.0.subnet', null)
                ->where('vlans.1.vid', 20)
                ->where('vlans.1.subnet.cidr', '192.168.20.0/24')
                ->where('vlans.1.subnet.usable_count', 254)
                ->where('vlans.1.subnet.documented_count', 2)
                ->has('sites', 1)
                ->has('statuses', 3));
    }

    public function test_the_list_can_be_filtered_by_site_and_by_status(): void
    {
        $workshop = Site::factory()->create(['name' => 'Workshop']);

        Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 10, 'name' => 'Offices']);
        Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 30, 'name' => 'Old', 'status' => Status::Retired]);
        Vlan::factory()->create(['site_id' => $workshop->id, 'vid' => 10, 'name' => 'Machines']);

        $reader = $this->reader();

        $this->actingAs($reader)
            ->get(route('vlans.index', ['site' => $workshop->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('vlans', 1)
                ->where('vlans.0.name', 'Machines')
                ->where('filters.site', (string) $workshop->id)
                ->where('filters.status', ''));

        $this->actingAs($reader)
            ->get(route('vlans.index', ['status' => 'retired']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('vlans', 1)
                ->where('vlans.0.name', 'Old')
                ->where('filters.status', 'retired'));

        $this->actingAs($reader)
            ->get(route('vlans.index', ['site' => $this->site->id, 'status' => 'active']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('vlans', 1)
                ->where('vlans.0.name', 'Offices'));
    }

    public function test_an_invalid_filter_is_refused(): void
    {
        $reader = $this->reader();

        $this->assertRefused($this->actingAs($reader)->get(route('vlans.index', ['site' => 999999999])), 'site');
        $this->assertRefused($this->actingAs($reader)->get(route('vlans.index', ['status' => 'unknown'])), 'status');
    }

    public function test_without_any_site_the_page_has_no_site_to_offer(): void
    {
        $this->site->delete();

        // The page then invites to create a site first.
        $this->actingAs($this->editor())
            ->get(route('vlans.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('sites', 0)->has('vlans', 0));
    }

    // --- Creation -----------------------------------------------------------

    public function test_the_form_offers_sites_statuses_and_prefix_lengths(): void
    {
        $this->actingAs($this->editor())
            ->get(route('vlans.create', ['site' => $this->site->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('vlans/Form')
                ->where('vlan', null)
                ->where('site', $this->site->id)
                ->has('sites', 1)
                ->has('statuses', 3)
                ->has('prefixes', 32)
                ->where('prefixes.23.value', 24)
                ->where('prefixes.23.label', '/24 — 255.255.255.0'));
    }

    public function test_the_form_refuses_an_unknown_site(): void
    {
        $this->assertRefused($this->actingAs($this->editor())->get(route('vlans.create', ['site' => 999999999])), 'site');
    }

    public function test_an_editor_creates_a_vlan(): void
    {
        $response = $this->actingAs($this->editor())->post(route('vlans.store'), $this->payload());

        $vlan = Vlan::query()->sole();

        $response->assertRedirect(route('vlans.show', $vlan))->assertSessionHasNoErrors();

        $this->assertSame($this->site->id, $vlan->site_id);
        $this->assertSame(10, $vlan->vid);
        $this->assertSame('Offices', $vlan->name);
        $this->assertSame('Workstations of the offices', $vlan->description);
        $this->assertSame(Status::Active, $vlan->status);
        $this->assertDatabaseCount('subnets', 0);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidVids(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            '4095, reserved' => [4095],
            'far above' => [70000],
            'not a number' => ['ten'],
            'decimal' => ['10.5'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidVids')]
    public function test_a_vid_outside_1_to_4094_is_refused(mixed $vid): void
    {
        $response = $this->actingAs($this->editor())->post(route('vlans.store'), $this->payload(['vid' => $vid]));

        $this->assertRefused($response, 'vid');
        $this->assertDatabaseCount('vlans', 0);
    }

    public function test_the_message_gives_the_bounds_of_the_vid(): void
    {
        $this->actingAs($this->editor())->post(route('vlans.store'), $this->payload(['vid' => 4095]));

        $this->assertSame('Le champ numéro de VLAN doit être compris entre 1 et 4094.', $this->error('vid'));
    }

    public function test_the_vids_1_and_4094_are_accepted(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->post(route('vlans.store'), $this->payload(['vid' => 1, 'name' => 'First']))->assertSessionHasNoErrors();
        $this->actingAs($editor)->post(route('vlans.store'), $this->payload(['vid' => 4094, 'name' => 'Last']))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('vlans', 2);
    }

    public function test_a_vid_already_used_in_the_site_is_refused(): void
    {
        Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 10, 'name' => 'Existing']);

        $response = $this->actingAs($this->editor())->post(route('vlans.store'), $this->payload(['vid' => 10, 'name' => 'Other']));

        $this->assertRefused($response, 'vid', 'Ce site a déjà un VLAN avec ce numéro.');
        $this->assertDatabaseCount('vlans', 1);
    }

    public function test_a_name_already_used_in_the_site_is_refused(): void
    {
        Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 11, 'name' => 'Offices']);

        $response = $this->actingAs($this->editor())->post(route('vlans.store'), $this->payload(['vid' => 10, 'name' => 'Offices']));

        $this->assertRefused($response, 'name', 'Ce site a déjà un VLAN avec ce nom.');
        $this->assertDatabaseCount('vlans', 1);
    }

    public function test_the_same_vid_and_name_are_accepted_on_another_site(): void
    {
        $workshop = Site::factory()->create(['name' => 'Workshop']);
        $editor = $this->editor();

        $this->actingAs($editor)->post(route('vlans.store'), $this->payload())->assertSessionHasNoErrors();
        $this->actingAs($editor)->post(route('vlans.store'), $this->payload(['site_id' => $workshop->id]))->assertSessionHasNoErrors();

        $this->assertSame(2, Vlan::query()->where('vid', 10)->where('name', 'Offices')->count());
    }

    public function test_a_vlan_needs_an_existing_site_a_name_and_a_status(): void
    {
        $editor = $this->editor();

        $this->assertRefused($this->actingAs($editor)->post(route('vlans.store'), $this->payload(['site_id' => 999999999])), 'site_id');
        $this->assertRefused($this->actingAs($editor)->post(route('vlans.store'), $this->payload(['site_id' => null])), 'site_id');
        $this->assertRefused($this->actingAs($editor)->post(route('vlans.store'), $this->payload(['name' => ''])), 'name');
        $this->assertRefused($this->actingAs($editor)->post(route('vlans.store'), $this->payload(['name' => str_repeat('a', 65)])), 'name');
        $this->assertRefused($this->actingAs($editor)->post(route('vlans.store'), $this->payload(['status' => 'broken'])), 'status');
        $this->assertRefused($this->actingAs($editor)->post(route('vlans.store'), $this->payload(['description' => str_repeat('a', 1001)])), 'description');

        $this->assertDatabaseCount('vlans', 0);
    }

    // --- A VLAN and its subnet in one action --------------------------------

    public function test_a_vlan_is_created_with_its_subnet_in_one_action(): void
    {
        $response = $this->actingAs($this->editor())->post(route('vlans.store'), $this->payload([
            'status' => 'planned',
            'with_subnet' => true,
            'subnet' => ['network' => '192.168.10.0', 'prefix_length' => 24, 'gateway' => '192.168.10.254'],
        ]));

        $response->assertSessionHasNoErrors();

        $vlan = Vlan::query()->sole();
        $subnet = Subnet::query()->sole();

        $this->assertSame($vlan->id, $subnet->vlan_id);
        $this->assertSame('192.168.10.0', $subnet->network);
        $this->assertSame(24, $subnet->prefix_length);
        $this->assertSame('192.168.10.254', $subnet->gateway);
        $this->assertSame(4, $subnet->ip_version);
        // The subnet starts with the status of its VLAN.
        $this->assertSame(Status::Planned, $subnet->status);

        $this->assertSame(1, $this->countEntries(ActivityEvent::VlanCreated));
        $this->assertSame(1, $this->countEntries(ActivityEvent::SubnetCreated));
    }

    public function test_the_subnet_of_a_new_vlan_can_be_typed_in_cidr_notation(): void
    {
        $this->actingAs($this->editor())->post(route('vlans.store'), $this->payload([
            'with_subnet' => true,
            // The prefix length typed with the address wins over the list.
            'subnet' => ['network' => '10.20.0.0/16', 'prefix_length' => 24, 'gateway' => null],
        ]))->assertSessionHasNoErrors();

        $subnet = Subnet::query()->sole();

        $this->assertSame('10.20.0.0/16', $subnet->cidr());
        $this->assertNull($subnet->gateway);
    }

    public function test_a_refused_subnet_leaves_no_vlan_behind(): void
    {
        $response = $this->actingAs($this->editor())->post(route('vlans.store'), $this->payload([
            'with_subnet' => true,
            // Host bits set: this is not a network address.
            'subnet' => ['network' => '192.168.10.7', 'prefix_length' => 24, 'gateway' => null],
        ]));

        $this->assertRefused(
            $response,
            'subnet.network',
            "192.168.10.7/24 n'est pas une adresse de réseau. Vouliez-vous dire 192.168.10.0/24 ?",
        );

        $this->assertDatabaseCount('vlans', 0);
        $this->assertDatabaseCount('subnets', 0);
        // Nothing was written to the log either: the whole action was undone.
        $this->assertSame(0, $this->countEntries(ActivityEvent::VlanCreated));
        $this->assertSame(0, $this->countEntries(ActivityEvent::SubnetCreated));
    }

    public function test_a_subnet_refused_for_its_gateway_or_an_overlap_leaves_no_vlan_behind(): void
    {
        $this->subnet('192.168.0.0/16');
        $editor = $this->editor();

        $overlapping = $this->actingAs($editor)->post(route('vlans.store'), $this->payload([
            'with_subnet' => true,
            'subnet' => ['network' => '192.168.10.0', 'prefix_length' => 24, 'gateway' => null],
        ]));

        $this->assertRefused($overlapping, 'subnet.network', 'Ce sous-réseau chevauche 192.168.0.0/16.');

        $badGateway = $this->actingAs($editor)->post(route('vlans.store'), $this->payload([
            'with_subnet' => true,
            'subnet' => ['network' => '10.0.10.0', 'prefix_length' => 24, 'gateway' => '10.0.11.1'],
        ]));

        $this->assertRefused($badGateway, 'subnet.gateway', 'La passerelle doit se trouver dans le sous-réseau.');

        $this->assertDatabaseCount('vlans', 0);
        $this->assertDatabaseCount('subnets', 1);
    }

    public function test_a_refused_vlan_leaves_no_subnet_behind(): void
    {
        Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 10, 'name' => 'Existing']);

        $response = $this->actingAs($this->editor())->post(route('vlans.store'), $this->payload([
            'with_subnet' => true,
            'subnet' => ['network' => '192.168.10.0', 'prefix_length' => 24, 'gateway' => null],
        ]));

        $this->assertRefused($response, 'vid');
        $this->assertDatabaseCount('vlans', 1);
        $this->assertDatabaseCount('subnets', 0);
    }

    public function test_the_subnet_section_is_checked_before_anything_is_saved(): void
    {
        $response = $this->actingAs($this->editor())->post(route('vlans.store'), $this->payload([
            'with_subnet' => true,
            'subnet' => ['network' => 'not an address', 'prefix_length' => 40, 'gateway' => 'fd00::1'],
        ]));

        $response->assertSessionHasErrors(['subnet.network', 'subnet.prefix_length', 'subnet.gateway']);

        $this->assertSame('Saisissez une adresse IPv4 valide, par exemple 192.168.1.10.', $this->error('subnet.network'));
        $this->assertSame(
            'Les adresses IPv6 ne sont pas encore prises en charge : saisissez une adresse IPv4.',
            $this->error('subnet.gateway'),
        );
        $this->assertDatabaseCount('vlans', 0);
    }

    public function test_the_subnet_section_is_ignored_when_it_is_not_ticked(): void
    {
        $this->actingAs($this->editor())->post(route('vlans.store'), $this->payload([
            'with_subnet' => false,
            // Left over in the form, and not even valid.
            'subnet' => ['network' => 'garbage', 'prefix_length' => 24, 'gateway' => ''],
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('vlans', 1);
        $this->assertDatabaseCount('subnets', 0);
    }

    // --- Page of a VLAN -----------------------------------------------------

    public function test_the_page_of_a_vlan_shows_its_subnet(): void
    {
        $vlan = Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 20, 'name' => 'Servers']);
        Subnet::factory()->cidr('192.168.20.0/24')->create(['vlan_id' => $vlan->id, 'gateway' => '192.168.20.1']);

        $this->actingAs($this->reader())
            ->get(route('vlans.show', $vlan))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('vlans/Show')
                ->where('vlan.vid', 20)
                ->where('vlan.name', 'Servers')
                ->where('vlan.site.name', 'Head office')
                ->where('vlan.subnet.cidr', '192.168.20.0/24')
                ->where('vlan.subnet.mask', '255.255.255.0')
                ->where('vlan.subnet.gateway', '192.168.20.1')
                ->where('vlan.subnet.documented_count', 0));
    }

    public function test_the_page_of_a_vlan_without_subnet_says_so(): void
    {
        $vlan = Vlan::factory()->create(['site_id' => $this->site->id]);

        // The page then offers to attach a subnet.
        $this->actingAs($this->reader())
            ->get(route('vlans.show', $vlan))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('vlan.subnet', null));
    }

    // --- Update -------------------------------------------------------------

    public function test_an_editor_updates_a_vlan(): void
    {
        $workshop = Site::factory()->create(['name' => 'Workshop']);
        $vlan = Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 10, 'name' => 'Offices']);

        $this->actingAs($this->editor())
            ->put(route('vlans.update', $vlan), [
                'site_id' => $workshop->id,
                'vid' => 15,
                'name' => 'Administration',
                'description' => null,
                'status' => 'retired',
            ])
            ->assertRedirect(route('vlans.show', $vlan))
            ->assertSessionHasNoErrors();

        $vlan->refresh();

        $this->assertSame($workshop->id, $vlan->site_id);
        $this->assertSame(15, $vlan->vid);
        $this->assertSame('Administration', $vlan->name);
        $this->assertNull($vlan->description);
        $this->assertSame(Status::Retired, $vlan->status);
    }

    public function test_a_vlan_keeps_its_own_vid_and_name_when_updated(): void
    {
        $vlan = Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 10, 'name' => 'Offices']);

        $this->actingAs($this->editor())
            ->put(route('vlans.update', $vlan), $this->payload(['description' => 'Only the description changes']))
            ->assertSessionHasNoErrors();
    }

    public function test_a_vlan_cannot_take_the_vid_or_the_name_of_another_one_of_its_site(): void
    {
        Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 20, 'name' => 'Servers']);
        $vlan = Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 10, 'name' => 'Offices']);
        $editor = $this->editor();

        $this->assertRefused($this->actingAs($editor)->put(route('vlans.update', $vlan), $this->payload(['vid' => 20])), 'vid');
        $this->assertRefused($this->actingAs($editor)->put(route('vlans.update', $vlan), $this->payload(['name' => 'Servers'])), 'name');
        $this->assertRefused($this->actingAs($editor)->put(route('vlans.update', $vlan), $this->payload(['vid' => 4095])), 'vid');

        $this->assertSame(10, $vlan->refresh()->vid);
        $this->assertSame('Offices', $vlan->name);
    }

    public function test_a_vlan_cannot_move_to_a_site_where_its_vid_is_taken(): void
    {
        $workshop = Site::factory()->create(['name' => 'Workshop']);
        Vlan::factory()->create(['site_id' => $workshop->id, 'vid' => 10, 'name' => 'Machines']);
        $vlan = Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 10, 'name' => 'Offices']);

        $response = $this->actingAs($this->editor())->put(route('vlans.update', $vlan), $this->payload(['site_id' => $workshop->id]));

        $this->assertRefused($response, 'vid');
        $this->assertSame($this->site->id, $vlan->refresh()->site_id);
    }

    public function test_updating_a_vlan_never_touches_a_subnet(): void
    {
        $vlan = Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 10, 'name' => 'Offices']);

        $this->actingAs($this->editor())->put(route('vlans.update', $vlan), $this->payload([
            'with_subnet' => true,
            'subnet' => ['network' => '192.168.10.0', 'prefix_length' => 24],
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('subnets', 0);
    }

    // --- Deletion -----------------------------------------------------------

    public function test_a_vlan_without_subnet_is_deleted(): void
    {
        $vlan = Vlan::factory()->create(['site_id' => $this->site->id]);

        $this->actingAs($this->editor())
            ->delete(route('vlans.destroy', $vlan))
            ->assertRedirect(route('vlans.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('vlans', 0);
    }

    public function test_a_vlan_with_a_subnet_cannot_be_deleted(): void
    {
        $vlan = Vlan::factory()->create(['site_id' => $this->site->id]);
        Subnet::factory()->cidr('192.168.10.0/24')->create(['vlan_id' => $vlan->id]);

        $response = $this->actingAs($this->editor())->delete(route('vlans.destroy', $vlan));

        $this->assertRefused(
            $response,
            'vlan',
            "Le sous-réseau 192.168.10.0/24 est rattaché à ce VLAN : supprimez-le ou détachez-le d'abord.",
        );
        $this->assertDatabaseHas('vlans', ['id' => $vlan->id]);
        $this->assertDatabaseCount('subnets', 1);
    }

    // --- Authorisation ------------------------------------------------------

    public function test_a_reader_reads_the_vlans_but_cannot_write_them(): void
    {
        $reader = $this->reader();
        $vlan = Vlan::factory()->create(['site_id' => $this->site->id, 'vid' => 20, 'name' => 'Servers']);

        $this->actingAs($reader)->get(route('vlans.index'))->assertOk();
        $this->actingAs($reader)->get(route('vlans.show', $vlan))->assertOk();

        $this->actingAs($reader)->get(route('vlans.create'))->assertForbidden();
        $this->actingAs($reader)->post(route('vlans.store'), $this->payload())->assertForbidden();
        $this->actingAs($reader)->get(route('vlans.edit', $vlan))->assertForbidden();
        $this->actingAs($reader)->put(route('vlans.update', $vlan), $this->payload())->assertForbidden();
        $this->actingAs($reader)->delete(route('vlans.destroy', $vlan))->assertForbidden();

        $this->assertSame(['Servers'], Vlan::query()->pluck('name')->all());
    }

    public function test_an_administrator_writes_vlans_like_an_editor(): void
    {
        $this->actingAs($this->admin())->post(route('vlans.store'), $this->payload())->assertSessionHasNoErrors();

        $this->assertDatabaseCount('vlans', 1);
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $vlan = Vlan::factory()->create(['site_id' => $this->site->id]);

        $this->get(route('vlans.index'))->assertRedirect(route('login'));
        $this->get(route('vlans.show', $vlan))->assertRedirect(route('login'));
        $this->get(route('vlans.create'))->assertRedirect(route('login'));
        $this->post(route('vlans.store'), $this->payload())->assertRedirect(route('login'));
        $this->get(route('vlans.edit', $vlan))->assertRedirect(route('login'));
        $this->put(route('vlans.update', $vlan), $this->payload())->assertRedirect(route('login'));
        $this->delete(route('vlans.destroy', $vlan))->assertRedirect(route('login'));

        $this->assertDatabaseCount('vlans', 1);
    }

    public function test_a_vlan_whose_subnet_was_detached_can_be_deleted(): void
    {
        $vlan = Vlan::factory()->create(['site_id' => $this->site->id]);
        $subnet = Subnet::factory()->cidr('192.168.10.0/24')->create(['vlan_id' => $vlan->id]);
        $editor = $this->editor();

        $this->actingAs($editor)->put(route('subnets.update', $subnet), [
            'network' => '192.168.10.0',
            'prefix_length' => 24,
            'vlan_id' => null,
            'status' => 'active',
        ])->assertSessionHasNoErrors();

        $this->actingAs($editor)->delete(route('vlans.destroy', $vlan))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('vlans', 0);
        $this->assertDatabaseCount('subnets', 1);
    }
}
