<?php

// torsade/tests/Feature/Network/IpRangeTest.php

namespace Tests\Feature\Network;

use App\Models\IpAddress;
use App\Models\IpRange;
use App\Models\Subnet;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

class IpRangeTest extends NetworkTestCase
{
    private Subnet $subnet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subnet = $this->subnet('192.168.100.0/24', ['gateway' => '192.168.100.254']);
    }

    /**
     * @return TestResponse<Response>
     */
    private function store(string $start, string $end, ?string $description = null): TestResponse
    {
        return $this->actingAs($this->editor())->post(route('ip-ranges.store', $this->subnet), [
            'kind' => 'dhcp',
            'start_address' => $start,
            'end_address' => $end,
            'description' => $description,
        ]);
    }

    private function usableMessage(): string
    {
        return 'La plage doit rester dans les adresses utilisables de 192.168.100.0/24 (192.168.100.1 – 192.168.100.254).';
    }

    // --- Creation -----------------------------------------------------------

    public function test_an_editor_adds_a_range_to_a_subnet(): void
    {
        $this->store('192.168.100.100', '192.168.100.199', 'Workstations')
            ->assertRedirect(route('subnets.show', $this->subnet))
            ->assertSessionHasNoErrors();

        $range = IpRange::query()->sole();

        $this->assertSame($this->subnet->id, $range->subnet_id);
        $this->assertSame('192.168.100.100', $range->start_address);
        $this->assertSame('192.168.100.199', $range->end_address);
        $this->assertSame('Workstations', $range->description);
    }

    public function test_a_range_can_hold_a_single_address(): void
    {
        $this->store('192.168.100.100', '192.168.100.100')->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ip_ranges', 1);
    }

    public function test_a_range_can_cover_every_usable_address_but_the_gateway(): void
    {
        $this->store('192.168.100.1', '192.168.100.253')->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ip_ranges', 1);
    }

    public function test_the_end_cannot_be_lower_than_the_start(): void
    {
        $this->assertRefused(
            $this->store('192.168.100.199', '192.168.100.100'),
            'end_address',
            'La fin de la plage ne peut pas être inférieure à son début.',
        );

        // Compared as numbers: as text, ".9" would come after ".10".
        $this->store('192.168.100.9', '192.168.100.10')->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ip_ranges', 1);
    }

    public function test_a_range_outside_the_usable_addresses_is_refused(): void
    {
        // Starts on the network address.
        $this->assertRefused($this->store('192.168.100.0', '192.168.100.50'), 'start_address', $this->usableMessage());
        // Ends on the broadcast address.
        $this->assertRefused($this->store('192.168.100.200', '192.168.100.255'), 'end_address', $this->usableMessage());
        // Runs over into the next subnet.
        $this->assertRefused($this->store('192.168.100.200', '192.168.101.10'), 'end_address', $this->usableMessage());
        // Entirely elsewhere.
        $this->store('10.0.0.10', '10.0.0.20')->assertSessionHasErrors(['start_address', 'end_address']);

        $this->assertDatabaseCount('ip_ranges', 0);
    }

    public function test_a_range_that_overlaps_another_range_of_the_subnet_is_refused(): void
    {
        IpRange::factory()->between('192.168.100.100', '192.168.100.150')->create(['subnet_id' => $this->subnet->id]);

        $message = 'Cette plage chevauche la plage 192.168.100.100 – 192.168.100.150.';

        // Inside, around, over the start, over the end, on a single shared address.
        $this->assertRefused($this->store('192.168.100.110', '192.168.100.120'), 'start_address', $message);
        $this->assertRefused($this->store('192.168.100.50', '192.168.100.200'), 'start_address', $message);
        $this->assertRefused($this->store('192.168.100.50', '192.168.100.100'), 'start_address', $message);
        $this->assertRefused($this->store('192.168.100.150', '192.168.100.200'), 'start_address', $message);
        $this->assertRefused($this->store('192.168.100.100', '192.168.100.150'), 'start_address', $message);

        $this->assertDatabaseCount('ip_ranges', 1);
    }

    public function test_adjacent_ranges_are_accepted(): void
    {
        IpRange::factory()->between('192.168.100.100', '192.168.100.150')->create(['subnet_id' => $this->subnet->id]);

        $this->store('192.168.100.50', '192.168.100.99')->assertSessionHasNoErrors();
        $this->store('192.168.100.151', '192.168.100.200')->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ip_ranges', 3);
    }

    public function test_ranges_of_two_subnets_do_not_disturb_each_other(): void
    {
        $other = $this->subnet('192.168.200.0/24');
        IpRange::factory()->between('192.168.200.100', '192.168.200.199')->create(['subnet_id' => $other->id]);

        $this->store('192.168.100.100', '192.168.100.199')->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ip_ranges', 2);
    }

    public function test_a_range_that_contains_the_gateway_is_refused(): void
    {
        $message = 'Une plage DHCP ne peut pas contenir la passerelle (192.168.100.254).';

        $this->assertRefused($this->store('192.168.100.200', '192.168.100.254'), 'start_address', $message);
        $this->assertRefused($this->store('192.168.100.254', '192.168.100.254'), 'start_address', $message);

        $this->store('192.168.100.200', '192.168.100.253')->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ip_ranges', 1);
    }

    public function test_a_range_that_covers_a_static_address_is_refused_and_lists_it(): void
    {
        IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.120']);
        IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.110']);
        // Outside the range, and a reservation inside it: neither is in the way.
        IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.10']);
        IpAddress::factory()->reservation()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.130']);

        $this->assertRefused(
            $this->store('192.168.100.100', '192.168.100.199'),
            'start_address',
            "Une plage DHCP ne peut pas contenir d'adresses fixes : 192.168.100.110, 192.168.100.120.",
        );

        // On the very first and the very last address of the range too.
        $this->assertRefused($this->store('192.168.100.110', '192.168.100.115'), 'start_address');
        $this->assertRefused($this->store('192.168.100.115', '192.168.100.120'), 'start_address');

        $this->assertDatabaseCount('ip_ranges', 0);
    }

    public function test_a_retired_static_address_still_blocks_a_range(): void
    {
        // A retired address keeps its number until its record is deleted.
        IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.120', 'status' => 'retired']);

        $this->assertRefused($this->store('192.168.100.100', '192.168.100.199'), 'start_address');
    }

    public function test_a_long_list_of_static_addresses_is_cut(): void
    {
        foreach (range(100, 114) as $host) {
            IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => "192.168.100.{$host}"]);
        }

        $this->store('192.168.100.100', '192.168.100.199');

        $message = $this->error('start_address');

        $this->assertStringContainsString('192.168.100.100, 192.168.100.101', $message);
        $this->assertStringContainsString('192.168.100.109 et 5 de plus.', $message);
        $this->assertStringNotContainsString('192.168.100.110', $message);
    }

    public function test_a_range_may_contain_dhcp_reservations(): void
    {
        IpAddress::factory()->reservation()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.120']);

        $this->store('192.168.100.100', '192.168.100.199')->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ip_ranges', 1);
    }

    public function test_invalid_addresses_are_refused(): void
    {
        $this->assertRefused(
            $this->store('192.168.100', '192.168.100.199'),
            'start_address',
            'Saisissez une adresse IPv4 valide, par exemple 192.168.1.10.',
        );
        $this->assertRefused($this->store('192.168.100.100', '192.168.100.300'), 'end_address');
        $this->assertRefused($this->store('', '192.168.100.199'), 'start_address', 'Le champ début de la plage est obligatoire.');
        $this->assertRefused($this->store('192.168.100.100', ''), 'end_address', 'Le champ fin de la plage est obligatoire.');
        $this->assertRefused(
            $this->store('fd00::100', 'fd00::199'),
            'start_address',
            'Les adresses IPv6 ne sont pas encore prises en charge : saisissez une adresse IPv4.',
        );
        $this->assertRefused(
            $this->store('192.168.100.100', '192.168.100.199', str_repeat('a', 1001)),
            'description',
        );

        $this->assertDatabaseCount('ip_ranges', 0);
    }

    public function test_ranges_in_a_slash_31_and_a_slash_32(): void
    {
        $link = $this->subnet('10.0.0.0/31');
        $host = $this->subnet('10.0.0.9/32');
        $editor = $this->editor();

        // Both addresses of a /31 are usable, and so is the address of a /32.
        $this->actingAs($editor)
            ->post(route('ip-ranges.store', $link), ['kind' => 'dhcp', 'start_address' => '10.0.0.0', 'end_address' => '10.0.0.1'])
            ->assertSessionHasNoErrors();

        $this->actingAs($editor)
            ->post(route('ip-ranges.store', $host), ['kind' => 'dhcp', 'start_address' => '10.0.0.9', 'end_address' => '10.0.0.9'])
            ->assertSessionHasNoErrors();

        $this->assertRefused(
            $this->actingAs($editor)->post(route('ip-ranges.store', $host), ['kind' => 'dhcp', 'start_address' => '10.0.0.9', 'end_address' => '10.0.0.10']),
            'end_address',
        );
    }

    // --- Update -------------------------------------------------------------

    public function test_an_editor_updates_a_range(): void
    {
        $range = IpRange::factory()->between('192.168.100.100', '192.168.100.150')->create(['subnet_id' => $this->subnet->id]);

        // Extended over its own former addresses: it does not overlap itself.
        $this->actingAs($this->editor())
            ->put(route('ip-ranges.update', $range), [
                'kind' => 'dhcp',
                'start_address' => '192.168.100.90',
                'end_address' => '192.168.100.160',
                'description' => 'Extended',
            ])
            ->assertRedirect(route('subnets.show', $this->subnet))
            ->assertSessionHasNoErrors();

        $range->refresh();

        $this->assertSame('192.168.100.90', $range->start_address);
        $this->assertSame('192.168.100.160', $range->end_address);
        $this->assertSame('Extended', $range->description);
    }

    public function test_an_updated_range_obeys_the_same_rules(): void
    {
        IpRange::factory()->between('192.168.100.10', '192.168.100.50')->create(['subnet_id' => $this->subnet->id]);
        $range = IpRange::factory()->between('192.168.100.100', '192.168.100.150')->create(['subnet_id' => $this->subnet->id]);
        IpAddress::factory()->create(['subnet_id' => $this->subnet->id, 'address' => '192.168.100.180']);

        $editor = $this->editor();
        $update = fn (string $start, string $end): TestResponse => $this->actingAs($editor)
            ->put(route('ip-ranges.update', $range), ['kind' => 'dhcp', 'start_address' => $start, 'end_address' => $end]);

        $this->assertRefused($update('192.168.100.40', '192.168.100.150'), 'start_address');
        $this->assertRefused($update('192.168.100.100', '192.168.100.200'), 'start_address');
        $this->assertRefused($update('192.168.100.100', '192.168.100.254'), 'start_address');
        $this->assertRefused($update('192.168.100.100', '192.168.100.255'), 'end_address');
        $this->assertRefused($update('192.168.100.150', '192.168.100.100'), 'end_address');

        $this->assertSame('192.168.100.150', $range->refresh()->end_address);
    }

    // --- Deletion -----------------------------------------------------------

    public function test_an_editor_deletes_a_range(): void
    {
        $range = IpRange::factory()->between('192.168.100.100', '192.168.100.150')->create(['subnet_id' => $this->subnet->id]);

        $this->actingAs($this->editor())
            ->delete(route('ip-ranges.destroy', $range))
            ->assertRedirect(route('subnets.show', $this->subnet))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ip_ranges', 0);
        $this->assertDatabaseHas('subnets', ['id' => $this->subnet->id]);
    }

    // --- Authorisation ------------------------------------------------------

    public function test_a_reader_cannot_write_ip_ranges(): void
    {
        $reader = $this->reader();
        $range = IpRange::factory()->between('192.168.100.100', '192.168.100.150')->create(['subnet_id' => $this->subnet->id]);
        $payload = ['kind' => 'dhcp', 'start_address' => '192.168.100.20', 'end_address' => '192.168.100.30'];

        $this->actingAs($reader)->post(route('ip-ranges.store', $this->subnet), $payload)->assertForbidden();
        $this->actingAs($reader)->put(route('ip-ranges.update', $range), $payload)->assertForbidden();
        $this->actingAs($reader)->delete(route('ip-ranges.destroy', $range))->assertForbidden();

        $this->assertSame('192.168.100.100', IpRange::query()->sole()->start_address);
    }

    public function test_an_administrator_writes_ip_ranges_like_an_editor(): void
    {
        $this->actingAs($this->admin())
            ->post(route('ip-ranges.store', $this->subnet), ['kind' => 'dhcp', 'start_address' => '192.168.100.20', 'end_address' => '192.168.100.30'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('ip_ranges', 1);
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $range = IpRange::factory()->between('192.168.100.100', '192.168.100.150')->create(['subnet_id' => $this->subnet->id]);
        $payload = ['kind' => 'dhcp', 'start_address' => '192.168.100.20', 'end_address' => '192.168.100.30'];

        $this->post(route('ip-ranges.store', $this->subnet), $payload)->assertRedirect(route('login'));
        $this->put(route('ip-ranges.update', $range), $payload)->assertRedirect(route('login'));
        $this->delete(route('ip-ranges.destroy', $range))->assertRedirect(route('login'));

        $this->assertDatabaseCount('ip_ranges', 1);
    }

    // --- Page of the subnet -------------------------------------------------

    public function test_the_page_of_the_subnet_lists_its_ranges_in_order_with_their_size(): void
    {
        IpRange::factory()->between('192.168.100.100', '192.168.100.199')->create(['subnet_id' => $this->subnet->id, 'description' => 'Workstations']);
        IpRange::factory()->between('192.168.100.20', '192.168.100.29')->create(['subnet_id' => $this->subnet->id]);

        $this->actingAs($this->reader())
            ->get(route('subnets.show', $this->subnet))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('ipRanges', 2)
                ->where('ipRanges.0.start_address', '192.168.100.20')
                ->where('ipRanges.0.end_address', '192.168.100.29')
                ->where('ipRanges.0.size', 10)
                ->where('ipRanges.1.start_address', '192.168.100.100')
                ->where('ipRanges.1.size', 100)
                ->where('ipRanges.1.description', 'Workstations'));
    }
}
