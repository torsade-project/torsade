<?php

// torsade/tests/Feature/Network/SubnetTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\Status;
use App\Models\IpAddress;
use App\Models\IpRange;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\Vlan;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

class SubnetTest extends NetworkTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'network' => '192.168.100.0',
            'prefix_length' => 24,
            'vlan_id' => null,
            'gateway' => null,
            'name' => null,
            'description' => null,
            'status' => 'active',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function store(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->editor())->post(route('subnets.store'), $this->payload($overrides));
    }

    // --- Creation -----------------------------------------------------------

    public function test_a_subnet_is_created_from_an_address_and_a_prefix_length(): void
    {
        $response = $this->store([
            'gateway' => '192.168.100.254',
            'name' => 'Offices',
            'description' => 'First floor',
            'status' => 'planned',
        ]);

        $subnet = Subnet::query()->sole();

        $response->assertRedirect(route('subnets.show', $subnet))->assertSessionHasNoErrors();

        $this->assertSame('192.168.100.0', $subnet->network);
        $this->assertSame(24, $subnet->prefix_length);
        $this->assertSame(4, $subnet->ip_version);
        $this->assertSame('192.168.100.254', $subnet->gateway);
        $this->assertSame('Offices', $subnet->name);
        $this->assertSame('First floor', $subnet->description);
        $this->assertSame(Status::Planned, $subnet->status);
        $this->assertNull($subnet->vlan_id);
    }

    public function test_addresses_are_stored_in_binary(): void
    {
        $this->store(['gateway' => '192.168.100.254']);

        $row = Subnet::query()->toBase()->first();

        $this->assertNotNull($row);
        // Four bytes each, not the text.
        $this->assertSame("\xC0\xA8\x64\x00", $row->network);
        $this->assertSame("\xC0\xA8\x64\xFE", $row->gateway);
    }

    public function test_a_subnet_is_created_from_cidr_notation(): void
    {
        $this->store(['network' => '10.20.0.0/16', 'prefix_length' => null])->assertSessionHasNoErrors();

        $this->assertSame('10.20.0.0/16', Subnet::query()->sole()->cidr());
    }

    public function test_the_prefix_length_typed_with_the_address_wins_over_the_list(): void
    {
        $this->store(['network' => '10.20.0.0/16', 'prefix_length' => 24])->assertSessionHasNoErrors();

        $this->assertSame(16, Subnet::query()->sole()->prefix_length);
    }

    public function test_spaces_around_the_address_are_ignored(): void
    {
        $this->store(['network' => '  192.168.100.0/24  ', 'gateway' => ' 192.168.100.1 '])->assertSessionHasNoErrors();

        $subnet = Subnet::query()->sole();

        $this->assertSame('192.168.100.0/24', $subnet->cidr());
        $this->assertSame('192.168.100.1', $subnet->gateway);
    }

    public function test_an_address_with_host_bits_is_refused_and_the_network_address_is_suggested(): void
    {
        $this->assertRefused(
            $this->store(['network' => '192.168.100.10', 'prefix_length' => 24]),
            'network',
            "192.168.100.10/24 n'est pas une adresse de réseau. Vouliez-vous dire 192.168.100.0/24 ?",
        );

        $this->assertRefused(
            $this->store(['network' => '10.20.30.40/8']),
            'network',
            "10.20.30.40/8 n'est pas une adresse de réseau. Vouliez-vous dire 10.0.0.0/8 ?",
        );

        $this->assertRefused(
            $this->store(['network' => '192.168.100.5/30']),
            'network',
            "192.168.100.5/30 n'est pas une adresse de réseau. Vouliez-vous dire 192.168.100.4/30 ?",
        );

        $this->assertDatabaseCount('subnets', 0);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidNetworks(): array
    {
        return [
            'number above 255' => ['256.1.1.0'],
            'three numbers' => ['192.168.1'],
            'leading zero' => ['192.168.01.0'],
            'space inside' => ['192.168. 1.0'],
            'letters' => ['office network'],
            'mask instead of prefix length' => ['192.168.1.0/255.255.255.0'],
            'two prefix lengths' => ['192.168.1.0/24/24'],
        ];
    }

    #[DataProvider('invalidNetworks')]
    public function test_an_invalid_address_is_refused(string $network): void
    {
        $this->assertRefused(
            $this->store(['network' => $network]),
            'network',
            'Saisissez une adresse IPv4 valide, par exemple 192.168.1.10.',
        );

        $this->assertDatabaseCount('subnets', 0);
    }

    public function test_an_ipv6_subnet_is_refused_with_a_clear_message(): void
    {
        $message = 'Les adresses IPv6 ne sont pas encore prises en charge : saisissez une adresse IPv4.';

        $this->assertRefused($this->store(['network' => 'fd00:10::/64']), 'network', $message);
        $this->assertRefused($this->store(['network' => 'fd00:10::', 'prefix_length' => 24]), 'network', $message);
        $this->assertRefused($this->store(['gateway' => 'fd00::1']), 'gateway', $message);

        $this->assertDatabaseCount('subnets', 0);
    }

    public function test_the_prefix_length_goes_from_1_to_32(): void
    {
        $this->assertRefused($this->store(['network' => '0.0.0.0', 'prefix_length' => 0]), 'prefix_length');
        $this->assertRefused($this->store(['network' => '0.0.0.0/0']), 'prefix_length');
        $this->assertRefused($this->store(['network' => '192.168.100.0', 'prefix_length' => 33]), 'prefix_length');
        $this->assertRefused($this->store(['network' => '192.168.100.0/33']), 'prefix_length');
        $this->assertRefused($this->store(['network' => '192.168.100.0', 'prefix_length' => null]), 'prefix_length');
        $this->assertRefused($this->store(['network' => '192.168.100.0', 'prefix_length' => 'wide']), 'prefix_length');

        $this->assertDatabaseCount('subnets', 0);

        $this->store(['network' => '128.0.0.0/1'])->assertSessionHasNoErrors();
        $this->store(['network' => '10.0.0.1/32'])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('subnets', 2);
    }

    public function test_a_subnet_needs_a_valid_status_and_bounded_texts(): void
    {
        $this->assertRefused($this->store(['status' => 'broken']), 'status');
        $this->assertRefused($this->store(['status' => null]), 'status');
        $this->assertRefused($this->store(['name' => str_repeat('a', 101)]), 'name');
        $this->assertRefused($this->store(['description' => str_repeat('a', 1001)]), 'description');
        $this->assertRefused($this->store(['vlan_id' => 999999999]), 'vlan_id');

        $this->assertDatabaseCount('subnets', 0);
    }

    // --- Overlap ------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function overlappingSubnets(): array
    {
        return [
            'identical' => ['192.168.100.0/24', '192.168.100.0/24'],
            'inside an existing one' => ['192.168.100.0/24', '192.168.100.128/25'],
            'around an existing one' => ['192.168.100.0/24', '192.168.0.0/16'],
            'a single address inside' => ['192.168.100.0/24', '192.168.100.77/32'],
            'a point-to-point link inside' => ['192.168.100.0/24', '192.168.100.254/31'],
            'a /8 around a /30' => ['10.200.3.4/30', '10.0.0.0/8'],
        ];
    }

    #[DataProvider('overlappingSubnets')]
    public function test_a_subnet_that_overlaps_another_one_is_refused(string $existing, string $new): void
    {
        $this->subnet($existing);

        $this->assertRefused($this->store(['network' => $new]), 'network', "Ce sous-réseau chevauche {$existing}.");

        $this->assertDatabaseCount('subnets', 1);
    }

    #[DataProvider('overlappingSubnets')]
    public function test_the_overlap_is_refused_whatever_the_status_of_the_other_subnet(string $existing, string $new): void
    {
        foreach ([Status::Planned, Status::Retired] as $status) {
            Subnet::query()->delete();

            $this->subnet($existing, ['status' => $status]);

            foreach (['planned', 'active', 'retired'] as $newStatus) {
                $this->assertRefused($this->store(['network' => $new, 'status' => $newStatus]), 'network');
            }

            $this->assertDatabaseCount('subnets', 1);
        }
    }

    public function test_every_overlapped_subnet_is_named(): void
    {
        $this->subnet('192.168.100.0/24');
        $this->subnet('192.168.200.0/24');
        $this->subnet('10.0.0.0/8');

        $this->assertRefused(
            $this->store(['network' => '192.168.0.0/16']),
            'network',
            'Ce sous-réseau chevauche 192.168.100.0/24, 192.168.200.0/24.',
        );
    }

    public function test_neighbouring_subnets_are_accepted(): void
    {
        $this->subnet('192.168.100.0/24');

        $this->store(['network' => '192.168.101.0/24'])->assertSessionHasNoErrors();
        $this->store(['network' => '192.168.99.0/24'])->assertSessionHasNoErrors();
        $this->store(['network' => '192.168.102.0/25'])->assertSessionHasNoErrors();
        $this->store(['network' => '192.168.102.128/25'])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('subnets', 5);
    }

    // --- Gateway ------------------------------------------------------------

    public function test_a_gateway_outside_the_subnet_is_refused(): void
    {
        $message = 'La passerelle doit se trouver dans le sous-réseau.';

        $this->assertRefused($this->store(['gateway' => '192.168.101.1']), 'gateway', $message);
        $this->assertRefused($this->store(['gateway' => '192.168.99.255']), 'gateway', $message);
        $this->assertRefused($this->store(['gateway' => '10.0.0.1']), 'gateway', $message);

        $this->assertDatabaseCount('subnets', 0);
    }

    public function test_the_gateway_cannot_be_the_network_or_the_broadcast_address(): void
    {
        $message = "La passerelle ne peut être ni l'adresse de réseau ni l'adresse de diffusion du sous-réseau.";

        $this->assertRefused($this->store(['gateway' => '192.168.100.0']), 'gateway', $message);
        $this->assertRefused($this->store(['gateway' => '192.168.100.255']), 'gateway', $message);
        $this->assertRefused($this->store(['network' => '10.0.0.4/30', 'gateway' => '10.0.0.4']), 'gateway', $message);
        $this->assertRefused($this->store(['network' => '10.0.0.4/30', 'gateway' => '10.0.0.7']), 'gateway', $message);

        $this->assertDatabaseCount('subnets', 0);
    }

    public function test_the_first_and_the_last_usable_addresses_are_accepted_as_gateway(): void
    {
        $this->store(['gateway' => '192.168.100.1'])->assertSessionHasNoErrors();
        $this->store(['network' => '192.168.101.0', 'gateway' => '192.168.101.254'])->assertSessionHasNoErrors();
        $this->store(['network' => '10.0.0.4/30', 'gateway' => '10.0.0.5'])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('subnets', 3);
    }

    public function test_both_addresses_of_a_slash_31_and_the_address_of_a_slash_32_can_be_the_gateway(): void
    {
        // A /31 has no network nor broadcast address.
        $this->store(['network' => '10.0.0.0/31', 'gateway' => '10.0.0.0'])->assertSessionHasNoErrors();
        $this->store(['network' => '10.0.0.2/31', 'gateway' => '10.0.0.3'])->assertSessionHasNoErrors();
        $this->store(['network' => '10.0.0.9/32', 'gateway' => '10.0.0.9'])->assertSessionHasNoErrors();

        $this->assertRefused($this->store(['network' => '10.0.0.4/31', 'gateway' => '10.0.0.6']), 'gateway');
        $this->assertRefused($this->store(['network' => '10.0.0.10/32', 'gateway' => '10.0.0.11']), 'gateway');

        $this->assertDatabaseCount('subnets', 3);
    }

    // --- VLAN ---------------------------------------------------------------

    public function test_a_subnet_is_attached_to_a_vlan(): void
    {
        $vlan = Vlan::factory()->create();

        $this->store(['vlan_id' => $vlan->id])->assertSessionHasNoErrors();

        $this->assertSame($vlan->id, Subnet::query()->sole()->vlan_id);
    }

    public function test_a_vlan_carries_one_subnet_at_most(): void
    {
        $vlan = Vlan::factory()->create();
        Subnet::factory()->cidr('10.0.0.0/24')->create(['vlan_id' => $vlan->id]);

        $this->assertRefused(
            $this->store(['vlan_id' => $vlan->id]),
            'vlan_id',
            'Ce VLAN a déjà un sous-réseau (10.0.0.0/24).',
        );

        $this->assertDatabaseCount('subnets', 1);
    }

    public function test_several_subnets_can_have_no_vlan(): void
    {
        $this->store(['network' => '10.0.0.0/30', 'name' => 'Carrier link'])->assertSessionHasNoErrors();
        $this->store(['network' => '10.0.0.4/30', 'name' => 'VPN'])->assertSessionHasNoErrors();

        $this->assertSame(2, Subnet::query()->whereNull('vlan_id')->count());
    }

    public function test_the_form_only_offers_vlans_without_subnet(): void
    {
        $site = Site::factory()->create(['name' => 'Head office']);
        $free = Vlan::factory()->create(['site_id' => $site->id, 'vid' => 10, 'name' => 'Offices']);
        $taken = Vlan::factory()->create(['site_id' => $site->id, 'vid' => 20, 'name' => 'Servers']);
        $subnet = Subnet::factory()->cidr('10.0.20.0/24')->create(['vlan_id' => $taken->id]);

        $editor = $this->editor();

        $this->actingAs($editor)
            ->get(route('subnets.create', ['vlan' => $free->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('subnets/Form')
                ->where('subnet', null)
                ->where('vlan', $free->id)
                ->has('vlans', 1)
                ->where('vlans.0.id', $free->id)
                ->where('vlans.0.label', '10 — Offices')
                ->where('vlans.0.site', 'Head office')
                ->has('prefixes', 32)
                ->has('statuses', 3));

        // Its own VLAN stays in the list of a subnet being modified.
        $this->actingAs($editor)
            ->get(route('subnets.edit', $subnet))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subnet.cidr', '10.0.20.0/24')
                ->where('subnet.vlan_id', $taken->id)
                ->has('vlans', 2));

        $this->assertRefused($this->actingAs($editor)->get(route('subnets.create', ['vlan' => 999999999])), 'vlan');
    }

    // --- Update -------------------------------------------------------------

    public function test_an_editor_updates_a_subnet(): void
    {
        $vlan = Vlan::factory()->create();
        $subnet = $this->subnet('192.168.100.0/24');

        $this->actingAs($this->editor())
            ->put(route('subnets.update', $subnet), $this->payload([
                'vlan_id' => $vlan->id,
                'gateway' => '192.168.100.1',
                'name' => 'Offices',
                'description' => 'First floor',
                'status' => 'retired',
            ]))
            ->assertRedirect(route('subnets.show', $subnet))
            ->assertSessionHasNoErrors();

        $subnet->refresh();

        $this->assertSame($vlan->id, $subnet->vlan_id);
        $this->assertSame('192.168.100.1', $subnet->gateway);
        $this->assertSame('Offices', $subnet->name);
        $this->assertSame(Status::Retired, $subnet->status);
    }

    public function test_a_subnet_does_not_overlap_itself_when_updated(): void
    {
        $subnet = $this->subnet('192.168.100.0/24');
        $editor = $this->editor();

        $this->actingAs($editor)
            ->put(route('subnets.update', $subnet), $this->payload(['name' => 'Renamed']))
            ->assertSessionHasNoErrors();

        // Widened: it now contains what it was.
        $this->actingAs($editor)
            ->put(route('subnets.update', $subnet), $this->payload(['network' => '192.168.100.0/23']))
            ->assertSessionHasNoErrors();

        $this->assertSame('192.168.100.0/23', $subnet->refresh()->cidr());
    }

    public function test_a_subnet_cannot_be_widened_over_another_one(): void
    {
        $this->subnet('192.168.101.0/24');
        $subnet = $this->subnet('192.168.100.0/24');

        $response = $this->actingAs($this->editor())
            ->put(route('subnets.update', $subnet), $this->payload(['network' => '192.168.100.0/23']));

        $this->assertRefused($response, 'network', 'Ce sous-réseau chevauche 192.168.101.0/24.');
        $this->assertSame(24, $subnet->refresh()->prefix_length);
    }

    public function test_a_change_that_would_leave_out_existing_elements_is_refused_and_lists_them(): void
    {
        $subnet = $this->subnet('192.168.100.0/24', ['gateway' => '192.168.100.254']);

        IpRange::factory()->between('192.168.100.20', '192.168.100.50')->create(['subnet_id' => $subnet->id]);
        IpRange::factory()->between('192.168.100.150', '192.168.100.199')->create(['subnet_id' => $subnet->id]);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.10']);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.200']);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.130']);

        // Halved: everything from .127 up falls out of the usable addresses.
        $response = $this->actingAs($this->editor())->put(route('subnets.update', $subnet), $this->payload([
            'network' => '192.168.100.0/25',
            'gateway' => '192.168.100.254',
        ]));

        $response->assertSessionHasErrors(['network', 'gateway']);

        $this->assertSame(
            'Cette modification laisserait en dehors du sous-réseau : la passerelle 192.168.100.254, '
            ."la plage 192.168.100.150 – 192.168.100.199, l'adresse 192.168.100.130, l'adresse 192.168.100.200.",
            $this->error('network'),
        );
        $this->assertSame('La passerelle doit se trouver dans le sous-réseau.', $this->error('gateway'));

        $this->assertSame('192.168.100.0/24', $subnet->refresh()->cidr());
    }

    public function test_an_address_that_would_become_the_broadcast_address_blocks_the_change(): void
    {
        $subnet = $this->subnet('192.168.100.0/24');
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.127']);

        $response = $this->actingAs($this->editor())
            ->put(route('subnets.update', $subnet), $this->payload(['network' => '192.168.100.0/25']));

        $this->assertRefused(
            $response,
            'network',
            "Cette modification laisserait en dehors du sous-réseau : l'adresse 192.168.100.127.",
        );
    }

    public function test_a_long_list_of_left_out_addresses_is_cut(): void
    {
        $subnet = $this->subnet('192.168.100.0/24');

        foreach (range(200, 214) as $host) {
            IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => "192.168.100.{$host}"]);
        }

        $this->actingAs($this->editor())
            ->put(route('subnets.update', $subnet), $this->payload(['network' => '192.168.100.0/25']));

        $message = $this->error('network');

        $this->assertStringContainsString("l'adresse 192.168.100.200, ", $message);
        $this->assertStringContainsString("l'adresse 192.168.100.209 et 5 de plus.", $message);
        $this->assertStringNotContainsString('192.168.100.210', $message);
    }

    public function test_a_change_that_keeps_every_element_inside_is_accepted(): void
    {
        $subnet = $this->subnet('192.168.100.0/24', ['gateway' => '192.168.100.1']);

        IpRange::factory()->between('192.168.100.20', '192.168.100.50')->create(['subnet_id' => $subnet->id]);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.10']);

        $editor = $this->editor();

        $this->actingAs($editor)
            ->put(route('subnets.update', $subnet), $this->payload(['network' => '192.168.100.0/25', 'gateway' => '192.168.100.1']))
            ->assertSessionHasNoErrors();

        $this->assertSame('192.168.100.0/25', $subnet->refresh()->cidr());

        $this->actingAs($editor)
            ->put(route('subnets.update', $subnet), $this->payload(['network' => '192.168.100.0/22', 'gateway' => '192.168.100.1']))
            ->assertSessionHasNoErrors();

        $this->assertSame('192.168.100.0/22', $subnet->refresh()->cidr());
    }

    public function test_the_gateway_cannot_be_moved_into_a_dhcp_range(): void
    {
        $subnet = $this->subnet('192.168.100.0/24', ['gateway' => '192.168.100.1']);
        IpRange::factory()->between('192.168.100.100', '192.168.100.199')->create(['subnet_id' => $subnet->id]);

        $response = $this->actingAs($this->editor())
            ->put(route('subnets.update', $subnet), $this->payload(['gateway' => '192.168.100.150']));

        $this->assertRefused(
            $response,
            'gateway',
            'La passerelle se trouve dans la plage DHCP 192.168.100.100 – 192.168.100.199.',
        );
        $this->assertSame('192.168.100.1', $subnet->refresh()->gateway);
    }

    public function test_the_gateway_can_be_removed(): void
    {
        $subnet = $this->subnet('192.168.100.0/24', ['gateway' => '192.168.100.1']);

        $this->actingAs($this->editor())
            ->put(route('subnets.update', $subnet), $this->payload(['gateway' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull($subnet->refresh()->gateway);
    }

    public function test_a_subnet_cannot_take_the_vlan_of_another_one(): void
    {
        $vlan = Vlan::factory()->create();
        Subnet::factory()->cidr('10.0.0.0/24')->create(['vlan_id' => $vlan->id]);
        $subnet = $this->subnet('192.168.100.0/24');

        $response = $this->actingAs($this->editor())
            ->put(route('subnets.update', $subnet), $this->payload(['vlan_id' => $vlan->id]));

        $this->assertRefused($response, 'vlan_id', 'Ce VLAN a déjà un sous-réseau (10.0.0.0/24).');
        $this->assertNull($subnet->refresh()->vlan_id);
    }

    // --- Deletion -----------------------------------------------------------

    public function test_an_empty_subnet_is_deleted(): void
    {
        $subnet = $this->subnet();

        $this->actingAs($this->editor())
            ->delete(route('subnets.destroy', $subnet))
            ->assertRedirect(route('subnets.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('subnets', 0);
    }

    public function test_a_subnet_that_holds_addresses_cannot_be_deleted(): void
    {
        $subnet = $this->subnet();
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.10']);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.11']);

        $response = $this->actingAs($this->editor())->delete(route('subnets.destroy', $subnet));

        $this->assertRefused(
            $response,
            'subnet',
            "Des adresses IP sont encore documentées dans ce sous-réseau (2) : supprimez-les d'abord.",
        );
        $this->assertDatabaseHas('subnets', ['id' => $subnet->id]);
        $this->assertDatabaseCount('ip_addresses', 2);
    }

    public function test_the_ip_ranges_are_deleted_with_their_subnet_and_each_deletion_is_logged(): void
    {
        $subnet = $this->subnet();
        IpRange::factory()->between('192.168.100.20', '192.168.100.50')->create(['subnet_id' => $subnet->id]);
        IpRange::factory()->between('192.168.100.100', '192.168.100.199')->create(['subnet_id' => $subnet->id]);

        $this->actingAs($this->editor())->delete(route('subnets.destroy', $subnet))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('subnets', 0);
        $this->assertDatabaseCount('ip_ranges', 0);

        $this->assertSame(2, $this->countEntries(ActivityEvent::IpRangeDeleted));
        $this->assertSame(1, $this->countEntries(ActivityEvent::SubnetDeleted));

        $this->assertSame(
            '192.168.100.100 – 192.168.100.199',
            $this->lastEntry(ActivityEvent::IpRangeDeleted)->getProperty('label'),
        );
    }

    public function test_the_database_itself_deletes_the_ranges_with_their_subnet(): void
    {
        $subnet = $this->subnet();
        IpRange::factory()->between('192.168.100.20', '192.168.100.50')->create(['subnet_id' => $subnet->id]);

        // Without going through the application: the foreign key cascades.
        Subnet::query()->toBase()->where('id', $subnet->id)->delete();

        $this->assertDatabaseCount('ip_ranges', 0);
    }

    // --- Authorisation ------------------------------------------------------

    public function test_a_reader_reads_the_subnets_but_cannot_write_them(): void
    {
        $reader = $this->reader();
        $subnet = $this->subnet('10.0.0.0/24');

        $this->actingAs($reader)->get(route('subnets.index'))->assertOk();
        $this->actingAs($reader)->get(route('subnets.show', $subnet))->assertOk();

        $this->actingAs($reader)->get(route('subnets.create'))->assertForbidden();
        $this->actingAs($reader)->post(route('subnets.store'), $this->payload())->assertForbidden();
        $this->actingAs($reader)->get(route('subnets.edit', $subnet))->assertForbidden();
        $this->actingAs($reader)->put(route('subnets.update', $subnet), $this->payload())->assertForbidden();
        $this->actingAs($reader)->delete(route('subnets.destroy', $subnet))->assertForbidden();

        $this->assertSame('10.0.0.0/24', Subnet::query()->sole()->cidr());
    }

    public function test_an_administrator_writes_subnets_like_an_editor(): void
    {
        $this->actingAs($this->admin())->post(route('subnets.store'), $this->payload())->assertSessionHasNoErrors();

        $this->assertDatabaseCount('subnets', 1);
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $subnet = $this->subnet('10.0.0.0/24');

        $this->get(route('subnets.index'))->assertRedirect(route('login'));
        $this->get(route('subnets.show', $subnet))->assertRedirect(route('login'));
        $this->get(route('subnets.create'))->assertRedirect(route('login'));
        $this->post(route('subnets.store'), $this->payload())->assertRedirect(route('login'));
        $this->get(route('subnets.edit', $subnet))->assertRedirect(route('login'));
        $this->put(route('subnets.update', $subnet), $this->payload())->assertRedirect(route('login'));
        $this->delete(route('subnets.destroy', $subnet))->assertRedirect(route('login'));

        $this->assertDatabaseCount('subnets', 1);
    }

    // --- Pages --------------------------------------------------------------

    public function test_the_list_shows_every_subnet_in_numeric_order(): void
    {
        $site = Site::factory()->create(['name' => 'Head office']);
        $vlan = Vlan::factory()->create(['site_id' => $site->id, 'vid' => 20, 'name' => 'Servers']);

        $servers = Subnet::factory()->cidr('192.168.20.0/24')->create(['vlan_id' => $vlan->id, 'gateway' => '192.168.20.1']);
        $this->subnet('10.0.0.0/30', ['name' => 'Carrier link', 'status' => Status::Planned]);
        $this->subnet('9.255.255.0/24');

        IpAddress::factory()->create(['subnet_id' => $servers->id, 'address' => '192.168.20.10']);

        $this->actingAs($this->reader())
            ->get(route('subnets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('subnets/Index')
                ->has('subnets', 3)
                // As text, "10." would come before "9.".
                ->where('subnets.0.cidr', '9.255.255.0/24')
                ->where('subnets.1.cidr', '10.0.0.0/30')
                ->where('subnets.1.mask', '255.255.255.252')
                ->where('subnets.1.name', 'Carrier link')
                ->where('subnets.1.vlan', null)
                ->where('subnets.1.gateway', null)
                ->where('subnets.1.status_label', 'Prévu')
                ->where('subnets.1.usable_count', 2)
                ->where('subnets.2.cidr', '192.168.20.0/24')
                ->where('subnets.2.mask', '255.255.255.0')
                ->where('subnets.2.gateway', '192.168.20.1')
                ->where('subnets.2.vlan.vid', 20)
                ->where('subnets.2.vlan.name', 'Servers')
                ->where('subnets.2.vlan.site', 'Head office')
                ->where('subnets.2.usable_count', 254)
                ->where('subnets.2.documented_count', 1));
    }

    public function test_the_page_of_a_subnet_shows_what_is_derived_from_its_address(): void
    {
        $subnet = $this->subnet('192.168.100.0/24', ['gateway' => '192.168.100.254', 'name' => 'Offices']);

        $this->actingAs($this->reader())
            ->get(route('subnets.show', $subnet))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('subnets/Show')
                ->where('subnet.cidr', '192.168.100.0/24')
                ->where('subnet.mask', '255.255.255.0')
                ->where('subnet.broadcast', '192.168.100.255')
                ->where('subnet.first_usable', '192.168.100.1')
                ->where('subnet.last_usable', '192.168.100.254')
                ->where('subnet.gateway', '192.168.100.254')
                ->where('subnet.usable_count', 254)
                ->where('subnet.documented_count', 0)
                ->where('subnet.vlan', null)
                ->where('subnet.name', 'Offices'));
    }
}
