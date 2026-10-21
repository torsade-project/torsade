<?php

// torsade/tests/Feature/Network/AddressPlanTest.php

namespace Tests\Feature\Network;

use App\Enums\AddressState;
use App\Models\IpAddress;
use App\Models\IpRange;
use App\Models\Subnet;
use App\Services\Network\AddressPlan;
use App\Services\Network\DemoData;
use App\Support\Actor;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * The address plan sent to the page of a subnet: one state per address,
 * from the network address to the broadcast address, for a subnet of 512
 * addresses at most.
 */
class AddressPlanTest extends NetworkTestCase
{
    /**
     * The plan the page of the subnet receives.
     *
     * @return array{size: int, limit: int, cells: list<string>|null}
     */
    private function planOf(Subnet $subnet): array
    {
        /** @var TestResponse<Response> $response */
        $response = $this->actingAs($this->reader())
            ->get(route('subnets.show', $subnet))
            ->assertOk();

        /** @var array{size: int, limit: int, cells: list<string>|null} $plan */
        $plan = $response->inertiaProps('addressPlan');

        return $plan;
    }

    private function demoSubnet(string $network): Subnet
    {
        return Subnet::query()->where('network', inet_pton($network))->sole();
    }

    public function test_an_empty_subnet_is_free_between_its_two_unusable_addresses(): void
    {
        $plan = $this->planOf($this->subnet('192.168.100.0/24'));

        $this->assertSame(256, $plan['size']);
        $this->assertSame(512, $plan['limit']);
        $this->assertNotNull($plan['cells']);
        $this->assertCount(256, $plan['cells']);

        $this->assertSame('unusable', $plan['cells'][0]);
        $this->assertSame('unusable', $plan['cells'][255]);
        $this->assertSame(array_fill(0, 254, 'free'), array_slice($plan['cells'], 1, 254));
    }

