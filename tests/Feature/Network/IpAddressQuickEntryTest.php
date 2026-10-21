<?php

// torsade/tests/Feature/Network/IpAddressQuickEntryTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\IpAssignment;
use App\Enums\Status;
use App\Models\IpAddress;
use App\Models\IpRange;
use App\Models\Subnet;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Several addresses sent at once: all of them are saved, or none.
 */
class IpAddressQuickEntryTest extends NetworkTestCase
{
    private Subnet $subnet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subnet = $this->subnet('192.168.100.0/24', ['gateway' => '192.168.100.254']);
    }

    /**
     * Lines for consecutive addresses, starting at the given host number.
     *
     * @return list<array<string, mixed>>
     */
    private function lines(int $count, int $firstHost = 1): array
    {
        return array_map(fn (int $host): array => [
            'address' => "192.168.100.{$host}",
            'hostname' => "pc-{$host}",
            'mac_address' => sprintf('02-00-00-00-00-%02X', $host),
            'device_type' => 'workstation',
            'assignment' => 'static',
            'status' => 'active',
            'description' => "Desk {$host}",
        ], range($firstHost, $firstHost + $count - 1));
    }

    /**
     * @param  array<int|string, mixed>  $lines
     * @return TestResponse<Response>
     */
    private function send(array $lines): TestResponse
    {
        return $this->actingAs($this->editor())
            ->post(route('ip-addresses.bulk', $this->subnet), ['addresses' => $lines]);
    }

    public function test_fifty_addresses_are_saved_in_one_request(): void
    {
        $this->send($this->lines(50))
            ->assertRedirect(route('subnets.show', $this->subnet))
            ->assertSessionHasNoErrors();

        $this->assertSame(50, $this->subnet->ipAddresses()->count());

        $tenth = IpAddress::query()->orderBy('address')->skip(9)->firstOrFail();

        $this->assertSame('192.168.100.10', $tenth->address);
        $this->assertSame('pc-10', $tenth->hostname);
        $this->assertSame('02:00:00:00:00:0a', $tenth->mac_address);
        $this->assertSame('Desk 10', $tenth->description);
    }

    public function test_each_saved_address_gets_its_own_log_entry(): void
    {
        $this->send($this->lines(50))->assertSessionHasNoErrors();

        $this->assertSame(50, $this->countEntries(ActivityEvent::IpAddressCreated));
        $this->assertSame('192.168.100.50', $this->lastEntry(ActivityEvent::IpAddressCreated)->getProperty('label'));
    }

    public function test_a_single_line_in_error_blocks_the_whole_entry(): void
    {
        $lines = $this->lines(50);
        // The 38th line is outside the subnet.
        $lines[37]['address'] = '192.168.101.38';

        $response = $this->send($lines);

        $this->assertRefused(
            $response,
            'addresses.37.address',
            "Cette adresse n'est pas une adresse utilisable de 192.168.100.0/24 (192.168.100.1 – 192.168.100.254).",
        );

        // The message is attached to that line only, and nothing was saved.
        $this->assertSame(['addresses.37.address'], $this->errorFields());
        $this->assertDatabaseCount('ip_addresses', 0);
        $this->assertSame(0, $this->countEntries(ActivityEvent::IpAddressCreated));
    }

    public function test_each_error_is_attached_to_its_line_and_to_its_field(): void
    {
        IpRange::factory()->between('192.168.100.100', '192.168.100.199')->create(['subnet_id' => $this->subnet->id]);
        IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.9']);

        $lines = $this->lines(10);
        $lines[1]['hostname'] = 'two words';
        $lines[3]['mac_address'] = 'not-a-mac';
        $lines[4]['address'] = '192.168.100.150';
        $lines[5]['address'] = '192.168.100.3';
        $lines[6]['address'] = 'fd00::7';
        $lines[7]['device_type'] = 'toaster';
        // The ninth line is already documented.

        $response = $this->send($lines);

        $response->assertRedirect()->assertSessionHasErrors([
            'addresses.1.hostname',
            'addresses.3.mac_address',
            'addresses.4.address',
            'addresses.5.address',
            'addresses.6.address',
            'addresses.7.device_type',
            'addresses.8.address',
        ]);

        $this->assertSame(
            "Un nom d'hôte ne contient que des lettres, des chiffres, des tirets, des tirets bas et des points.",
            $this->error('addresses.1.hostname'),
        );
        $this->assertSame('Saisissez une adresse MAC, par exemple aa:bb:cc:dd:ee:ff.', $this->error('addresses.3.mac_address'));
        $this->assertStringStartsWith('Une adresse fixe ne peut pas se trouver dans la plage DHCP', $this->error('addresses.4.address'));
        $this->assertSame('Cette adresse figure deux fois dans cette saisie.', $this->error('addresses.5.address'));
        $this->assertSame(
            'Les adresses IPv6 ne sont pas encore prises en charge : saisissez une adresse IPv4.',
            $this->error('addresses.6.address'),
        );
        $this->assertSame('Cette adresse est déjà documentée.', $this->error('addresses.8.address'));

        // The lines without error carry no message.
        foreach ([0, 2, 9] as $line) {
            $this->assertSame('', $this->error("addresses.{$line}.address"));
        }

        $this->assertDatabaseCount('ip_addresses', 1);
    }

    public function test_an_address_typed_twice_is_reported_on_its_second_line(): void
    {
        $lines = $this->lines(3);
        $lines[2]['address'] = '192.168.100.1';

        $response = $this->send($lines);

        $this->assertRefused($response, 'addresses.2.address', 'Cette adresse figure deux fois dans cette saisie.');
        $this->assertSame('', $this->error('addresses.0.address'));
        $this->assertDatabaseCount('ip_addresses', 0);
    }

    public function test_a_reservation_inside_a_dhcp_range_is_accepted_in_an_entry(): void
    {
        IpRange::factory()->between('192.168.100.100', '192.168.100.199')->create(['subnet_id' => $this->subnet->id]);

        $lines = $this->lines(2, 150);
        $lines[0]['assignment'] = 'dhcp_reservation';
        $lines[1]['assignment'] = 'dhcp_reservation';

        $this->send($lines)->assertSessionHasNoErrors();

        $this->assertSame(2, IpAddress::query()->where('assignment', 'dhcp_reservation')->count());
    }

    public function test_only_the_address_is_mandatory_on_a_line(): void
    {
        $this->send([['address' => '192.168.100.10'], ['address' => '192.168.100.11', 'hostname' => '']])
            ->assertSessionHasNoErrors();

        $address = IpAddress::query()->orderBy('address')->firstOrFail();

        $this->assertNull($address->hostname);
        $this->assertNull($address->device_type);
        // Left out, assignment and status take their default value.
        $this->assertSame(IpAssignment::Static, $address->assignment);
        $this->assertSame(Status::Active, $address->status);
    }

    public function test_a_line_without_address_is_refused(): void
    {
        $response = $this->send([['address' => '192.168.100.10'], ['address' => '', 'hostname' => 'forgotten']]);

        $this->assertRefused($response, 'addresses.1.address', 'Le champ adresse est obligatoire.');
        $this->assertDatabaseCount('ip_addresses', 0);
    }

    public function test_an_entry_holds_100_lines_at_most(): void
    {
        $this->assertRefused(
            $this->send($this->lines(101)),
            'addresses',
            "Il n'est pas possible d'enregistrer plus de 100 adresses à la fois.",
        );

        $this->assertDatabaseCount('ip_addresses', 0);

        $this->send($this->lines(100))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ip_addresses', 100);
    }

    public function test_an_empty_entry_is_refused(): void
    {
        $this->assertRefused($this->send([]), 'addresses', 'Saisissez au moins une adresse.');
        $this->assertRefused(
            $this->actingAs($this->editor())->post(route('ip-addresses.bulk', $this->subnet), []),
            'addresses',
        );
        $this->assertRefused(
            $this->actingAs($this->editor())->post(route('ip-addresses.bulk', $this->subnet), ['addresses' => 'everything']),
            'addresses',
        );
    }

    public function test_an_entry_must_be_a_plain_list_of_lines(): void
    {
        $this->assertRefused($this->send(['first' => ['address' => '192.168.100.10']]), 'addresses');
        $this->assertRefused($this->send(['192.168.100.10']), 'addresses.0');

        $this->assertDatabaseCount('ip_addresses', 0);
    }

    public function test_the_whole_entry_is_checked_with_a_handful_of_queries(): void
    {
        IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.200']);

        $editor = $this->editor();
        $lines = $this->lines(100);
        $lines[99]['address'] = '192.168.100.200';

        $this->actingAs($editor);

        DB::enableQueryLog();

        $this->post(route('ip-addresses.bulk', $this->subnet), ['addresses' => $lines])
            ->assertSessionHasErrors('addresses.99.address');

        $queries = count(DB::getQueryLog());

        DB::disableQueryLog();

        // The existing addresses are looked up once for the 100 lines, not
        // once per line.
        $this->assertLessThan(20, $queries);
        $this->assertDatabaseCount('ip_addresses', 1);
    }
}
