<?php

// torsade/tests/Feature/StructuredAddressingUpgradeTest.php

namespace Tests\Feature;

use App\Enums\RangeKind;
use App\Enums\SubnetKind;
use App\Models\Activity;
use App\Models\AddressBlock;
use App\Models\CatalogVlan;
use App\Models\IpAddress;
use App\Models\IpRange;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\User;
use App\Models\Vlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * An installation of version 0.4.1 is upgraded: the catalogue, the blocks,
 * the site and the kind of subnets and the kinds of ranges are added to a
 * database that already holds a network. Nothing of it is lost, and the
 * activity log reads as before.
 *
 * These tests change the schema, which no transaction can undo: each one
 * rebuilds the database before and after itself.
 */
class StructuredAddressingUpgradeTest extends TestCase
{
    /**
     * The first migration of the version: everything from it on is rolled
     * back to get the schema of version 0.4.1.
     */
    private const string FIRST_MIGRATION = '2026_10_11_100000_add_code_to_sites_table';

    /**
     * The subnets of the network before the upgrade, with what each one
     * must become: its site, by name, and its kind.
     *
     * @var array<string, array{vlan: array{0: string, 1: int}|null, gateway: string|null, site: string|null, kind: SubnetKind}>
     */
    private const array SUBNETS = [
        // A /24 carried by a VLAN: on the site of the VLAN.
        '10.10.10.0/24' => ['vlan' => ['Siège', 10], 'gateway' => '10.10.10.254', 'site' => 'Siège', 'kind' => SubnetKind::Segment],
        // A /24 without a VLAN: no site.
        '172.16.0.0/24' => ['vlan' => null, 'gateway' => '172.16.0.1', 'site' => null, 'kind' => SubnetKind::Segment],
        // A /31 with a gateway is a small segment.
        '10.255.0.0/31' => ['vlan' => null, 'gateway' => '10.255.0.0', 'site' => null, 'kind' => SubnetKind::Segment],
        // A /31 with neither gateway nor range is a link between two routers.
        '10.255.0.2/31' => ['vlan' => null, 'gateway' => null, 'site' => null, 'kind' => SubnetKind::PointToPoint],
        // Even when a VLAN carries it: it then gets the site of the VLAN.
        '10.255.0.4/31' => ['vlan' => ['Atelier', 900], 'gateway' => null, 'site' => 'Atelier', 'kind' => SubnetKind::PointToPoint],
        // A /31 with a range is a small segment.
        '10.255.0.6/31' => ['vlan' => null, 'gateway' => null, 'site' => null, 'kind' => SubnetKind::Segment],
        // A /32 with no VLAN, gateway or range is a loopback.
        '10.255.255.1/32' => ['vlan' => null, 'gateway' => null, 'site' => null, 'kind' => SubnetKind::Loopback],
        // A /32 carried by a VLAN is not one.
        '10.255.255.2/32' => ['vlan' => ['Atelier', 901], 'gateway' => null, 'site' => 'Atelier', 'kind' => SubnetKind::Segment],
        // Nor is a /32 with a gateway.
        '10.255.255.3/32' => ['vlan' => null, 'gateway' => '10.255.255.3', 'site' => null, 'kind' => SubnetKind::Segment],
        // A /30 keeps the default kind.
        '10.255.1.0/30' => ['vlan' => null, 'gateway' => null, 'site' => null, 'kind' => SubnetKind::Segment],
    ];

    /**
     * The DHCP ranges before the upgrade: subnet, start, end, description.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string|null}>
     */
    private const array RANGES = [
        ['10.10.10.0/24', '10.10.10.100', '10.10.10.199', 'Postes de travail'],
        ['172.16.0.0/24', '172.16.0.50', '172.16.0.60', null],
        ['10.255.0.6/31', '10.255.0.6', '10.255.0.7', 'Deux postes'],
    ];