    public function test_each_address_gets_the_state_of_what_is_declared_on_it(): void
    {
        $subnet = $this->subnet('192.168.100.0/24', ['gateway' => '192.168.100.254']);

        IpRange::factory()->between('192.168.100.100', '192.168.100.199')->create(['subnet_id' => $subnet->id]);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.10']);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.11', 'status' => 'planned']);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.12', 'status' => 'retired']);
        IpAddress::factory()->reservation()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.150']);
        IpAddress::factory()->reservation()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.20']);

        $cells = $this->planOf($subnet)['cells'];

        $this->assertNotNull($cells);
        $this->assertSame('unusable', $cells[0]);
        $this->assertSame('free', $cells[1]);
        $this->assertSame('documented', $cells[10]);
        $this->assertSame('planned', $cells[11]);
        $this->assertSame('retired', $cells[12]);
        $this->assertSame('free', $cells[13]);
        // A reservation is one inside a range or outside of it.
        $this->assertSame('reservation', $cells[20]);
        $this->assertSame('free', $cells[99]);
        $this->assertSame('dhcp_range', $cells[100]);
        $this->assertSame('dhcp_range', $cells[149]);
        $this->assertSame('reservation', $cells[150]);
        $this->assertSame('dhcp_range', $cells[199]);
        $this->assertSame('free', $cells[200]);
        $this->assertSame('gateway', $cells[254]);
        $this->assertSame('unusable', $cells[255]);

        // 254 usable addresses, minus the gateway, the hundred addresses of
        // the range and the four documented outside of it.
        $this->assertSame(
            ['unusable' => 2, 'free' => 149, 'documented' => 1, 'planned' => 1, 'retired' => 1, 'reservation' => 2, 'dhcp_range' => 99, 'gateway' => 1],
            array_count_values($cells),
        );
    }

    public function test_the_first_state_that_applies_wins(): void
    {
        $subnet = $this->subnet('192.168.100.0/24', ['gateway' => '192.168.100.1']);

        IpRange::factory()->between('192.168.100.100', '192.168.100.199')->create(['subnet_id' => $subnet->id]);
        // The gateway documented as the interface of the router.
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.1', 'status' => 'retired']);
        // A reservation that is not in service yet, then one that no longer is.
        IpAddress::factory()->reservation()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.110', 'status' => 'planned']);
        IpAddress::factory()->reservation()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.111', 'status' => 'retired']);

        $cells = $this->planOf($subnet)['cells'];

        $this->assertNotNull($cells);
        $this->assertSame('gateway', $cells[1]);
        $this->assertSame('planned', $cells[110]);
        $this->assertSame('retired', $cells[111]);
        $this->assertSame('dhcp_range', $cells[112]);
    }

    public function test_every_state_sent_is_a_known_one(): void
    {
        $subnet = $this->subnet('192.168.100.0/24', ['gateway' => '192.168.100.254']);

        IpRange::factory()->between('192.168.100.100', '192.168.100.199')->create(['subnet_id' => $subnet->id]);
        IpRange::factory()->reserved()->between('192.168.100.200', '192.168.100.220')->create(['subnet_id' => $subnet->id]);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.10']);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.11', 'status' => 'planned']);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.12', 'status' => 'retired']);
        IpAddress::factory()->reservation()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.150']);

        $known = array_map(fn (AddressState $state): string => $state->value, AddressState::cases());
        $sent = array_keys(array_count_values($this->planOf($subnet)['cells'] ?? []));

        sort($known);
        sort($sent);

        // The nine states, all present here.
        $this->assertCount(9, $known);
        $this->assertSame($known, $sent);
    }

    public function test_the_nine_states_come_in_their_order_of_priority(): void
    {
        $this->assertSame(
            ['unusable', 'gateway', 'planned', 'retired', 'reservation', 'documented', 'dhcp_range', 'reserved_range', 'free'],
            array_map(fn (AddressState $state): string => $state->value, AddressState::cases()),
        );
    }

    public function test_an_address_of_a_reserved_range_has_a_state_of_its_own_and_one_of_a_static_range_is_free(): void
    {
        $subnet = $this->subnet('192.168.100.0/24', ['gateway' => '192.168.100.1']);

        IpRange::factory()->static()->between('192.168.100.1', '192.168.100.49')->create(['subnet_id' => $subnet->id, 'name' => 'Infrastructure']);
        IpRange::factory()->between('192.168.100.100', '192.168.100.199')->create(['subnet_id' => $subnet->id]);
        IpRange::factory()->reserved()->between('192.168.100.200', '192.168.100.229')->create(['subnet_id' => $subnet->id]);

        // In the static range.
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.10']);
        // In the reserved range: documented, planned, retired, reserved on
        // the DHCP server.
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.200']);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.201', 'status' => 'planned']);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.202', 'status' => 'retired']);
        IpAddress::factory()->reservation()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.203']);

        $cells = $this->planOf($subnet)['cells'];

        $this->assertNotNull($cells);

        // The gateway stays the gateway inside a static range.
        $this->assertSame('gateway', $cells[1]);
        // A free address of a static range is simply free.
        $this->assertSame('free', $cells[2]);
        $this->assertSame('documented', $cells[10]);
        $this->assertSame('free', $cells[49]);
        $this->assertSame('free', $cells[50]);
        $this->assertSame('dhcp_range', $cells[100]);
        // What is declared on an address comes before the range it is in.
        $this->assertSame('documented', $cells[200]);
        $this->assertSame('planned', $cells[201]);
        $this->assertSame('retired', $cells[202]);
        $this->assertSame('reservation', $cells[203]);
        $this->assertSame('reserved_range', $cells[204]);
        $this->assertSame('reserved_range', $cells[229]);
        $this->assertSame('free', $cells[230]);

        $this->assertSame(26, array_count_values($cells)['reserved_range']);
        $this->assertSame(100, array_count_values($cells)['dhcp_range']);
    }

    /**
     * @return array<string, array{string, int, list<int>}>
     */
    public static function sizes(): array
    {
        return [
            // CIDR, number of cells, positions of the unusable ones.
            'a /23, the largest with a plan' => ['10.50.0.0/23', 512, [0, 511]],
            'a /25' => ['10.50.0.0/25', 128, [0, 127]],
            'a /30' => ['10.50.0.0/30', 4, [0, 3]],
            'a /31, where both addresses are usable' => ['10.50.0.0/31', 2, []],
            'a /32, a single address' => ['10.50.0.7/32', 1, []],
        ];
    }

    /**
     * @param  list<int>  $unusable
     */
    #[DataProvider('sizes')]
    public function test_the_plan_has_one_cell_per_address_of_the_subnet(string $cidr, int $size, array $unusable): void
    {
        $plan = $this->planOf($this->subnet($cidr));

        $this->assertSame($size, $plan['size']);
        $this->assertNotNull($plan['cells']);
        $this->assertCount($size, $plan['cells']);
        $this->assertSame($unusable, array_keys($plan['cells'], 'unusable', true));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function largeSubnets(): array
    {
        return [
            'a /22' => ['10.60.0.0/22', 1024],
            'a /16' => ['10.70.0.0/16', 65536],
            'a /8' => ['11.0.0.0/8', 16777216],
        ];
    }

    #[DataProvider('largeSubnets')]
    public function test_a_subnet_of_more_than_512_addresses_gets_no_cells(string $cidr, int $size): void
    {
        $subnet = $this->subnet($cidr);

        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => long2ip(ip2long(explode('/', $cidr)[0]) + 10)]);

        $plan = $this->planOf($subnet);

        $this->assertSame(['size' => $size, 'limit' => 512, 'cells' => null], $plan);

        // The list of free intervals still carries the information.
        $this->actingAs($this->reader())
            ->get(route('subnets.show', $subnet))
            ->assertInertia(fn ($page) => $page->has('freeIntervals', 2)->where('freeCount', $size - 3));
    }

    public function test_the_limit_is_512_addresses(): void
    {
        $this->assertSame(512, AddressPlan::MAX_ADDRESSES);
    }

    public function test_the_plan_of_the_demonstration_office_network_shows_its_declared_state(): void
    {
        app(DemoData::class)->create(Actor::console());

        // 10.10.10.0/24: gateway .254, DHCP range .100 to .199, six static
        // addresses and fourteen reservations inside the range.
        $cells = $this->planOf($this->demoSubnet('10.10.10.0'))['cells'];

        $this->assertNotNull($cells);
        $this->assertCount(256, $cells);

        $this->assertSame('unusable', $cells[0]);
        $this->assertSame('unusable', $cells[255]);
        $this->assertSame('gateway', $cells[254]);

        foreach ([20, 21, 22, 23, 24, 30] as $position) {
            $this->assertSame('documented', $cells[$position], "10.10.10.{$position}");
        }

        foreach (range(110, 123) as $position) {
            $this->assertSame('reservation', $cells[$position], "10.10.10.{$position}");
        }

        foreach ([...range(100, 109), ...range(124, 199)] as $position) {
            $this->assertSame('dhcp_range', $cells[$position], "10.10.10.{$position}");
        }

        foreach ([1, 19, 25, 29, 31, 99, 200, 253] as $position) {
            $this->assertSame('free', $cells[$position], "10.10.10.{$position}");
        }

        $this->assertSame(
            ['unusable' => 2, 'free' => 147, 'documented' => 6, 'dhcp_range' => 86, 'reservation' => 14, 'gateway' => 1],
            array_count_values($cells),
        );
    }

    public function test_the_plan_of_the_demonstration_server_network_shows_planned_and_retired_addresses(): void
    {
        app(DemoData::class)->create(Actor::console());

        $cells = $this->planOf($this->demoSubnet('10.10.20.0'))['cells'];

        $this->assertNotNull($cells);
        $this->assertSame('planned', $cells[31]);
        $this->assertSame('retired', $cells[40]);
        $this->assertSame('gateway', $cells[254]);
    }

    public function test_the_free_cells_match_the_free_addresses_of_the_page(): void
    {
        app(DemoData::class)->create(Actor::console());

        foreach (Subnet::query()->get() as $subnet) {
            /** @var TestResponse<Response> $response */
            $response = $this->actingAs($this->reader())->get(route('subnets.show', $subnet))->assertOk();

            /** @var array{cells: list<string>|null} $plan */
            $plan = $response->inertiaProps('addressPlan');

            $this->assertNotNull($plan['cells'], $subnet->cidr());

            $states = array_count_values($plan['cells']);

            // A free address of a reserved range is free, and drawn as
            // part of its range.
            $this->assertSame(
                $response->inertiaProps('freeCount'),
                ($states['free'] ?? 0) + ($states['reserved_range'] ?? 0),
                $subnet->cidr(),
            );
        }
    }
}
