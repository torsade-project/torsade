<?php

// torsade/tests/Feature/CablingPathsUpgradeTest.php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * An installation of version 0.18.0 is upgraded: the jack cords are added,
 * which plug a port of a device into a wall jack, the patch panels can be
 * placed by hand on the physical diagram, and the addressing rule may have
 * no level of sites. Nothing the former version holds is lost, and going
 * back loses only what the version adds.
 *
 * These tests change the schema, which no transaction can undo: each one
 * rebuilds the database before and after itself.
 */
class CablingPathsUpgradeTest extends TestCase
{
    /**
     * The first migration of the version: everything from it on is rolled
     * back to get the schema of version 0.18.0.
     */
    private const string FIRST_MIGRATION = '2026_10_19_100000_create_jack_cords_table';

    /**
     * The columns of the positions in version 0.18.0: the version adds the
     * one of the panel.
     *
     * @var list<string>
     */
    private const array FORMER_POSITION_COLUMNS = ['id', 'site_id', 'view', 'node', 'device_id', 'subnet_id', 'internet_node', 'x', 'y', 'created_at', 'updated_at'];

    /**
     * The tables of version 0.18.0 the version touches or refers to, and
     * the activity log that tells their story.
     *
     * @var list<string>
     */
    private const array TABLES = [
        'sites', 'buildings', 'floors', 'rooms', 'devices', 'device_members', 'ports',
        'links', 'link_ends', 'patch_panels', 'patch_panel_ports', 'jacks', 'patch_cords',
        'address_blocks', 'addressing_rules', 'addressing_rule_ranges', 'diagram_positions', 'activity_log',
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
     * Fill the database the way version 0.18.0 would have: a site with a
     * floor, a technical room and its panel, two jacks cabled to it, a
     * switch patched to the first one and linked to an access point, the
     * switch placed by hand on the physical diagram, the usual addressing
     * rule with its ranges, and what the activity log tells of the cord.
     *
     * @return array{site: int, panel: int, jacks: array<string, int>, ports: array<string, int>, rule: int, devices: array<string, int>}
     */
    private function documentTheNetwork(): array
    {
        $stamps = ['created_at' => now(), 'updated_at' => now()];

        $site = (int) DB::table('sites')->insertGetId(['name' => 'Siège', 'code' => 'SIE', 'number' => 10, 'status' => 'active', 'description' => null, ...$stamps]);
        $building = (int) DB::table('buildings')->insertGetId(['site_id' => $site, 'name' => 'Bâtiment principal', 'code' => null, 'description' => null, ...$stamps]);
        $floor = (int) DB::table('floors')->insertGetId(['building_id' => $building, 'level' => 1, 'name' => '1er étage', 'description' => null, ...$stamps]);
        $room = (int) DB::table('rooms')->insertGetId(['floor_id' => $floor, 'name' => 'Placard technique', 'is_technical' => true, 'description' => null, ...$stamps]);

        $panel = (int) DB::table('patch_panels')->insertGetId([
            'room_id' => $room, 'name' => 'PB-B', 'port_count' => 2, 'connector' => 'rj45', 'category' => '6A', 'description' => null, 'status' => 'active', ...$stamps,
        ]);

        $panelPorts = [];

        foreach ([1, 2] as $number) {
            $panelPorts[$number] = (int) DB::table('patch_panel_ports')->insertGetId(['patch_panel_id' => $panel, 'number' => $number, ...$stamps]);
        }

        $jacks = [];

        foreach (['B.13' => 1, 'B.14' => 2] as $label => $number) {
            $jacks[$label] = (int) DB::table('jacks')->insertGetId([
                'floor_id' => $floor, 'label' => $label, 'location' => null, 'patch_panel_port_id' => $panelPorts[$number], 'description' => null, 'status' => 'active', ...$stamps,
            ]);
        }

        $ports = [];
        $devices = [];

        foreach ([['sw-etage-1', 'switch', ['1/0/21', '1/0/22']], ['ap-etage-1', 'access_point', ['eth0']]] as [$name, $type, $names]) {
            $device = $devices[$name] = (int) DB::table('devices')->insertGetId([
                'site_id' => $site, 'room_id' => $type === 'switch' ? $room : null, 'name' => $name, 'type' => $type,
                'manufacturer' => null, 'model' => null, 'serial_number' => null, 'description' => null, 'status' => 'active', ...$stamps,
            ]);
            $member = (int) DB::table('device_members')->insertGetId([
                'device_id' => $device, 'position' => 1, 'role' => 'primary', 'device_template_id' => null, 'serial_number' => null, 'room_id' => null, 'description' => null, ...$stamps,
            ]);

            foreach ($names as $order => $portName) {
                $ports[$name.' '.$portName] = (int) DB::table('ports')->insertGetId([
                    'device_member_id' => $member, 'device_id' => $device, 'name' => $portName, 'position' => $order + 1,
                    'connector' => 'rj45', 'max_speed' => '1g', 'poe_type' => 'none', 'poe_label' => null, 'enabled' => true,
                    'mode' => null, 'port_aggregate_id' => null, 'description' => null, ...$stamps,
                ]);
            }
        }

        DB::table('patch_cords')->insert(['patch_panel_port_id' => $panelPorts[1], 'port_id' => $ports['sw-etage-1 1/0/21'], 'color' => 'Bleu', 'description' => null, ...$stamps]);

        $link = (int) DB::table('links')->insertGetId(['medium' => 'copper', 'description' => null, 'status' => 'active', ...$stamps]);

        DB::table('link_ends')->insert([
            ['link_id' => $link, 'port_id' => $ports['sw-etage-1 1/0/21'], 'end' => 1, ...$stamps],
            ['link_id' => $link, 'port_id' => $ports['ap-etage-1 eth0'], 'end' => 2, ...$stamps],
        ]);

        DB::table('diagram_positions')->insert(['site_id' => $site, 'view' => 'physical', 'node' => 'device', 'device_id' => $devices['sw-etage-1'], 'subnet_id' => null, 'x' => 0, 'y' => 0, ...$stamps]);

        $block = (int) DB::table('address_blocks')->insertGetId([
            'site_id' => null, 'ip_version' => 4, 'network' => inet_pton('10.0.0.0'), 'prefix_length' => 8, 'name' => 'Adressage interne', 'description' => null, 'status' => 'active', ...$stamps,
        ]);
        $rule = (int) DB::table('addressing_rules')->insertGetId(['aggregate_block_id' => $block, 'site_prefix_length' => 16, 'vlan_prefix_length' => 24, 'gateway_position' => 'first', ...$stamps]);

        DB::table('addressing_rule_ranges')->insert(['addressing_rule_id' => $rule, 'kind' => 'dhcp', 'start_offset' => 100, 'end_offset' => 200, 'name' => null, ...$stamps]);

        DB::table('activity_log')->insert([
            'log_name' => 'default', 'description' => 'patch_cord.created', 'subject_type' => 'App\\Models\\PatchCord', 'subject_id' => 1, 'event' => 'patch_cord.created',
            'causer_type' => null, 'causer_id' => null,
            'attribute_changes' => json_encode(['old' => [], 'attributes' => ['panel_port' => 'Bâtiment principal › 1er étage › Placard technique › PB-B 1', 'port' => 'sw-etage-1 (Siège) › 1/0/21']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'properties' => json_encode(['source' => 'web', 'label' => 'PB-B 1 ↔ sw-etage-1 (Siège) › 1/0/21'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ...$stamps,
        ]);

        return ['site' => $site, 'panel' => $panel, 'jacks' => $jacks, 'ports' => $ports, 'rule' => $rule, 'devices' => $devices];
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
            $tables[$table] = DB::table($table)
                ->when($table === 'diagram_positions', fn ($query) => $query->select(self::FORMER_POSITION_COLUMNS))
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all();
        }

        return $tables;
    }

    /**
     * @return array<string, mixed>
     */
    private function cord(int $jack, int $port): array
    {
        return ['jack_id' => $jack, 'port_id' => $port, 'color' => null, 'description' => null, 'created_at' => now(), 'updated_at' => now()];
    }

    public function test_the_jack_cords_are_added_and_nothing_of_the_former_version_is_lost(): void
    {
        $this->assertFalse(Schema::hasTable('jack_cords'));

        $network = $this->documentTheNetwork();
        $before = $this->everything();

        $this->artisan('migrate')->assertSuccessful();

        $this->assertSame($before, $this->everything());
        $this->assertSame(0, DB::table('jack_cords')->count());

        // The access point is plugged into its jack.
        DB::table('jack_cords')->insert($this->cord($network['jacks']['B.13'], $network['ports']['ap-etage-1 eth0']));

        // One cord per jack, one per port, whichever request comes second.
        $this->assertRefusedAsDuplicate(fn () => DB::table('jack_cords')->insert($this->cord($network['jacks']['B.13'], $network['ports']['sw-etage-1 1/0/22'])));
        $this->assertRefusedAsDuplicate(fn () => DB::table('jack_cords')->insert($this->cord($network['jacks']['B.14'], $network['ports']['ap-etage-1 eth0'])));

        // A jack or a port plugged in is not deleted while its cord is there.
        foreach ([['jacks', $network['jacks']['B.13']], ['ports', $network['ports']['ap-etage-1 eth0']]] as [$table, $id]) {
            try {
                DB::table($table)->where('id', $id)->delete();

                $this->fail("A row of {$table} that a cord holds was deleted.");
            } catch (QueryException $exception) {
                $this->assertStringContainsString('foreign key', strtolower($exception->getMessage()));
            }
        }
    }

    public function test_going_back_loses_only_the_jack_cords(): void
    {
        $network = $this->documentTheNetwork();

        $this->artisan('migrate')->assertSuccessful();

        DB::table('jack_cords')->insert($this->cord($network['jacks']['B.13'], $network['ports']['ap-etage-1 eth0']));

        $before = $this->everything();

        $this->rollBackToTheFormerVersion();

        $this->assertFalse(Schema::hasTable('jack_cords'));
        $this->assertSame($before, $this->everything());
    }

    public function test_a_patch_panel_is_placed_on_the_physical_diagram_only_once_and_going_back_loses_its_place_alone(): void
    {
        $network = $this->documentTheNetwork();

        $this->artisan('migrate')->assertSuccessful();

        $position = fn (array $row): array => ['site_id' => $network['site'], 'x' => 8, 'y' => 8, 'created_at' => now(), 'updated_at' => now(), 'device_id' => null, 'subnet_id' => null, ...$row];

        DB::table('diagram_positions')->insert($position(['view' => 'physical', 'node' => 'patch_panel', 'patch_panel_id' => $network['panel']]));

        // Once per diagram, on the physical one only, with its panel alone.
        foreach ([
            ['view' => 'physical', 'node' => 'patch_panel', 'patch_panel_id' => $network['panel']],
            ['view' => 'logical', 'node' => 'patch_panel', 'patch_panel_id' => $network['panel']],
            ['view' => 'physical', 'node' => 'patch_panel', 'patch_panel_id' => null],
            ['view' => 'physical', 'node' => 'device', 'device_id' => $network['devices']['ap-etage-1'], 'patch_panel_id' => $network['panel']],
        ] as $row) {
            try {
                DB::table('diagram_positions')->insert($position($row));

                $this->fail('A position was written: '.json_encode($row));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(2, DB::table('diagram_positions')->count());

        // Going back: the place of the panel goes, the one of the switch
        // stays, and a panel can no longer be placed.
        $this->rollBackToTheFormerVersion();

        $this->assertFalse(Schema::hasColumn('diagram_positions', 'patch_panel_id'));
        $this->assertSame([[$network['devices']['sw-etage-1'], 'device']], DB::table('diagram_positions')->get()->map(fn (object $row): array => [$row->device_id, $row->node])->all());

        try {
            DB::table('diagram_positions')->insert(['site_id' => $network['site'], 'view' => 'physical', 'node' => 'patch_panel', 'device_id' => null, 'subnet_id' => null, 'x' => 0, 'y' => 0]);

            $this->fail('A panel was placed in the former version.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_the_addressing_rule_keeps_its_level_of_sites_and_may_lose_it(): void
    {
        $nullable = fn (): bool => (bool) collect(Schema::getColumns('addressing_rules'))->firstWhere('name', 'site_prefix_length')['nullable'];

        $this->assertFalse($nullable());

        $network = $this->documentTheNetwork();

        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue($nullable());
        $this->assertSame(16, (int) DB::table('addressing_rules')->where('id', $network['rule'])->value('site_prefix_length'));

        // The rule in place can then go without a level of sites.
        DB::table('addressing_rules')->where('id', $network['rule'])->update(['site_prefix_length' => null]);

        $this->assertNull(DB::table('addressing_rules')->where('id', $network['rule'])->value('site_prefix_length'));
    }

    public function test_going_back_deletes_a_rule_without_a_level_of_sites_and_nothing_else(): void
    {
        $network = $this->documentTheNetwork();

        $this->artisan('migrate')->assertSuccessful();

        DB::table('addressing_rules')->where('id', $network['rule'])->update(['site_prefix_length' => null]);

        $before = $this->everything();

        $this->rollBackToTheFormerVersion();

        // The former version cannot write such a rule: it is gone, with its
        // ranges, as when it is deleted from its page. The block it started
        // from stays.
        $after = $this->everything();

        $this->assertSame([], $after['addressing_rules']);
        $this->assertSame([], $after['addressing_rule_ranges']);
        $this->assertCount(1, $after['address_blocks']);

        unset($before['addressing_rules'], $before['addressing_rule_ranges'], $after['addressing_rules'], $after['addressing_rule_ranges']);

        $this->assertSame($before, $after);
    }

    private function assertRefusedAsDuplicate(callable $write): void
    {
        try {
            $write();

            $this->fail('A second cord was written.');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }
    }
}
