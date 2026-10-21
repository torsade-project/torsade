<?php

// torsade/tests/Feature/Network/FreeAddressesTest.php

namespace Tests\Feature\Network;

use App\Models\IpAddress;
use App\Models\IpRange;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The free addresses shown on the page of a subnet: what is usable, minus
 * the gateway, the DHCP ranges and the documented addresses, as intervals.
 */
class FreeAddressesTest extends NetworkTestCase
{
    public function test_an_empty_subnet_is_free_from_its_first_to_its_last_usable_address(): void
    {
        $subnet = $this->subnet('192.168.100.0/24');

        $this->actingAs($this->reader())
            ->get(route('subnets.show', $subnet))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('freeIntervals', [['start' => '192.168.100.1', 'end' => '192.168.100.254', 'count' => 254, 'kind' => null, 'range' => null]])
                ->where('freeCount', 254));
    }

    public function test_the_gateway_the_ip_ranges_and_the_documented_addresses_are_left_out(): void
    {
        $subnet = $this->subnet('192.168.100.0/24', ['gateway' => '192.168.100.254']);

        IpRange::factory()->between('192.168.100.100', '192.168.100.199')->create(['subnet_id' => $subnet->id]);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.1']);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.2']);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.10', 'status' => 'retired']);
        // A reservation inside the range changes nothing: the range is already out.
        IpAddress::factory()->reservation()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.150']);

        $this->actingAs($this->reader())
            ->get(route('subnets.show', $subnet))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('freeIntervals', [
                    ['start' => '192.168.100.3', 'end' => '192.168.100.9', 'count' => 7, 'kind' => null, 'range' => null],
                    ['start' => '192.168.100.11', 'end' => '192.168.100.99', 'count' => 89, 'kind' => null, 'range' => null],
                    ['start' => '192.168.100.200', 'end' => '192.168.100.253', 'count' => 54, 'kind' => null, 'range' => null],
                ])
                // 254 usable, minus the gateway, 100 addresses of the range and
                // 3 documented addresses outside it.
                ->where('freeCount', 150)
                ->where('subnet.usable_count', 254)
                ->where('subnet.documented_count', 4));
    }

    public function test_the_free_addresses_of_a_slash_8_come_as_a_few_intervals(): void
    {
        $subnet = $this->subnet('10.0.0.0/8', ['gateway' => '10.0.0.1']);

        IpRange::factory()->between('10.0.1.0', '10.0.1.255')->create(['subnet_id' => $subnet->id]);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.0.0.10']);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.128.0.1']);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.255.255.254']);

        $started = hrtime(true);

        $response = $this->actingAs($this->reader())->get(route('subnets.show', $subnet));

        $elapsedMilliseconds = (hrtime(true) - $started) / 1_000_000;

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('subnet.usable_count', 16777214)
            ->where('subnet.documented_count', 3)
            ->where('freeIntervals', [
                ['start' => '10.0.0.2', 'end' => '10.0.0.9', 'count' => 8, 'kind' => null, 'range' => null],
                ['start' => '10.0.0.11', 'end' => '10.0.0.255', 'count' => 245, 'kind' => null, 'range' => null],
                ['start' => '10.0.2.0', 'end' => '10.128.0.0', 'count' => 8388097, 'kind' => null, 'range' => null],
                ['start' => '10.128.0.2', 'end' => '10.255.255.253', 'count' => 8388604, 'kind' => null, 'range' => null],
            ])
            // 16 777 214 usable, minus the gateway, 256 addresses of the range
            // and 3 documented addresses.
            ->where('freeCount', 16777214 - 1 - 256 - 3));

        // Four intervals, not sixteen million addresses: neither in the page…
        $this->assertLessThan(20000, strlen((string) $response->getContent()));
        // …nor in the time it takes to build it.
        $this->assertLessThan(2000, $elapsedMilliseconds);
    }

    public function test_a_full_subnet_has_no_free_address(): void
    {
        $link = $this->subnet('10.0.0.4/30', ['gateway' => '10.0.0.5']);
        IpAddress::factory()->create(['subnet_id' => $link->id, 'address' => '10.0.0.6']);

        $this->actingAs($this->reader())
            ->get(route('subnets.show', $link))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('freeIntervals', [])
                ->where('freeCount', 0));
    }

    public function test_the_free_addresses_of_a_slash_31_and_a_slash_32(): void
    {
        $link = $this->subnet('10.0.0.0/31');
        $host = $this->subnet('10.0.0.9/32');
        $reader = $this->reader();

        $this->actingAs($reader)
            ->get(route('subnets.show', $link))
            ->assertInertia(fn (Assert $page) => $page
                ->where('freeIntervals', [['start' => '10.0.0.0', 'end' => '10.0.0.1', 'count' => 2, 'kind' => null, 'range' => null]])
                ->where('subnet.usable_count', 2)
                ->where('subnet.broadcast', '10.0.0.1'));

        $this->actingAs($reader)
            ->get(route('subnets.show', $host))
            ->assertInertia(fn (Assert $page) => $page
                ->where('freeIntervals', [['start' => '10.0.0.9', 'end' => '10.0.0.9', 'count' => 1, 'kind' => null, 'range' => null]])
                ->where('subnet.usable_count', 1)
                ->where('subnet.mask', '255.255.255.255'));
    }

    public function test_a_documented_gateway_is_only_counted_once(): void
    {
        $subnet = $this->subnet('192.168.100.0/24', ['gateway' => '192.168.100.254']);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.254']);

        $this->actingAs($this->reader())
            ->get(route('subnets.show', $subnet))
            ->assertInertia(fn (Assert $page) => $page
                ->where('freeIntervals', [['start' => '192.168.100.1', 'end' => '192.168.100.253', 'count' => 253, 'kind' => null, 'range' => null]])
                ->where('freeCount', 253));
    }
}
