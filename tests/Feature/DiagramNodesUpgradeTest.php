<?php

// torsade/tests/Feature/DiagramNodesUpgradeTest.php

namespace Tests\Feature;

use App\Enums\DiagramNode;
use App\Models\DiagramPosition;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * An installation of version 0.14.0 is upgraded: the positions placed by
 * hand, until now those of devices, can also be those of subnets and of
 * the node of the internet. The positions already saved are kept, as
 * positions of devices, and the database itself keeps each node whole and
 * in one place per diagram. Going back loses the positions of the subnets
 * and of the internet alone.
 *
 * These tests change the schema, which no transaction can undo: each one
 * rebuilds the database before and after itself.
 */
class DiagramNodesUpgradeTest extends TestCase
{
    /**
     * The first migration of the version: everything from it on is rolled
     * back to get the schema of version 0.14.0.
     */
    private const string FIRST_MIGRATION = '2026_10_15_200000_add_logical_nodes_to_diagram_positions_table';

    /**
     * The tables of version 0.14.0 a position refers to, and the positions.
     *
     * @var list<string>
     */
    private const array TABLES = ['sites', 'vlans', 'subnets', 'devices', 'diagram_positions', 'activity_log'];

    /**
     * The columns of the positions in version 0.14.0.
     *
     * @var list<string>
     */
    private const array FORMER_COLUMNS = ['id', 'site_id', 'view', 'device_id', 'x', 'y', 'created_at', 'updated_at'];

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
     * Fill the database the way version 0.14.0 would have: two sites, their
     * subnets and devices, and positions saved on the physical diagram of
     * each site, a device of the head office drawn at the edge of the one
     * of the workshop among them.
     *
     * @return array{sites: array<string, int>, devices: array<string, int>, subnets: array<string, int>}
     */
    private function documentTheNetwork(): array
    {
        $stamps = ['created_at' => now(), 'updated_at' => now()];

        $sites = [];

        foreach ([['Siège', 'SIE', 10], ['Atelier', 'ATE', 20]] as [$name, $code, $number]) {
            $sites[$name] = (int) DB::table('sites')->insertGetId(['name' => $name, 'code' => $code, 'number' => $number, 'status' => 'active', 'description' => null, ...$stamps]);
        }

        $vlan = (int) DB::table('vlans')->insertGetId([
            'site_id' => $sites['Siège'], 'catalog_vlan_id' => null, 'vid' => 10, 'name' => 'Bureautique', 'description' => null, 'status' => 'active', ...$stamps,
        ]);

        $subnets = [];

        foreach ([
            ['bureautique', $vlan, $sites['Siège'], 'segment', '10.10.10.0', 24, '10.10.10.1'],
            ['operateur', null, $sites['Siège'], 'external', '203.0.113.0', 29, '203.0.113.1'],
            ['tunnel', null, null, 'tunnel', '172.31.255.0', 30, null],
        ] as [$name, $inVlan, $site, $kind, $network, $prefix, $gateway]) {
            $subnets[$name] = (int) DB::table('subnets')->insertGetId([
                'vlan_id' => $inVlan, 'site_id' => $site, 'kind' => $kind, 'ip_version' => 4,
                'network' => inet_pton($network), 'prefix_length' => $prefix, 'gateway' => $gateway === null ? null : inet_pton($gateway),
                'name' => $name, 'description' => null, 'status' => 'active', ...$stamps,
            ]);
        }

        $devices = [];

        foreach ([['Siège', 'fw-siege', 'firewall'], ['Siège', 'sw-coeur', 'switch'], ['Atelier', 'sw-atelier', 'switch']] as [$site, $name, $type]) {
            $devices[$name] = (int) DB::table('devices')->insertGetId([
                'site_id' => $sites[$site], 'room_id' => null, 'name' => $name, 'type' => $type,
                'manufacturer' => null, 'model' => null, 'serial_number' => null, 'description' => null, 'status' => 'active', ...$stamps,
            ]);
        }

        foreach ([
            [$sites['Siège'], $devices['fw-siege'], 80, 0],
            [$sites['Siège'], $devices['sw-coeur'], 80, 160],
            [$sites['Atelier'], $devices['sw-atelier'], 0, 0],
            [$sites['Atelier'], $devices['fw-siege'], -240, -8],
        ] as [$site, $device, $x, $y]) {
            DB::table('diagram_positions')->insert(['site_id' => $site, 'view' => 'physical', 'device_id' => $device, 'x' => $x, 'y' => $y, ...$stamps]);
        }

        // What the former version wrote when the layout was saved.
        DB::table('activity_log')->insert([
            'log_name' => 'default', 'description' => 'site.diagram_saved', 'subject_type' => 'App\\Models\\Site', 'subject_id' => $sites['Siège'], 'event' => 'site.diagram_saved',
            'causer_type' => null, 'causer_id' => null, 'attribute_changes' => null,
            'properties' => json_encode(['source' => 'web', 'label' => 'Siège', 'view' => 'physical', 'devices' => 2], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ...$stamps,
        ]);

        return ['sites' => $sites, 'devices' => $devices, 'subnets' => $subnets];
    }

    /**
     * Every row of the tables of the former version, as plain arrays, the
     * positions with the columns they had then.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function everything(): array
    {
        $tables = [];

        foreach (self::TABLES as $table) {
            $query = DB::table($table)->orderBy('id');

            if ($table === 'diagram_positions') {
                $query->select(self::FORMER_COLUMNS);
            }

            $tables[$table] = $query->get()->map(fn (object $row): array => (array) $row)->all();
        }

        return $tables;
    }

    /**
     * A position, written straight to its table.
     *
     * @param  array<string, mixed>  $columns
     */
    private function position(int $site, array $columns, string $view = 'logical'): int
    {
        return (int) DB::table('diagram_positions')->insertGetId([
            'site_id' => $site, 'view' => $view, 'x' => 0, 'y' => 0, ...$columns,
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

    public function test_the_former_schema_is_the_one_of_version_0_14_0(): void
    {
        $this->assertSame(self::FORMER_COLUMNS, Schema::getColumnListing('diagram_positions'));
        $this->assertFalse(collect(Schema::getColumns('diagram_positions'))->firstWhere('name', 'device_id')['nullable']);
    }

    public function test_the_upgrade_keeps_every_position_as_the_one_of_a_device(): void
    {
        $this->documentTheNetwork();

        $before = $this->everything();

        $this->assertSame(['sites' => 2, 'vlans' => 1, 'subnets' => 3, 'devices' => 3, 'diagram_positions' => 4, 'activity_log' => 1], array_map(count(...), $before));

        $this->artisan('migrate')->assertSuccessful();

        // Every row is there, column for column, the positions included.
        $this->assertEquals($before, $this->everything());

        // Each position is the one of a device, and read as such.
        $this->assertSame(['device', 'device', 'device', 'device'], DB::table('diagram_positions')->orderBy('id')->pluck('node')->all());
        $this->assertSame([null, null, null, null], DB::table('diagram_positions')->orderBy('id')->pluck('subnet_id')->all());
        $this->assertSame([null, null, null, null], DB::table('diagram_positions')->orderBy('id')->pluck('internet_node')->all());

        foreach (DiagramPosition::query()->with('device')->get() as $position) {
            $this->assertSame(DiagramNode::Device, $position->node);
            $this->assertNotNull($position->device);
        }

        // The device of a position is now optional: a subnet and the
        // internet have none.
        $this->assertTrue(collect(Schema::getColumns('diagram_positions'))->firstWhere('name', 'device_id')['nullable']);
    }

    public function test_the_database_itself_keeps_each_node_whole(): void
    {
        $ids = $this->documentTheNetwork();

        $this->artisan('migrate')->assertSuccessful();

        $office = $ids['sites']['Siège'];
        $device = $ids['devices']['sw-coeur'];
        $subnet = $ids['subnets']['bureautique'];

        // What is accepted: each node with what it needs, and nothing else.
        $this->position($office, ['node' => 'device', 'device_id' => $device]);
        $this->position($office, ['node' => 'subnet', 'subnet_id' => $subnet]);
        $this->position($office, ['node' => 'internet']);

        // A position written without its node is the one of a device, as
        // in the former version.
        $this->position($office, ['device_id' => $ids['devices']['fw-siege']]);

        foreach ([
            'A device without its device' => ['node' => 'device'],
            'A device with a subnet' => ['node' => 'device', 'device_id' => $ids['devices']['sw-atelier'], 'subnet_id' => $ids['subnets']['tunnel']],
            'A subnet without its subnet' => ['node' => 'subnet'],
            'A subnet with a device' => ['node' => 'subnet', 'subnet_id' => $ids['subnets']['tunnel'], 'device_id' => $ids['devices']['sw-atelier']],
            'The internet with a device' => ['node' => 'internet', 'device_id' => $ids['devices']['sw-atelier']],
            'The internet with a subnet' => ['node' => 'internet', 'subnet_id' => $ids['subnets']['tunnel']],
            'A node of no known kind' => ['node' => 'vlan'],
            'A node of no known kind, with a device' => ['node' => 'vlan', 'device_id' => $ids['devices']['sw-atelier']],
        ] as $what => $columns) {
            $this->assertRefusedByTheDatabase(fn () => $this->position($ids['sites']['Atelier'], $columns), $what);
        }

        // A subnet needs to exist.
        $this->assertRefusedByTheDatabase(fn () => $this->position($office, ['node' => 'subnet', 'subnet_id' => 999999999]), 'A position of a subnet that does not exist');

        $this->assertSame(0, DB::table('diagram_positions')->where('site_id', $ids['sites']['Atelier'])->where('view', 'logical')->count());
    }

    public function test_the_database_itself_keeps_one_place_per_subnet_and_one_internet_per_diagram(): void
    {
        $ids = $this->documentTheNetwork();

        $this->artisan('migrate')->assertSuccessful();

        $office = $ids['sites']['Siège'];
        $workshop = $ids['sites']['Atelier'];
        $subnet = $ids['subnets']['operateur'];

        $this->position($office, ['node' => 'subnet', 'subnet_id' => $subnet]);
        $this->position($office, ['node' => 'internet']);

        $this->assertRefusedByTheDatabase(
            fn () => $this->position($office, ['node' => 'subnet', 'subnet_id' => $subnet]),
            'A second place for a subnet on one diagram',
            UniqueConstraintViolationException::class,
        );
        $this->assertRefusedByTheDatabase(
            fn () => $this->position($office, ['node' => 'internet']),
            'A second internet on one diagram',
            UniqueConstraintViolationException::class,
        );

        // On another view, or on the diagram of another site, they have a
        // place of their own: the database keeps the views apart, what each
        // one draws is the business of the application. And the subnets and
        // devices of one diagram are as many as they need, whatever the
        // internet.
        $this->position($office, ['node' => 'subnet', 'subnet_id' => $subnet], 'physical');
        $this->position($office, ['node' => 'internet'], 'physical');
        $this->position($workshop, ['node' => 'subnet', 'subnet_id' => $subnet]);
        $this->position($workshop, ['node' => 'internet']);
        $this->position($office, ['node' => 'subnet', 'subnet_id' => $ids['subnets']['bureautique']]);
        $this->position($office, ['node' => 'subnet', 'subnet_id' => $ids['subnets']['tunnel']]);
        $this->position($office, ['node' => 'device', 'device_id' => $ids['devices']['sw-coeur']]);

        $this->assertSame(1, DB::table('diagram_positions')->where('site_id', $office)->where('view', 'logical')->where('node', 'internet')->count());
        $this->assertSame(3, DB::table('diagram_positions')->where('internet_node', 1)->count());

        // The place of a device is still one per diagram.
        $this->assertRefusedByTheDatabase(
            fn () => $this->position($office, ['node' => 'device', 'device_id' => $ids['devices']['sw-coeur']]),
            'A second place for a device on one diagram',
            UniqueConstraintViolationException::class,
        );

        // A subnet deleted takes its places along, on every diagram.
        DB::table('subnets')->where('id', $subnet)->delete();

        $this->assertSame(0, DB::table('diagram_positions')->where('subnet_id', $subnet)->count());
        $this->assertSame(3, DB::table('diagram_positions')->where('internet_node', 1)->count());

        // A site deleted, once its devices are, takes the internet of its
        // diagrams along.
        DB::table('devices')->where('site_id', $workshop)->delete();
        DB::table('sites')->where('id', $workshop)->delete();

        $this->assertSame(2, DB::table('diagram_positions')->where('internet_node', 1)->count());
    }

    public function test_going_back_loses_the_positions_of_the_subnets_and_of_the_internet_alone(): void
    {
        $ids = $this->documentTheNetwork();

        $before = $this->everything();

        $this->artisan('migrate')->assertSuccessful();

        $office = $ids['sites']['Siège'];

        $this->position($office, ['node' => 'subnet', 'subnet_id' => $ids['subnets']['operateur']]);
        $this->position($office, ['node' => 'internet']);

        $this->rollBackToTheFormerVersion();

        // The schema of version 0.14.0, and the positions of the devices as
        // they were.
        $this->assertSame(self::FORMER_COLUMNS, Schema::getColumnListing('diagram_positions'));
        $this->assertFalse(collect(Schema::getColumns('diagram_positions'))->firstWhere('name', 'device_id')['nullable']);
        $this->assertEquals($before, $this->everything());

        // And up again: the positions of the devices are still there.
        $this->artisan('migrate')->assertSuccessful();

        $this->assertSame(4, DB::table('diagram_positions')->where('node', 'device')->count());
        $this->assertSame(0, DB::table('diagram_positions')->where('node', '!=', 'device')->count());
    }
}
