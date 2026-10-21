<?php

// torsade/tests/Feature/Network/IpAddressTest.php

namespace Tests\Feature\Network;

use App\Enums\DeviceType;
use App\Enums\IpAssignment;
use App\Enums\Status;
use App\Models\IpAddress;
use App\Models\IpRange;
use App\Models\Subnet;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

class IpAddressTest extends NetworkTestCase
{
    private Subnet $subnet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subnet = $this->subnet('192.168.100.0/24', ['gateway' => '192.168.100.254']);

        IpRange::factory()->between('192.168.100.100', '192.168.100.199')->create(['subnet_id' => $this->subnet->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'address' => '192.168.100.10',
            'hostname' => null,
            'mac_address' => null,
            'device_type' => null,
            'assignment' => 'static',
            'status' => 'active',
            'description' => null,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function store(array $overrides = [], ?Subnet $subnet = null): TestResponse
    {
        return $this->actingAs($this->editor())
            ->post(route('ip-addresses.store', $subnet ?? $this->subnet), $this->payload($overrides));
    }

    // --- Creation -----------------------------------------------------------

    public function test_only_the_address_is_mandatory(): void
    {
        $this->store()
            ->assertRedirect(route('subnets.show', $this->subnet))
            ->assertSessionHasNoErrors();

        $address = IpAddress::query()->sole();

        $this->assertSame($this->subnet->id, $address->subnet_id);
        $this->assertSame('192.168.100.10', $address->address);
        $this->assertNull($address->hostname);
        $this->assertNull($address->mac_address);
        $this->assertNull($address->device_type);
        $this->assertNull($address->description);
        $this->assertSame(IpAssignment::Static, $address->assignment);
        $this->assertSame(Status::Active, $address->status);
    }

    public function test_an_address_is_documented_with_every_field(): void
    {
        $this->store([
            'hostname' => 'srv-files-01',
            'mac_address' => '00:1A:2B:3C:4D:5E',
            'device_type' => 'server',
            'assignment' => 'static',
            'status' => 'planned',
            'description' => 'File server',
        ])->assertSessionHasNoErrors();

        $address = IpAddress::query()->sole();

        $this->assertSame('srv-files-01', $address->hostname);
        $this->assertSame('00:1a:2b:3c:4d:5e', $address->mac_address);
        $this->assertSame(DeviceType::Server, $address->device_type);
        $this->assertSame(Status::Planned, $address->status);
        $this->assertSame('File server', $address->description);
    }

    public function test_the_address_is_stored_in_binary(): void
    {
        $this->store();

        $row = IpAddress::query()->toBase()->first();

        $this->assertNotNull($row);
        $this->assertSame("\xC0\xA8\x64\x0A", $row->address);
    }

    public function test_an_address_outside_the_subnet_is_refused(): void
    {
        $message = "Cette adresse n'est pas une adresse utilisable de 192.168.100.0/24 (192.168.100.1 – 192.168.100.254).";

        $this->assertRefused($this->store(['address' => '192.168.101.10']), 'address', $message);
        $this->assertRefused($this->store(['address' => '192.168.99.255']), 'address', $message);
        $this->assertRefused($this->store(['address' => '10.0.0.1']), 'address', $message);

        $this->assertDatabaseCount('ip_addresses', 0);
    }

    public function test_the_network_and_broadcast_addresses_are_refused(): void
    {
        $this->assertRefused($this->store(['address' => '192.168.100.0']), 'address');
        $this->assertRefused($this->store(['address' => '192.168.100.255']), 'address');

        $this->store(['address' => '192.168.100.1'])->assertSessionHasNoErrors();
        $this->store(['address' => '192.168.100.253'])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ip_addresses', 2);
    }

    public function test_an_address_already_documented_is_refused(): void
    {
        $this->store()->assertSessionHasNoErrors();

        $this->assertRefused($this->store(), 'address', 'Cette adresse est déjà documentée.');

        $this->assertDatabaseCount('ip_addresses', 1);
    }

    public function test_a_retired_address_keeps_its_number_until_its_record_is_deleted(): void
    {
        $editor = $this->editor();

        $this->store(['status' => 'retired'])->assertSessionHasNoErrors();

        $this->assertRefused($this->store(), 'address', 'Cette adresse est déjà documentée.');

        // Deleting the record frees the number.
        $this->actingAs($editor)->delete(route('ip-addresses.destroy', IpAddress::query()->sole()))->assertSessionHasNoErrors();

        $this->store(['hostname' => 'new-owner'])->assertSessionHasNoErrors();

        $this->assertSame('new-owner', IpAddress::query()->sole()->hostname);
    }

    public function test_a_static_address_inside_a_dhcp_range_is_refused(): void
    {
        $message = 'Une adresse fixe ne peut pas se trouver dans la plage DHCP 192.168.100.100 – 192.168.100.199. '
            .'Choisissez une autre adresse, ou faites-en une réservation DHCP.';

        $this->assertRefused($this->store(['address' => '192.168.100.150']), 'address', $message);
        // On the bounds of the range too.
        $this->assertRefused($this->store(['address' => '192.168.100.100']), 'address', $message);
        $this->assertRefused($this->store(['address' => '192.168.100.199']), 'address', $message);

        // Right before and right after the range.
        $this->store(['address' => '192.168.100.99'])->assertSessionHasNoErrors();
        $this->store(['address' => '192.168.100.200'])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ip_addresses', 2);
    }

    public function test_a_dhcp_reservation_inside_a_dhcp_range_is_accepted(): void
    {
        $this->store(['address' => '192.168.100.150', 'assignment' => 'dhcp_reservation'])->assertSessionHasNoErrors();
        $this->store(['address' => '192.168.100.100', 'assignment' => 'dhcp_reservation'])->assertSessionHasNoErrors();
        // And outside any range as well.
        $this->store(['address' => '192.168.100.20', 'assignment' => 'dhcp_reservation'])->assertSessionHasNoErrors();

        $this->assertSame(3, IpAddress::query()->where('assignment', 'dhcp_reservation')->count());
    }

    public function test_both_addresses_of_a_slash_31_are_accepted(): void
    {
        $link = $this->subnet('10.0.0.0/31');

        $this->store(['address' => '10.0.0.0'], $link)->assertSessionHasNoErrors();
        $this->store(['address' => '10.0.0.1'], $link)->assertSessionHasNoErrors();
        $this->assertRefused($this->store(['address' => '10.0.0.2'], $link), 'address');

        $this->assertSame(2, $link->ipAddresses()->count());
    }

    public function test_the_address_of_a_slash_32_is_accepted(): void
    {
        $host = $this->subnet('10.0.0.9/32');

        $this->assertRefused(
            $this->store(['address' => '10.0.0.8'], $host),
            'address',
            "Cette adresse n'est pas une adresse utilisable de 10.0.0.9/32 (10.0.0.9 – 10.0.0.9).",
        );
        $this->store(['address' => '10.0.0.9'], $host)->assertSessionHasNoErrors();

        $this->assertSame(1, $host->ipAddresses()->count());
    }

    public function test_in_a_slash_30_only_the_two_middle_addresses_are_accepted(): void
    {
        $link = $this->subnet('10.0.0.4/30');

        $this->assertRefused($this->store(['address' => '10.0.0.4'], $link), 'address');
        $this->assertRefused($this->store(['address' => '10.0.0.7'], $link), 'address');
        $this->store(['address' => '10.0.0.5'], $link)->assertSessionHasNoErrors();
        $this->store(['address' => '10.0.0.6'], $link)->assertSessionHasNoErrors();
    }

    public function test_the_address_of_the_gateway_can_be_documented(): void
    {
        $this->store(['address' => '192.168.100.254', 'hostname' => 'router', 'device_type' => 'router'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ip_addresses', 1);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidAddresses(): array
    {
        return [
            'number above 255' => ['192.168.100.256'],
            'three numbers' => ['192.168.100'],
            'leading zero' => ['192.168.100.010'],
            'space inside' => ['192.168.100. 10'],
            'with a prefix length' => ['192.168.100.10/24'],
            'a host name' => ['srv-files-01'],
        ];
    }

    #[DataProvider('invalidAddresses')]
    public function test_an_invalid_address_is_refused(string $address): void
    {
        $this->assertRefused(
            $this->store(['address' => $address]),
            'address',
            'Saisissez une adresse IPv4 valide, par exemple 192.168.1.10.',
        );

        $this->assertDatabaseCount('ip_addresses', 0);
    }

    public function test_an_ipv6_address_is_refused_with_a_clear_message(): void
    {
        $this->assertRefused(
            $this->store(['address' => 'fd00::10']),
            'address',
            'Les adresses IPv6 ne sont pas encore prises en charge : saisissez une adresse IPv4.',
        );
    }

    public function test_the_address_is_required(): void
    {
        $this->assertRefused($this->store(['address' => '']), 'address', 'Le champ adresse est obligatoire.');
    }

    // --- MAC address --------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function macNotations(): array
    {
        return [
            'colons' => ['aa:bb:cc:dd:ee:ff'],
            'hyphens' => ['AA-BB-CC-DD-EE-FF'],
            'dots' => ['aabb.ccdd.eeff'],
            'no separator' => ['AABBCCDDEEFF'],
        ];
    }

    #[DataProvider('macNotations')]
    public function test_a_mac_address_typed_in_any_of_the_four_notations_is_stored_normalised(string $typed): void
    {
        $this->store(['mac_address' => $typed])->assertSessionHasNoErrors();

        $this->assertSame('aa:bb:cc:dd:ee:ff', IpAddress::query()->sole()->mac_address);
    }

    public function test_an_invalid_mac_address_is_refused(): void
    {
        $message = 'Saisissez une adresse MAC, par exemple aa:bb:cc:dd:ee:ff.';

        $this->assertRefused($this->store(['mac_address' => 'aa:bb:cc:dd:ee']), 'mac_address', $message);
        $this->assertRefused($this->store(['mac_address' => 'aa:bb:cc:dd:ee:gg']), 'mac_address', $message);
        $this->assertRefused($this->store(['mac_address' => 'aa:bb-cc:dd-ee:ff']), 'mac_address', $message);
        $this->assertRefused($this->store(['mac_address' => 'unknown']), 'mac_address', $message);

        $this->assertDatabaseCount('ip_addresses', 0);
    }

    public function test_two_addresses_can_share_a_mac_address(): void
    {
        // A machine with two addresses is two records.
        $this->store(['address' => '192.168.100.10', 'mac_address' => 'aa:bb:cc:dd:ee:ff'])->assertSessionHasNoErrors();
        $this->store(['address' => '192.168.100.11', 'mac_address' => 'aa-bb-cc-dd-ee-ff'])->assertSessionHasNoErrors();

        $this->assertSame(2, IpAddress::query()->where('mac_address', 'aa:bb:cc:dd:ee:ff')->count());
    }

    // --- Host name, type, texts ---------------------------------------------

    public function test_a_host_name_accepts_letters_digits_hyphens_underscores_and_dots(): void
    {
        $this->store(['address' => '192.168.100.10', 'hostname' => 'PC_COMPTA-01.example.lan'])->assertSessionHasNoErrors();
        $this->store(['address' => '192.168.100.11', 'hostname' => str_repeat('a', 253)])->assertSessionHasNoErrors();

        $this->assertSame('PC_COMPTA-01.example.lan', IpAddress::query()->orderBy('address')->firstOrFail()->hostname);
    }

    public function test_a_host_name_with_anything_else_is_refused(): void
    {
        $message = "Un nom d'hôte ne contient que des lettres, des chiffres, des tirets, des tirets bas et des points.";

        $this->assertRefused($this->store(['hostname' => 'file server']), 'hostname', $message);
        $this->assertRefused($this->store(['hostname' => 'srv/files']), 'hostname', $message);
        $this->assertRefused($this->store(['hostname' => 'serveur-été']), 'hostname', $message);
        $this->assertRefused($this->store(['hostname' => str_repeat('a', 254)]), 'hostname');

        $this->assertDatabaseCount('ip_addresses', 0);
    }

    public function test_type_assignment_status_and_description_are_checked(): void
    {
        $this->assertRefused($this->store(['device_type' => 'toaster']), 'device_type');
        $this->assertRefused($this->store(['assignment' => 'dynamic']), 'assignment');
        $this->assertRefused($this->store(['assignment' => null]), 'assignment');
        $this->assertRefused($this->store(['status' => 'broken']), 'status');
        $this->assertRefused($this->store(['description' => str_repeat('a', 1001)]), 'description');

        $this->assertDatabaseCount('ip_addresses', 0);
    }

    public function test_every_device_type_is_accepted(): void
    {
        foreach (DeviceType::cases() as $index => $type) {
            $this->store(['address' => '192.168.100.'.(10 + $index), 'device_type' => $type->value])
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('ip_addresses', 12);
    }

    // --- Update -------------------------------------------------------------

    public function test_an_editor_updates_an_address(): void
    {
        $address = IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.10']);

        $this->actingAs($this->editor())
            ->put(route('ip-addresses.update', $address), $this->payload([
                'address' => '192.168.100.11',
                'hostname' => 'printer-hall',
                'mac_address' => 'AABB.CCDD.EEFF',
                'device_type' => 'printer',
                'status' => 'retired',
                'description' => 'Ground floor',
            ]))
            ->assertRedirect(route('subnets.show', $this->subnet))
            ->assertSessionHasNoErrors();

        $address->refresh();

        $this->assertSame('192.168.100.11', $address->address);
        $this->assertSame('printer-hall', $address->hostname);
        $this->assertSame('aa:bb:cc:dd:ee:ff', $address->mac_address);
        $this->assertSame(DeviceType::Printer, $address->device_type);
        $this->assertSame(Status::Retired, $address->status);
        $this->assertSame('Ground floor', $address->description);
    }

    public function test_an_address_keeps_its_own_number_when_updated(): void
    {
        $address = IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.10']);

        $this->actingAs($this->editor())
            ->put(route('ip-addresses.update', $address), $this->payload(['hostname' => 'renamed']))
            ->assertSessionHasNoErrors();

        $this->assertSame('renamed', $address->refresh()->hostname);
    }

    public function test_an_updated_address_obeys_the_same_rules(): void
    {
        IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.20']);
        $address = IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.10']);

        $editor = $this->editor();
        $update = fn (array $overrides): TestResponse => $this->actingAs($editor)
            ->put(route('ip-addresses.update', $address), $this->payload($overrides));

        $this->assertRefused($update(['address' => '192.168.100.20']), 'address', 'Cette adresse est déjà documentée.');
        $this->assertRefused($update(['address' => '192.168.101.10']), 'address');
        $this->assertRefused($update(['address' => '192.168.100.255']), 'address');
        $this->assertRefused($update(['address' => '192.168.100.150']), 'address');
        $this->assertRefused($update(['mac_address' => 'nope']), 'mac_address');
        $this->assertRefused($update(['hostname' => 'two words']), 'hostname');

        $this->assertSame('192.168.100.10', $address->refresh()->address);
    }

    public function test_a_reservation_inside_a_range_cannot_become_static(): void
    {
        $address = IpAddress::factory()->reservation()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.150']);

        $response = $this->actingAs($this->editor())
            ->put(route('ip-addresses.update', $address), $this->payload(['address' => '192.168.100.150', 'assignment' => 'static']));

        $this->assertRefused($response, 'address');
        $this->assertSame(IpAssignment::DhcpReservation, $address->refresh()->assignment);
    }

    public function test_optional_fields_can_be_emptied(): void
    {
        $address = IpAddress::factory()->create([
            'subnet_id' => $this->subnet->id,
            'address' => '192.168.100.10',
            'hostname' => 'old-name',
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'device_type' => 'server',
            'description' => 'To be cleared',
        ]);

        $this->actingAs($this->editor())
            ->put(route('ip-addresses.update', $address), $this->payload([
                'hostname' => '',
                'mac_address' => '',
                'device_type' => '',
                'description' => '',
            ]))
            ->assertSessionHasNoErrors();

        $address->refresh();

        $this->assertNull($address->hostname);
        $this->assertNull($address->mac_address);
        $this->assertNull($address->device_type);
        $this->assertNull($address->description);
    }

    // --- Deletion -----------------------------------------------------------

    public function test_an_editor_deletes_an_address(): void
    {
        $address = IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.10']);

        $this->actingAs($this->editor())
            ->delete(route('ip-addresses.destroy', $address))
            ->assertRedirect(route('subnets.show', $this->subnet))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ip_addresses', 0);
    }

    // --- Authorisation ------------------------------------------------------

    public function test_a_reader_cannot_write_ip_addresses(): void
    {
        $reader = $this->reader();
        $address = IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.10']);

        $this->actingAs($reader)
            ->post(route('ip-addresses.store', $this->subnet), $this->payload(['address' => '192.168.100.11']))
            ->assertForbidden();
        $this->actingAs($reader)
            ->post(route('ip-addresses.bulk', $this->subnet), ['addresses' => [['address' => '192.168.100.11']]])
            ->assertForbidden();
        $this->actingAs($reader)
            ->put(route('ip-addresses.update', $address), $this->payload(['hostname' => 'renamed']))
            ->assertForbidden();
        $this->actingAs($reader)->delete(route('ip-addresses.destroy', $address))->assertForbidden();

        $address = IpAddress::query()->sole();

        $this->assertSame('192.168.100.10', $address->address);
        $this->assertNull($address->hostname);
    }

    public function test_an_administrator_writes_ip_addresses_like_an_editor(): void
    {
        $this->actingAs($this->admin())
            ->post(route('ip-addresses.store', $this->subnet), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ip_addresses', 1);
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $address = IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.10']);

        $this->post(route('ip-addresses.store', $this->subnet), $this->payload(['address' => '192.168.100.11']))
            ->assertRedirect(route('login'));
        $this->post(route('ip-addresses.bulk', $this->subnet), ['addresses' => [['address' => '192.168.100.11']]])
            ->assertRedirect(route('login'));
        $this->put(route('ip-addresses.update', $address), $this->payload())->assertRedirect(route('login'));
        $this->delete(route('ip-addresses.destroy', $address))->assertRedirect(route('login'));

        $this->assertDatabaseCount('ip_addresses', 1);
    }

    // --- Page of the subnet -------------------------------------------------

    public function test_the_page_of_the_subnet_lists_its_addresses_in_numeric_order(): void
    {
        IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.10', 'hostname' => 'srv-files-01', 'device_type' => 'server']);
        IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.9', 'mac_address' => 'aa:bb:cc:dd:ee:ff']);
        IpAddress::factory()->reservation()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.150', 'status' => 'planned']);
        IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.254', 'device_type' => 'router']);

        $this->actingAs($this->reader())
            ->get(route('subnets.show', $this->subnet))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('addresses', 4)
                // As text, ".10" would come before ".9".
                ->where('addresses.0.address', '192.168.100.9')
                ->where('addresses.0.mac_address', 'aa:bb:cc:dd:ee:ff')
                ->where('addresses.0.assignment_label', 'Fixe')
                // The status agrees with the address: "active", not "actif".
                ->where('addresses.0.status_label', 'Active')
                ->where('addresses.0.is_gateway', false)
                ->where('addresses.1.address', '192.168.100.10')
                ->where('addresses.1.hostname', 'srv-files-01')
                ->where('addresses.1.device_type', 'server')
                ->where('addresses.1.device_type_label', 'Serveur')
                ->where('addresses.2.address', '192.168.100.150')
                ->where('addresses.2.assignment', 'dhcp_reservation')
                ->where('addresses.2.assignment_label', 'Réservation DHCP')
                ->where('addresses.2.status_label', 'Prévue')
                // The gateway is flagged where it is documented.
                ->where('addresses.3.address', '192.168.100.254')
                ->where('addresses.3.is_gateway', true)
                ->where('subnet.documented_count', 4)
                ->has('deviceTypes', 12)
                ->has('assignments', 2)
                ->has('statuses', 3)
                ->where('maxBulkLines', 100));
    }
}
