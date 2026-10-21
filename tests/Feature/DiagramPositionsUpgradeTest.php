<?php

// torsade/tests/Feature/DiagramPositionsUpgradeTest.php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Link;
use App\Models\PatchCord;
use App\Models\Site;
use App\Services\Network\PhysicalDiagram;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * An installation of version 0.13.0 is upgraded: the positions of the
 * devices placed by hand on a diagram are added to a database that already
 * holds a network, its places, its devices, their ports, links and cords.
 * Nothing of it is lost, and going back loses the positions alone.
 *
 * These tests change the schema, which no transaction can undo: each one
 * rebuilds the database before and after itself.
 */
class DiagramPositionsUpgradeTest extends TestCase
{
    /**
     * The first migration of the version: everything from it on is rolled
     * back to get the schema of version 0.13.0.
     */
    private const string FIRST_MIGRATION = '2026_10_15_100000_create_diagram_positions_table';

    /**
     * The tables of the network documentation in version 0.13.0, and the
     * activity log that tells its story.
     *
     * @var list<string>
     */
    private const array TABLES = [
        'sites', 'catalog_vlans', 'vlans', 'address_blocks', 'subnets', 'ip_addresses', 'buildings', 'floors', 'rooms', 'devices',
        'device_templates', 'device_template_port_groups', 'device_members', 'port_aggregates', 'ports', 'port_vlans',
        'links', 'link_ends', 'patch_panels', 'patch_panel_ports', 'jacks', 'patch_cords', 'activity_log',
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
     * Fill the database the way version 0.13.0 would have: two sites with
     * their places, a firewall and two switches with their ports, links
     * between them and to the other site, a panel, a jack and a cord, an
     * address and what the activity log tells of them.
     *
     * @return array{sites: array<string, int>, devices: array<string, int>}
     */
    private function documentTheNetwork(): array
    {
        $stamps = ['created_at' => now(), 'updated_at' => now()];

        $sites = [];

        foreach ([['Siège', 'SIE', 10], ['Atelier', 'ATE', 20], ['Dépôt', 'DEP', 30]] as [$name, $code, $number]) {
            $sites[$name] = (int) DB::table('sites')->insertGetId(['name' => $name, 'code' => $code, 'number' => $number, 'status' => 'active', 'description' => null, ...$stamps]);
        }

        $vlan = (int) DB::table('vlans')->insertGetId([
            'site_id' => $sites['Siège'], 'catalog_vlan_id' => null, 'vid' => 10, 'name' => 'Bureautique', 'description' => null, 'status' => 'active', ...$stamps,
        ]);

        $subnet = (int) DB::table('subnets')->insertGetId([
            'vlan_id' => $vlan, 'site_id' => $sites['Siège'], 'kind' => 'segment', 'ip_version' => 4,
            'network' => inet_pton('10.10.10.0'), 'prefix_length' => 24, 'gateway' => inet_pton('10.10.10.1'),
            'name' => null, 'description' => null, 'status' => 'active', ...$stamps,
        ]);

        $building = (int) DB::table('buildings')->insertGetId(['site_id' => $sites['Siège'], 'name' => 'Bâtiment principal', 'code' => null, 'description' => null, ...$stamps]);
        $floor = (int) DB::table('floors')->insertGetId(['building_id' => $building, 'level' => 0, 'name' => 'Rez-de-chaussée', 'description' => null, ...$stamps]);
        $room = (int) DB::table('rooms')->insertGetId(['floor_id' => $floor, 'name' => 'Local technique', 'is_technical' => true, 'description' => null, ...$stamps]);

        $devices = [];
        $ports = [];

        foreach ([
            ['Siège', $room, 'fw-siege', 'firewall', ['port1', 'port2']],
            ['Siège', $room, 'sw-coeur', 'switch', ['1/0/1', '1/0/2', '1/0/3']],
            ['Atelier', null, 'sw-atelier', 'switch', ['1/0/1']],
        ] as [$site, $inRoom, $name, $type, $portNames]) {
            $devices[$name] = (int) DB::table('devices')->insertGetId([
                'site_id' => $sites[$site], 'room_id' => $inRoom, 'name' => $name, 'type' => $type,
                'manufacturer' => null, 'model' => null, 'serial_number' => null, 'description' => null, 'status' => 'active', ...$stamps,
            ]);

            $member = (int) DB::table('device_members')->insertGetId([
                'device_id' => $devices[$name], 'position' => 1, 'role' => 'primary', 'device_template_id' => null,
                'serial_number' => null, 'room_id' => null, 'description' => null, ...$stamps,
            ]);

            foreach ($portNames as $order => $portName) {
                $ports[$name.'/'.$portName] = (int) DB::table('ports')->insertGetId([
                    'device_member_id' => $member, 'device_id' => $devices[$name], 'name' => $portName, 'position' => $order + 1,
                    'connector' => 'rj45', 'max_speed' => '1g', 'poe_type' => 'none', 'poe_label' => null, 'enabled' => true,
                    'mode' => 'access', 'port_aggregate_id' => null, 'description' => null, ...$stamps,
                ]);
            }
        }

        DB::table('port_vlans')->insert([
            ['port_id' => $ports['sw-coeur/1/0/3'], 'port_aggregate_id' => null, 'vlan_id' => $vlan, 'tagged' => false, ...$stamps],
        ]);

        // The firewall to the core, and a fibre to the workshop.
        foreach ([['fw-siege/port1', 'sw-coeur/1/0/1', null], ['sw-coeur/1/0/2', 'sw-atelier/1/0/1', 'fiber_singlemode']] as [$first, $second, $medium]) {
            $link = (int) DB::table('links')->insertGetId(['medium' => $medium, 'description' => null, 'status' => 'active', ...$stamps]);

            DB::table('link_ends')->insert([
                ['link_id' => $link, 'port_id' => $ports[$first], 'end' => 1, ...$stamps],
                ['link_id' => $link, 'port_id' => $ports[$second], 'end' => 2, ...$stamps],
            ]);
        }

        // A panel of the technical room, a jack of the floor and a cord.
        $panel = (int) DB::table('patch_panels')->insertGetId([
            'room_id' => $room, 'name' => 'PB-A', 'port_count' => 2, 'connector' => 'rj45', 'category' => '6A',
            'description' => null, 'status' => 'active', ...$stamps,
        ]);
        $panelPort = (int) DB::table('patch_panel_ports')->insertGetId(['patch_panel_id' => $panel, 'number' => 1, ...$stamps]);
        DB::table('patch_panel_ports')->insert(['patch_panel_id' => $panel, 'number' => 2, ...$stamps]);
        $jack = (int) DB::table('jacks')->insertGetId([
            'floor_id' => $floor, 'label' => 'B.01', 'location' => null, 'patch_panel_port_id' => $panelPort,
            'description' => null, 'status' => 'active', ...$stamps,
        ]);
        DB::table('patch_cords')->insert(['patch_panel_port_id' => $panelPort, 'port_id' => $ports['sw-coeur/1/0/3'], 'color' => 'bleu', 'description' => null, ...$stamps]);

        DB::table('ip_addresses')->insert([
            'subnet_id' => $subnet, 'address' => inet_pton('10.10.10.21'), 'hostname' => 'pc-accueil', 'mac_address' => null,
            'device_type' => null, 'assignment' => 'static', 'device_id' => null, 'jack_id' => $jack,
            'description' => null, 'status' => 'active', ...$stamps,
        ]);

        // What the former version wrote about a cord.
        DB::table('activity_log')->insert([
            'log_name' => 'default', 'description' => 'patch_cord.created', 'subject_type' => 'App\\Models\\PatchCord', 'subject_id' => 1, 'event' => 'patch_cord.created',
            'causer_type' => null, 'causer_id' => null,
            'attribute_changes' => json_encode(['old' => [], 'attributes' => ['panel_port' => 'PB-A 1', 'port' => 'sw-coeur › 1/0/3']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'properties' => json_encode(['source' => 'web', 'label' => 'PB-A 1 ↔ sw-coeur › 1/0/3'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ...$stamps,
        ]);

        return ['sites' => $sites, 'devices' => $devices];
    }

    /**
     * Every row of every table of the former version, as plain arrays.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function everything(): array
    {
        $tables = [];

        foreach (self::TABLES as $table) {
            $tables[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }

        return $tables;
    }

    /**
     * A position, written straight to its table.
     */
    private function position(int $site, int $device, int $x = 0, int $y = 0, string $view = 'physical'): int
    {
        return (int) DB::table('diagram_positions')->insertGetId([
            'site_id' => $site, 'view' => $view, 'device_id' => $device, 'x' => $x, 'y' => $y,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * What the database refuses, whatever writes it.
     *
     * @param  class-string<QueryException>  $exception
     */
    private function assertRefusedByTheDatabase(callable $write, string $what, string $exception = QueryException::class): void
    {
        try {
            $write();
        } catch (QueryException $thrown) {
            $this->assertInstanceOf($exception, $thrown, $what);

            return;
        }

        $this->fail($what.' was accepted.');
    }

    public function test_the_former_schema_is_the_one_of_version_0_13_0(): void
    {
        $this->assertFalse(Schema::hasTable('diagram_positions'));

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
    }

    public function test_the_upgrade_keeps_every_object_as_it_was(): void
    {
        $ids = $this->documentTheNetwork();

        $before = $this->everything();

        $this->assertSame(
            [
                'sites' => 3, 'catalog_vlans' => 0, 'vlans' => 1, 'address_blocks' => 0, 'subnets' => 1, 'ip_addresses' => 1,
                'buildings' => 1, 'floors' => 1, 'rooms' => 1, 'devices' => 3,
                'device_templates' => 0, 'device_template_port_groups' => 0, 'device_members' => 3, 'port_aggregates' => 0, 'ports' => 6, 'port_vlans' => 1,
                'links' => 2, 'link_ends' => 4, 'patch_panels' => 1, 'patch_panel_ports' => 2, 'jacks' => 1, 'patch_cords' => 1, 'activity_log' => 1,
            ],
            array_map(count(...), $before),
        );

        $this->artisan('migrate')->assertSuccessful();

        // What the tables of the former version held is there, column for
        // column and byte for byte, the log included.
        $this->assertEquals($before, $this->everything());

        // The new table starts empty: every diagram is laid out on its own,
        // as before.
        $this->assertTrue(Schema::hasTable('diagram_positions'));
        $this->assertSame(0, DB::table('diagram_positions')->count());

        // The objects are read as before, and so is the diagram of the head
        // office.
        $this->assertSame(2, Link::query()->has('ends', 2)->count());
        $this->assertSame(1, PatchCord::query()->count());

        $diagram = app(PhysicalDiagram::class)->of(Site::query()->findOrFail($ids['sites']['Siège']));

        $this->assertSame(['fw-siege', 'sw-atelier', 'sw-coeur'], collect($diagram['devices'])->pluck('name')->sort()->values()->all());
        $this->assertSame(2, $diagram['summary']['links']);
    }

    public function test_the_database_itself_keeps_one_place_per_device_on_a_diagram(): void
    {
        $ids = $this->documentTheNetwork();

        $this->artisan('migrate')->assertSuccessful();

        $office = $ids['sites']['Siège'];
        $core = $ids['devices']['sw-coeur'];

        $this->position($office, $core, 16, 24);

        $this->assertRefusedByTheDatabase(
            fn () => $this->position($office, $core, 40, 40),
            'A second place for a device on one diagram',
            UniqueConstraintViolationException::class,
        );

        // The same device on another view, or on the diagram of another
        // site where it is drawn at the edge, has a place of its own.
        $this->position($office, $core, 0, 0, 'logical');
        $this->position($ids['sites']['Atelier'], $core, 0, 0);

        // A position needs its site and its device.
        $this->assertRefusedByTheDatabase(fn () => $this->position(999999999, $core), 'A position on a site that does not exist');
        $this->assertRefusedByTheDatabase(fn () => $this->position($office, 999999999), 'A position of a device that does not exist');

        // A device deleted takes its places along, on every diagram.
        $firewall = $ids['devices']['fw-siege'];

        $this->position($office, $firewall);
        $this->position($ids['sites']['Dépôt'], $firewall);
        DB::table('link_ends')->whereIn('port_id', DB::table('ports')->select('id')->where('device_id', $firewall))->delete();
        DB::table('ports')->where('device_id', $firewall)->delete();
        DB::table('device_members')->where('device_id', $firewall)->delete();
        DB::table('devices')->where('id', $firewall)->delete();

        $this->assertSame(0, DB::table('diagram_positions')->where('device_id', $firewall)->count());

        // A site deleted takes the places of its diagrams along, those of
        // the devices of other sites drawn at its edge included.
        $this->position($ids['sites']['Dépôt'], $core, 8, 8);
        DB::table('sites')->where('id', $ids['sites']['Dépôt'])->delete();

        $this->assertSame(0, DB::table('diagram_positions')->where('site_id', $ids['sites']['Dépôt'])->count());
        $this->assertSame(3, DB::table('diagram_positions')->where('device_id', $core)->count());
        $this->assertTrue(Device::query()->whereKey($core)->exists());
    }

    public function test_going_back_loses_the_positions_and_nothing_else(): void
    {
        $ids = $this->documentTheNetwork();

        $before = $this->everything();

        $this->artisan('migrate')->assertSuccessful();

        $this->position($ids['sites']['Siège'], $ids['devices']['sw-coeur'], 80, 160);
        $this->position($ids['sites']['Siège'], $ids['devices']['fw-siege'], 80, 0);

        $this->rollBackToTheFormerVersion();

        $this->assertFalse(Schema::hasTable('diagram_positions'));
        $this->assertEquals($before, $this->everything());

        // And up again, with no position: every diagram is laid out on its
        // own.
        $this->artisan('migrate')->assertSuccessful();

        $this->assertSame(0, DB::table('diagram_positions')->count());
    }
}
