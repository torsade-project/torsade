<?php

// torsade/tests/Feature/Network/SearchVolumeTest.php

namespace Tests\Feature\Network;

use App\Casts\AsIpAddress;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\IpAddress;
use App\Models\Port;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\User;
use App\Support\Ipv4;
use Illuminate\Support\Facades\DB;

/**
 * The search sends the same number of queries whatever the volume of the
 * documentation: compared here on a hundred devices and on 2,000 devices, 500
 * subnets and 20,000 addresses, for each form of what can be typed.
 */
class SearchVolumeTest extends NetworkTestCase
{
    /**
     * What is typed, one of each form.
     */
    private const array TYPED = ['poste', '10.0.1.5', '10.0.', '10.0.0.0/16', '10.0.1.0/24', '12', 'aa:bb:cc:00:01:05', 'aa:bb:cc', 'poste-0001 1/0/1', 'retiré'];

    /**
     * So many devices, subnets and addresses, written in bulk.
     */
    private function fill(int $devices, int $subnets, int $addressesPerSubnet): void
    {
        $site = Site::factory()->create(['name' => 'Siège']);
        $now = now();

        foreach (array_chunk(range(1, $devices), 500) as $numbers) {
            Device::query()->insert(array_map(fn (int $number): array => [
                'site_id' => $site->id,
                'name' => sprintf('poste-%04d', $number),
                'type' => 'workstation',
                'description' => $number % 2 === 0 ? 'Poste retiré du parc' : null,
                'status' => $number % 2 === 0 ? 'retired' : 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ], $numbers));
        }

        // A port on the first device, for the search of a device and a port.
        $member = DeviceMember::factory()->create(['device_id' => Device::query()->orderBy('id')->value('id')]);
        Port::factory()->create(['device_member_id' => $member->id, 'name' => '1/0/1']);

        foreach (array_chunk(range(0, $subnets - 1), 250) as $numbers) {
            Subnet::query()->insert(array_map(fn (int $number): array => [
                'site_id' => $site->id,
                'kind' => 'segment',
                'ip_version' => 4,
                'network' => AsIpAddress::toBinary('10.'.intdiv($number, 256).'.'.($number % 256).'.0'),
                'prefix_length' => 24,
                'name' => 'Réseau '.$number,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ], $numbers));
        }

        $rows = [];

        foreach (Subnet::query()->get(['id', 'network']) as $subnet) {
            for ($host = 1; $host <= $addressesPerSubnet; $host++) {
                $value = Ipv4::toInt($subnet->network) + $host;
                $address = Ipv4::toAddress($value);

                $rows[] = [
                    'subnet_id' => $subnet->id,
                    'address' => AsIpAddress::toBinary($address),
                    'hostname' => 'poste-'.str_replace('.', '-', $address),
                    'mac_address' => sprintf('aa:bb:cc:%02x:%02x:%02x', $value >> 16 & 0xFF, $value >> 8 & 0xFF, $value & 0xFF),
                    'assignment' => 'static',
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($rows) === 1000) {
                    IpAddress::query()->insert($rows);
                    $rows = [];
                }
            }
        }

        if ($rows !== []) {
            IpAddress::query()->insert($rows);
        }
    }

    /**
     * The number of queries of each search, and the total of its results.
     *
     * @return array<string, array{queries: int, total: int}>
     */
    private function measure(User $user): array
    {
        $measures = [];

        foreach (self::TYPED as $typed) {
            $queries = 0;

            DB::listen(function () use (&$queries): void {
                $queries++;
            });

            $total = $this->actingAs($user)->getJson(route('search.results', ['q' => $typed]))->assertOk()->json('total');

            DB::getEventDispatcher()->forget('Illuminate\Database\Events\QueryExecuted');

            $measures[$typed] = ['queries' => $queries, 'total' => (int) $total];
        }

        return $measures;
    }

    public function test_the_number_of_queries_of_a_search_does_not_depend_on_the_volume(): void
    {
        $user = $this->editor();

        // Enough for each search to find something in the same groups.
        $this->fill(100, 20, 20);
        $small = $this->measure($user);

        DB::table('ip_addresses')->delete();
        DB::table('ports')->delete();
        DB::table('device_members')->delete();
        DB::table('devices')->delete();
        DB::table('subnets')->delete();
        DB::table('sites')->delete();

        $this->fill(2000, 500, 40);
        $this->assertSame(2000, Device::query()->count());
        $this->assertSame(500, Subnet::query()->count());
        $this->assertSame(20000, IpAddress::query()->count());

        $large = $this->measure($user);

        foreach (self::TYPED as $typed) {
            $this->assertSame($small[$typed]['queries'], $large[$typed]['queries'], "[{$typed}] costs more queries with more objects.");
        }

        // The volume was really searched.
        $this->assertGreaterThan($small['poste']['total'], $large['poste']['total']);
        $this->assertGreaterThan($small['10.0.']['total'], $large['10.0.']['total']);
        $this->assertGreaterThan($small['aa:bb:cc']['total'], $large['aa:bb:cc']['total']);
    }
}