    /**
     * The documented addresses before the upgrade: subnet, address, host name, assignment.
     *
     * @var list<array{0: string, 1: string, 2: string|null, 3: string}>
     */
    private const array ADDRESSES = [
        ['10.10.10.0/24', '10.10.10.10', 'srv-fichiers-01', 'static'],
        ['10.10.10.0/24', '10.10.10.110', 'pc-accueil-01', 'dhcp_reservation'],
        ['10.10.10.0/24', '10.10.10.254', 'fw-siege', 'static'],
        ['10.255.0.2/31', '10.255.0.2', 'rtr-a', 'static'],
        ['10.255.0.2/31', '10.255.0.3', 'rtr-b', 'static'],
        ['10.255.255.1/32', '10.255.255.1', 'rtr-a-lo0', 'static'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh')->assertSuccessful();

        $this->rollBackToTheFormerVersion();
    }

    protected function tearDown(): void
    {
        // The other tests expect the current schema, and an empty database.
        $this->artisan('migrate:fresh')->assertSuccessful();

        parent::tearDown();
    }

    private function rollBackToTheFormerVersion(): void
    {
        while (DB::table('migrations')->where('migration', self::FIRST_MIGRATION)->exists()) {
            $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();
        }
    }

    /**
     * Fill the database the way version 0.4.1 would have: with the tables
     * and the columns it had, and nothing else.
     *
     * @return array{sites: array<string, int>, vlans: array<string, int>, subnets: array<string, int>}
     */
    private function documentTheNetwork(): array
    {
        $now = now();
        $stamps = ['created_at' => $now, 'updated_at' => $now];

        $sites = [];

        foreach (['Siège' => 'Bureaux', 'Atelier' => null] as $name => $description) {
            $sites[$name] = (int) DB::table('sites')->insertGetId(['name' => $name, 'description' => $description, ...$stamps]);
        }

        $vlans = [];

        foreach ([['Siège', 10, 'Bureautique', 'active'], ['Siège', 20, 'Serveurs', 'planned'], ['Atelier', 900, 'Interconnexion', 'active'], ['Atelier', 901, 'Supervision', 'retired']] as [$site, $vid, $name, $status]) {
            $vlans[$site.'/'.$vid] = (int) DB::table('vlans')->insertGetId([
                'site_id' => $sites[$site], 'vid' => $vid, 'name' => $name, 'description' => null, 'status' => $status, ...$stamps,
            ]);
        }

        $subnets = [];

        foreach (self::SUBNETS as $cidr => $subnet) {
            [$network, $prefix] = explode('/', $cidr);

            $subnets[$cidr] = (int) DB::table('subnets')->insertGetId([
                'vlan_id' => $subnet['vlan'] === null ? null : $vlans[$subnet['vlan'][0].'/'.$subnet['vlan'][1]],
                'ip_version' => 4,
                'network' => inet_pton($network),
                'prefix_length' => (int) $prefix,
                'gateway' => $subnet['gateway'] === null ? null : inet_pton($subnet['gateway']),
                'name' => 'Réseau '.$cidr,
                'description' => null,
                'status' => 'active',
                ...$stamps,
            ]);
        }

        foreach (self::RANGES as [$cidr, $start, $end, $description]) {
            DB::table('dhcp_ranges')->insert([
                'subnet_id' => $subnets[$cidr],
                'start_address' => inet_pton($start),
                'end_address' => inet_pton($end),
                'description' => $description,
                ...$stamps,
            ]);
        }

        foreach (self::ADDRESSES as [$cidr, $address, $hostname, $assignment]) {
            DB::table('ip_addresses')->insert([
                'subnet_id' => $subnets[$cidr],
                'address' => inet_pton($address),
                'hostname' => $hostname,
                'mac_address' => null,
                'device_type' => null,
                'assignment' => $assignment,
                'description' => null,
                'status' => 'active',
                ...$stamps,
            ]);
        }

        return ['sites' => $sites, 'vlans' => $vlans, 'subnets' => $subnets];
    }

    /**
     * What version 0.4.1 wrote to the activity log about a DHCP range: its
     * creation, a change and the deletion of another one.
     */
    private function logTheLifeOfDhcpRanges(User $author): void
    {
        $range = DB::table('dhcp_ranges')->orderBy('id')->first();

        $this->assertNotNull($range);

        $entry = fn (string $event, int $subjectId, array $changes, string $label): array => [
            'log_name' => 'default',
            'description' => $event,
            'subject_type' => 'App\\Models\\DhcpRange',
            'subject_id' => $subjectId,
            'event' => $event,
            'causer_type' => 'App\\Models\\User',
            'causer_id' => $author->id,
            'attribute_changes' => json_encode($changes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'properties' => json_encode(['source' => 'web', 'ip' => '192.0.2.10', 'label' => $label], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('activity_log')->insert([
            $entry('dhcp_range.created', $range->id, [
                'old' => [],
                'attributes' => [
                    'subnet' => '10.10.10.0/24',
                    'start_address' => '10.10.10.100',
                    'end_address' => '10.10.10.150',
                    'description' => 'Postes de travail',
                ],
            ], '10.10.10.100 – 10.10.10.150'),
            $entry('dhcp_range.updated', $range->id, [
                'old' => ['end_address' => '10.10.10.150'],
                'attributes' => ['end_address' => '10.10.10.199'],
            ], '10.10.10.100 – 10.10.10.199'),
            // A range that no longer exists.
            $entry('dhcp_range.deleted', 4040, [
                'old' => [
                    'subnet' => '10.10.10.0/24',
                    'start_address' => '10.10.10.200',
                    'end_address' => '10.10.10.220',
                    'description' => null,
                ],
                'attributes' => [],
            ], '10.10.10.200 – 10.10.10.220'),
        ]);
    }

    /**
     * Every row of a table, as plain arrays.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $table): array
    {
        return DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    public function test_the_former_schema_is_the_one_of_the_former_version(): void
    {
        $this->assertTrue(Schema::hasTable('dhcp_ranges'));
        $this->assertFalse(Schema::hasTable('ip_ranges'));
        $this->assertFalse(Schema::hasTable('catalog_vlans'));
        $this->assertFalse(Schema::hasTable('address_blocks'));
        $this->assertFalse(Schema::hasColumn('sites', 'code'));
        $this->assertFalse(Schema::hasColumn('vlans', 'catalog_vlan_id'));
        $this->assertFalse(Schema::hasColumn('subnets', 'site_id'));
        $this->assertFalse(Schema::hasColumn('subnets', 'kind'));
    }

    public function test_the_upgrade_keeps_every_object_and_gives_each_subnet_its_site_and_its_kind(): void
    {
        $ids = $this->documentTheNetwork();

        $before = [
            'sites' => $this->rows('sites'),
            'vlans' => $this->rows('vlans'),
            'subnets' => $this->rows('subnets'),
            'ranges' => $this->rows('dhcp_ranges'),
            'addresses' => $this->rows('ip_addresses'),
        ];

        $this->artisan('migrate')->assertSuccessful();

        // What the tables held is still there, column for column.
        $kept = fn (string $table, array $added): array => array_map(
            fn (array $row): array => array_diff_key($row, array_flip($added)),
            $this->rows($table),
        );

        $this->assertEquals($before['sites'], $kept('sites', ['code', 'number', 'status']));
        $this->assertEquals($before['vlans'], $kept('vlans', ['catalog_vlan_id']));
        $this->assertEquals($before['subnets'], $kept('subnets', ['site_id', 'kind']));
        $this->assertEquals($before['ranges'], $kept('ip_ranges', ['kind', 'name']));
        $this->assertEquals($before['addresses'], $kept('ip_addresses', ['device_id', 'jack_id']));

        // The new columns start empty.
        $this->assertSame(2, Site::query()->whereNull('code')->count());
        $this->assertSame(4, Vlan::query()->whereNull('catalog_vlan_id')->count());
        $this->assertSame(0, CatalogVlan::query()->count());
        $this->assertSame(0, AddressBlock::query()->count());

        // Each subnet is found again, on the site and with the kind expected.
        $this->assertSame(count(self::SUBNETS), Subnet::query()->count());

        foreach (self::SUBNETS as $cidr => $expected) {
            $subnet = Subnet::query()->with(['site', 'vlan'])->findOrFail($ids['subnets'][$cidr]);

            $this->assertSame($cidr, $subnet->cidr());
            $this->assertSame($expected['gateway'], $subnet->gateway, $cidr);
            $this->assertSame($expected['site'], $subnet->site?->name, "The site of {$cidr}.");
            $this->assertSame($expected['kind'], $subnet->kind, "The kind of {$cidr}.");
            $this->assertSame($subnet->vlan?->site_id, $expected['vlan'] === null ? null : $subnet->site_id, $cidr);
            $this->assertSame('Réseau '.$cidr, $subnet->name);
        }

        // The DHCP ranges are ranges of the DHCP kind, in their new table.
        $this->assertFalse(Schema::hasTable('dhcp_ranges'));
        $this->assertSame(count(self::RANGES), IpRange::query()->count());

        foreach (self::RANGES as [$cidr, $start, $end, $description]) {
            $range = IpRange::query()->where('subnet_id', $ids['subnets'][$cidr])->sole();

            $this->assertSame(RangeKind::Dhcp, $range->kind);
            $this->assertSame([$start, $end], [$range->start_address, $range->end_address]);
            $this->assertNull($range->name);
            $this->assertSame($description, $range->description);
        }

        // And the addresses, in their subnet.
        $this->assertSame(count(self::ADDRESSES), IpAddress::query()->count());

        foreach (self::ADDRESSES as [$cidr, $ip, $hostname, $assignment]) {
            $address = IpAddress::query()->where('hostname', $hostname)->sole();

            $this->assertSame($ip, $address->address);
            $this->assertSame($ids['subnets'][$cidr], $address->subnet_id);
            $this->assertSame($assignment, $address->assignment->value);
        }

        // The VLANs kept their site, their number and their status.
        $this->assertSame(
            ['Atelier/900/active', 'Atelier/901/retired', 'Siège/10/active', 'Siège/20/planned'],
            Vlan::query()->with('site')->get()
                ->map(fn (Vlan $vlan): string => $vlan->site->name.'/'.$vlan->vid.'/'.$vlan->status->value)
                ->sort()->values()->all(),
        );
    }

    public function test_the_upgrade_leaves_the_activity_log_as_it_was_written_and_readable(): void
    {
        $admin = User::factory()->admin()->create();

        $this->documentTheNetwork();
        $this->logTheLifeOfDhcpRanges($admin);

        $before = $this->rows('activity_log');

        $this->assertCount(3, $before);

        $this->artisan('migrate')->assertSuccessful();

        // Not a byte of the entries has changed, the name of what they are
        // about included.
        $this->assertSame($before, $this->rows('activity_log'));
        $this->assertSame(['App\\Models\\DhcpRange'], array_values(array_unique(array_column($before, 'subject_type'))));

        // The old name is read as the range it has become.
        $created = Activity::query()->where('event', 'dhcp_range.created')->sole();

        $this->assertInstanceOf(IpRange::class, $created->subject);
        $this->assertSame('10.10.10.100', $created->subject->start_address);
        $this->assertNull(Activity::query()->where('event', 'dhcp_range.deleted')->sole()->subject);

        // The page of the log shows them, named as ranges.
        $this->actingAs($admin)
            ->get(route('activity-log.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('activity/Index')
                ->has('entries.data', 3)
                ->where('entries.data.0.event_label', 'Plage DHCP supprimée')
                ->where('entries.data.0.subject.title', '10.10.10.200 – 10.10.10.220')
                ->where('entries.data.0.changes.0', ['field' => 'Sous-réseau', 'old' => '10.10.10.0/24', 'new' => null])
                ->where('entries.data.1.event_label', 'Plage DHCP modifiée')
                ->where('entries.data.1.changes', [['field' => 'Fin', 'old' => '10.10.10.150', 'new' => '10.10.10.199']])
                ->where('entries.data.2.event_label', 'Plage DHCP créée')
                ->where('entries.data.2.subject.title', '10.10.10.100 – 10.10.10.150')
                ->where('entries.data.2.changes.1', ['field' => 'Début', 'old' => null, 'new' => '10.10.10.100'])
                ->where('entries.data.2.author.name', $admin->name)
            );

        // Looking for the ranges created finds those of that time too.
        $this->actingAs($admin)
            ->get(route('activity-log.index', ['event' => 'ip_range.created']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.event_label', 'Plage DHCP créée')
            );

        // The events of that time are not offered as filters of their own.
        $offered = array_column(
            (array) $this->actingAs($admin)->get(route('activity-log.index'))->inertiaProps('events'),
            'value',
        );

        $this->assertContains('ip_range.created', $offered);
        $this->assertNotContains('dhcp_range.created', $offered);
    }

    public function test_a_range_created_after_the_upgrade_is_logged_under_its_new_name(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $subnet = Subnet::factory()->cidr('192.168.100.0/24')->create();

        $this->actingAs(User::factory()->editor()->create())
            ->post(route('ip-ranges.store', $subnet), ['kind' => 'dhcp', 'start_address' => '192.168.100.100', 'end_address' => '192.168.100.199'])
            ->assertSessionHasNoErrors();

        $entry = DB::table('activity_log')->where('event', 'ip_range.created')->sole();

        $this->assertSame('App\\Models\\IpRange', $entry->subject_type);
        $this->assertInstanceOf(IpRange::class, Activity::query()->findOrFail($entry->id)->subject);
    }

    public function test_going_back_keeps_what_the_former_version_knew(): void
    {
        // The rows are compared with their dates: the clock stands still.
        $this->freezeSecond();

        $ids = $this->documentTheNetwork();

        $before = [
            'sites' => $this->rows('sites'),
            'vlans' => $this->rows('vlans'),
            'subnets' => $this->rows('subnets'),
            'ranges' => $this->rows('dhcp_ranges'),
            'addresses' => $this->rows('ip_addresses'),
        ];

        $this->artisan('migrate')->assertSuccessful();

        // What only the new version knows.
        $site = Site::query()->where('name', 'Siège')->sole();
        $site->update(['code' => 'SIE']);

        $entry = CatalogVlan::factory()->create(['vid' => 10, 'name' => 'Bureautique']);
        Vlan::query()->whereKey($ids['vlans']['Siège/10'])->update(['catalog_vlan_id' => $entry->id]);

        AddressBlock::factory()->cidr('10.0.0.0/8')->create();
        AddressBlock::factory()->cidr('10.10.0.0/16')->forSite($site)->create();

        Subnet::query()->whereKey($ids['subnets']['172.16.0.0/24'])->update(['site_id' => $site->id, 'kind' => SubnetKind::External]);

        IpRange::factory()->static()->between('10.10.10.1', '10.10.10.50')->create(['subnet_id' => $ids['subnets']['10.10.10.0/24'], 'name' => 'Infrastructure']);
        IpRange::factory()->reserved()->between('10.10.10.200', '10.10.10.250')->create(['subnet_id' => $ids['subnets']['10.10.10.0/24']]);

        $this->rollBackToTheFormerVersion();

        // The former schema is back.
        $this->assertTrue(Schema::hasTable('dhcp_ranges'));
        $this->assertFalse(Schema::hasTable('ip_ranges'));
        $this->assertFalse(Schema::hasTable('catalog_vlans'));
        $this->assertFalse(Schema::hasTable('address_blocks'));
        $this->assertFalse(Schema::hasColumn('sites', 'code'));
        $this->assertFalse(Schema::hasColumn('vlans', 'catalog_vlan_id'));
        $this->assertFalse(Schema::hasColumn('subnets', 'site_id'));
        $this->assertFalse(Schema::hasColumn('subnets', 'kind'));

        // What it knew is unchanged; the static and the reserved ranges,
        // which it could only have read as DHCP ranges, are gone.
        $this->assertEquals($before['sites'], $this->rows('sites'));
        $this->assertEquals($before['vlans'], $this->rows('vlans'));
        $this->assertEquals($before['subnets'], $this->rows('subnets'));
        $this->assertEquals($before['ranges'], $this->rows('dhcp_ranges'));
        $this->assertEquals($before['addresses'], $this->rows('ip_addresses'));

        // And the way forward still works.
        $this->artisan('migrate')->assertSuccessful();

        $this->assertSame(count(self::RANGES), IpRange::query()->where('kind', RangeKind::Dhcp)->count());
    }
}
