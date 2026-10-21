<?php

// torsade/tests/Feature/Network/HomeVolumeTest.php

namespace Tests\Feature\Network;

use App\Casts\AsIpAddress;
use App\Enums\ActivityEvent;
use App\Models\IpAddress;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Actor;
use App\Support\Ipv4;
use Illuminate\Support\Facades\DB;

/**
 * The home page sends the same number of queries whatever the volume of the
 * documentation: compared on a few sites and on 100 sites, 1,000 subnets
 * and 20,000 addresses.
 */
class HomeVolumeTest extends NetworkTestCase
{
    /**
     * So many sites, each with its subnets and their addresses, written in
     * bulk, and as many entries in the activity log as sites.
     */
    private function fill(int $sites, int $subnetsPerSite, int $addressesPerSubnet): void
    {
        $now = now();

        Site::query()->insert(array_map(fn (int $number): array => [
            'name' => sprintf('Site %03d', $number),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], range(1, $sites)));

        $subnets = [];

        foreach (Site::query()->orderBy('id')->pluck('id')->values() as $index => $site) {
            for ($number = 0; $number < $subnetsPerSite; $number++) {
                $subnets[] = [
                    'site_id' => $site,
                    'kind' => 'segment',
                    'ip_version' => 4,
                    'network' => AsIpAddress::toBinary('10.'.$index.'.'.$number.'.0'),
                    'prefix_length' => 24,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            app(AuditLogger::class)->record(ActivityEvent::SiteCreated, Actor::console(), Site::query()->find($site), new: ['name' => 'Site']);
        }

        foreach (array_chunk($subnets, 500) as $chunk) {
            Subnet::query()->insert($chunk);
        }

        $rows = [];

        foreach (Subnet::query()->get(['id', 'network']) as $subnet) {
            for ($host = 1; $host <= $addressesPerSubnet; $host++) {
                $rows[] = [
                    'subnet_id' => $subnet->id,
                    'address' => AsIpAddress::toBinary(Ipv4::toAddress(Ipv4::toInt($subnet->network) + $host)),
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

    private function queriesOfTheHomePage(User $user): int
    {
        $queries = 0;

        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        DB::getEventDispatcher()->forget('Illuminate\Database\Events\QueryExecuted');

        return $queries;
    }

    public function test_the_number_of_queries_of_the_home_page_does_not_depend_on_the_volume(): void
    {
        $admin = $this->admin();
        $reader = $this->reader();

        // Enough for every module to show something.
        $this->fill(14, 2, 3);
        $small = [$this->queriesOfTheHomePage($admin), $this->queriesOfTheHomePage($reader)];

        DB::table('ip_addresses')->delete();
        DB::table('subnets')->delete();
        DB::table('activity_log')->delete();
        DB::table('sites')->delete();

        $this->fill(100, 10, 20);
        $this->assertSame(100, Site::query()->count());
        $this->assertSame(1000, Subnet::query()->count());
        $this->assertSame(20000, IpAddress::query()->count());

        $large = [$this->queriesOfTheHomePage($admin), $this->queriesOfTheHomePage($reader)];

        $this->assertSame($small, $large);
    }
}
