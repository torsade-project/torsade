<?php

// torsade/tests/Feature/DeviceLinksUpgradeTest.php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Port;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * An installation of version 0.8.0 is upgraded: the links between devices
 * and their ends are added to a database that already holds devices with
 * their members, their ports, their aggregates and the VLANs they carry.
 * Nothing of it is lost, and going back loses nothing the former version
 * knew.
 *
 * These tests change the schema, which no transaction can undo: each one
 * rebuilds the database before and after itself.
 */
class DeviceLinksUpgradeTest extends TestCase
{
    /**
     * The first migration of the version: everything from it on is rolled
     * back to get the schema of version 0.8.0.
     */
    private const string FIRST_MIGRATION = '2026_10_13_100000_create_links_table';

    /**
     * The tables of the network documentation in version 0.8.0, and the
     * activity log that tells its story.
     *
     * @var list<string>
     */
    private const array TABLES = [
        'sites', 'catalog_vlans', 'vlans', 'address_blocks', 'subnets', 'ip_addresses', 'buildings', 'floors', 'rooms', 'devices',
        'device_templates', 'device_template_port_groups', 'device_members', 'port_aggregates', 'ports', 'port_vlans', 'activity_log',
    ];

    /**
     * The tables the version adds.
     *
     * @var list<string>
     */
    private const array NEW_TABLES = ['links', 'link_ends'];

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
     * Fill the database the way version 0.8.0 would have: two sites, a
     * stack with an aggregate over its two members, a core switch with an
     * aggregate of its own and a firewall, with the VLANs their ports
     * carry and what the activity log tells of them.
     *
     * @return array{devices: array<string, int>, members: array<string, int>, ports: array<string, int>, aggregates: array<string, int>}
     */
    private function documentTheNetwork(): array
    {
        $now = now();
        $stamps = ['created_at' => $now, 'updated_at' => $now];

        $sites = [];

        foreach ([['Siège', 'SIE', 10], ['Atelier', 'ATE', 20]] as [$name, $code, $number]) {
            $sites[$name] = (int) DB::table('sites')->insertGetId(['name' => $name, 'code' => $code, 'number' => $number, 'status' => 'active', 'description' => null, ...$stamps]);
        }

        $entry = (int) DB::table('catalog_vlans')->insertGetId(['vid' => 10, 'name' => 'Bureautique', 'description' => null, ...$stamps]);

        $vlans = [];

        foreach ([['Siège', 10, 'Bureautique', $entry], ['Siège', 20, 'Serveurs', null], ['Siège', 99, 'Administration', null], ['Atelier', 10, 'Bureautique', $entry]] as [$site, $vid, $name, $catalog]) {
            $vlans[$site.'/'.$vid] = (int) DB::table('vlans')->insertGetId([
                'site_id' => $sites[$site], 'catalog_vlan_id' => $catalog,
                'vid' => $vid, 'name' => $name, 'description' => null, 'status' => 'active', ...$stamps,
            ]);
        }

        $block = (int) DB::table('address_blocks')->insertGetId([
            'site_id' => $sites['Siège'], 'ip_version' => 4, 'network' => inet_pton('10.10.0.0'), 'prefix_length' => 16,
            'name' => null, 'description' => null, 'status' => 'active', ...$stamps,
        ]);

        $this->assertGreaterThan(0, $block);

        $subnet = (int) DB::table('subnets')->insertGetId([
            'vlan_id' => $vlans['Siège/99'], 'site_id' => $sites['Siège'], 'kind' => 'segment', 'ip_version' => 4,
            'network' => inet_pton('10.10.99.0'), 'prefix_length' => 24, 'gateway' => inet_pton('10.10.99.254'),
            'name' => null, 'description' => null, 'status' => 'active', ...$stamps,
        ]);

        $building = (int) DB::table('buildings')->insertGetId(['site_id' => $sites['Siège'], 'name' => 'Bâtiment principal', 'code' => 'A', 'description' => null, ...$stamps]);
        $floor = (int) DB::table('floors')->insertGetId(['building_id' => $building, 'level' => 0, 'name' => 'Rez-de-chaussée', 'description' => null, ...$stamps]);
        $room = (int) DB::table('rooms')->insertGetId(['floor_id' => $floor, 'name' => 'Local technique', 'is_technical' => true, 'description' => null, ...$stamps]);

        $devices = [];

        foreach ([
            ['Siège', $room, 'sw-serveurs', 'switch', null, 'active'],
            ['Siège', $room, 'sw-coeur', 'switch', null, 'active'],
            ['Siège', $room, 'fw-siege', 'firewall', 'SN-FW-1', 'active'],
            ['Atelier', null, 'sw-atelier', 'switch', null, 'planned'],
        ] as [$site, $where, $name, $type, $serial, $status]) {
            $devices[$name] = (int) DB::table('devices')->insertGetId([
                'site_id' => $sites[$site], 'room_id' => $where, 'name' => $name, 'type' => $type,
                'manufacturer' => null, 'model' => null, 'serial_number' => $serial, 'description' => null, 'status' => $status, ...$stamps,
            ]);
        }

        foreach ([['10.10.99.11', 'sw-serveurs'], ['10.10.99.254', 'fw-siege']] as [$address, $device]) {
            DB::table('ip_addresses')->insert([
                'subnet_id' => $subnet, 'address' => inet_pton($address), 'hostname' => $device, 'mac_address' => null,
                'device_type' => null, 'assignment' => 'static', 'device_id' => $devices[$device],
                'description' => null, 'status' => 'active', ...$stamps,
            ]);
        }

        $template = (int) DB::table('device_templates')->insertGetId([
            'name' => 'Switch 24 ports', 'device_type' => 'switch', 'manufacturer' => null, 'model' => null,
            'poe_budget_watts' => 370, 'description' => null, ...$stamps,
        ]);

        DB::table('device_template_port_groups')->insert([
            'device_template_id' => $template, 'position' => 1, 'quantity' => 24, 'name_pattern' => '{m}/0/{n}', 'first_number' => 1,
            'connector' => 'rj45', 'max_speed' => '1g', 'poe_type' => '802.3at', 'poe_label' => null, ...$stamps,
        ]);

        // A stack of two members, a switch alone, a firewall: a device of
        // the workshop has no member yet.
        $members = [];

        foreach ([['sw-serveurs', 1, 'primary', $template], ['sw-serveurs', 2, 'secondary', $template], ['sw-coeur', 1, 'primary', $template], ['fw-siege', 1, 'primary', null]] as [$device, $position, $role, $from]) {
            $members[$device.'/'.$position] = (int) DB::table('device_members')->insertGetId([
                'device_id' => $devices[$device], 'position' => $position, 'role' => $role, 'device_template_id' => $from,
                'serial_number' => 'SN-'.$device.'-'.$position, 'room_id' => null, 'description' => null, ...$stamps,
            ]);
        }

        $aggregates = [];

        foreach ([['sw-serveurs', 'Po1'], ['sw-coeur', 'Po1']] as [$device, $name]) {
            $aggregates[$device.'/'.$name] = (int) DB::table('port_aggregates')->insertGetId([
                'device_id' => $devices[$device], 'name' => $name, 'protocol' => 'lacp', 'mode' => 'trunk', 'description' => null, ...$stamps,
            ]);
        }

        $ports = [];

        foreach ([
            ['sw-serveurs', 1, '1/0/1', 'access', null, true],
            ['sw-serveurs', 1, '1/0/24', null, 'Po1', true],
            ['sw-serveurs', 2, '2/0/1', null, null, false],
            ['sw-serveurs', 2, '2/0/24', null, 'Po1', true],
            ['sw-coeur', 1, '1/0/1', null, 'Po1', true],
            ['sw-coeur', 1, '1/0/2', null, 'Po1', true],
            ['sw-coeur', 1, '1/0/24', 'trunk', null, true],
            ['fw-siege', 1, 'port1', null, null, true],
            ['fw-siege', 1, 'port2', null, null, true],
        ] as $order => [$device, $member, $name, $mode, $aggregate, $enabled]) {
            $ports[$device.'/'.$name] = (int) DB::table('ports')->insertGetId([
                'device_member_id' => $members[$device.'/'.$member], 'device_id' => $devices[$device], 'name' => $name, 'position' => $order + 1,
                'connector' => 'rj45', 'max_speed' => '1g', 'poe_type' => 'none', 'poe_label' => null, 'enabled' => $enabled,
                'mode' => $mode, 'port_aggregate_id' => $aggregate === null ? null : $aggregates[$device.'/'.$aggregate],
                'description' => $enabled ? null : 'Prise condamnée', ...$stamps,
            ]);
        }

        DB::table('port_vlans')->insert([
            // An access port with its VLAN.
            ['port_id' => $ports['sw-serveurs/1/0/1'], 'port_aggregate_id' => null, 'vlan_id' => $vlans['Siège/20'], 'tagged' => false, ...$stamps],
            // A trunk of its own on the core switch.
            ['port_id' => $ports['sw-coeur/1/0/24'], 'port_aggregate_id' => null, 'vlan_id' => $vlans['Siège/99'], 'tagged' => false, ...$stamps],
            ['port_id' => $ports['sw-coeur/1/0/24'], 'port_aggregate_id' => null, 'vlan_id' => $vlans['Siège/10'], 'tagged' => true, ...$stamps],
            // The trunks of the two aggregates.
            ['port_id' => null, 'port_aggregate_id' => $aggregates['sw-serveurs/Po1'], 'vlan_id' => $vlans['Siège/99'], 'tagged' => false, ...$stamps],
            ['port_id' => null, 'port_aggregate_id' => $aggregates['sw-serveurs/Po1'], 'vlan_id' => $vlans['Siège/20'], 'tagged' => true, ...$stamps],
            ['port_id' => null, 'port_aggregate_id' => $aggregates['sw-coeur/Po1'], 'vlan_id' => $vlans['Siège/99'], 'tagged' => false, ...$stamps],
        ]);

        // What the former version wrote about a member created with its
        // ports and about a port.
        foreach ([
            ['device_member.created', 'App\\Models\\DeviceMember', $members['sw-serveurs/1'], [], ['device' => 'sw-serveurs (Siège)', 'position' => 1, 'role' => 'primary'], 'sw-serveurs (Siège) › 1'],
            ['port.created', 'App\\Models\\Port', $ports['sw-serveurs/1/0/1'], [], ['name' => '1/0/1', 'connector' => 'rj45'], 'sw-serveurs (Siège) › 1/0/1'],
            ['port.updated', 'App\\Models\\Port', $ports['sw-serveurs/2/0/1'], ['enabled' => true], ['enabled' => false], 'sw-serveurs (Siège) › 2/0/1'],
        ] as [$event, $type, $id, $old, $attributes, $label]) {
            DB::table('activity_log')->insert([
                'log_name' => 'default', 'description' => $event, 'subject_type' => $type, 'subject_id' => $id, 'event' => $event,
                'causer_type' => null, 'causer_id' => null,
                'attribute_changes' => json_encode(['old' => $old, 'attributes' => $attributes], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'properties' => json_encode(['source' => 'web', 'label' => $label], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ...$stamps,
            ]);
        }

        return ['devices' => $devices, 'members' => $members, 'ports' => $ports, 'aggregates' => $aggregates];
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

        // The jack of an address is a column of a later version than 0.9.0:
        // what this version knew of an address is the rest.
        $tables['ip_addresses'] = array_map(fn (array $row): array => array_diff_key($row, ['jack_id' => true]), $tables['ip_addresses']);

        return $tables;
    }

    /**
     * A link between two ports, written straight to its tables.
     *
     * @param  array<string, int>  $ports
     * @param  array<string, mixed>  $values
     */
    private function link(array $ports, string $first, string $second, array $values = []): int
    {
        $stamps = ['created_at' => now(), 'updated_at' => now()];

        $link = (int) DB::table('links')->insertGetId(['medium' => null, 'description' => null, 'status' => 'active', ...$values, ...$stamps]);

        DB::table('link_ends')->insert([
            ['link_id' => $link, 'port_id' => $ports[$first], 'end' => 1, ...$stamps],
            ['link_id' => $link, 'port_id' => $ports[$second], 'end' => 2, ...$stamps],
        ]);

        return $link;
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

    public function test_the_former_schema_is_the_one_of_the_former_version(): void
    {
        foreach (self::NEW_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }

        // What version 0.8.0 had is there.
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertTrue(Schema::hasColumn('ports', 'port_aggregate_id'));
        $this->assertTrue(Schema::hasColumn('ports', 'device_id'));
    }

    public function test_the_upgrade_keeps_every_object_as_it_was(): void
    {
        $this->documentTheNetwork();

        $before = $this->everything();

        $this->assertSame(
            [
                'sites' => 2, 'catalog_vlans' => 1, 'vlans' => 4, 'address_blocks' => 1, 'subnets' => 1, 'ip_addresses' => 2,
                'buildings' => 1, 'floors' => 1, 'rooms' => 1, 'devices' => 4,
                'device_templates' => 1, 'device_template_port_groups' => 1, 'device_members' => 4, 'port_aggregates' => 2, 'ports' => 9, 'port_vlans' => 6,
                'activity_log' => 3,
            ],
            array_map(count(...), $before),
        );

        $this->artisan('migrate')->assertSuccessful();

        // No table of the former version gets a column: what they held is
        // there, column for column and byte for byte, the log included.
        $this->assertEquals($before, $this->everything());

        // The new tables start empty: no port is linked.
        foreach (self::NEW_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }

        // The devices are read as before, with their members and their
        // ports, none of which is the end of a link.
        $this->assertSame(
            ['fw-siege' => 2, 'sw-atelier' => 0, 'sw-coeur' => 3, 'sw-serveurs' => 4],
            Device::query()->withCount('ports')->orderBy('name')->pluck('ports_count', 'name')->all(),
        );
        $this->assertSame(0, Port::query()->has('linkEnd')->count());
        $this->assertSame(4, Port::query()->whereNotNull('port_aggregate_id')->count());
    }

    public function test_the_database_itself_keeps_a_port_in_one_link_at_most(): void
    {
        $ids = $this->documentTheNetwork();
        $ports = $ids['ports'];

        $this->artisan('migrate')->assertSuccessful();

        $link = $this->link($ports, 'sw-serveurs/1/0/24', 'sw-coeur/1/0/1');
        $stamps = ['created_at' => now(), 'updated_at' => now()];
        $unique = UniqueConstraintViolationException::class;

        // A port already linked is the end of no other link, whether it is
        // the first end of its link or the second, and whichever end it
        // would be of the new one.
        $other = (int) DB::table('links')->insertGetId(['status' => 'active', ...$stamps]);

        foreach (['sw-serveurs/1/0/24', 'sw-coeur/1/0/1'] as $taken) {
            foreach ([1, 2] as $end) {
                $this->assertRefusedByTheDatabase(
                    fn () => DB::table('link_ends')->insert(['link_id' => $other, 'port_id' => $ports[$taken], 'end' => $end, ...$stamps]),
                    'A second link on the port '.$taken.', as its end '.$end,
                    $unique,
                );
            }
        }

        // A link has one end 1 and one end 2: no third end, and no end of
        // another number.
        $this->assertRefusedByTheDatabase(
            fn () => DB::table('link_ends')->insert(['link_id' => $link, 'port_id' => $ports['fw-siege/port1'], 'end' => 1, ...$stamps]),
            'A second end 1 on a link',
            $unique,
        );
        $this->assertRefusedByTheDatabase(
            fn () => DB::table('link_ends')->insert(['link_id' => $link, 'port_id' => $ports['fw-siege/port1'], 'end' => 2, ...$stamps]),
            'A second end 2 on a link',
            $unique,
        );

        foreach ([0, 3] as $end) {
            $this->assertRefusedByTheDatabase(
                fn () => DB::table('link_ends')->insert(['link_id' => $other, 'port_id' => $ports['fw-siege/port1'], 'end' => $end, ...$stamps]),
                'An end numbered '.$end,
            );
        }

        // An end is a port that exists, on a link that exists.
        $this->assertRefusedByTheDatabase(
            fn () => DB::table('link_ends')->insert(['link_id' => $other, 'port_id' => 999999, 'end' => 1, ...$stamps]),
            'An end on a port that does not exist',
        );
        $this->assertRefusedByTheDatabase(
            fn () => DB::table('link_ends')->insert(['link_id' => 999999, 'port_id' => $ports['fw-siege/port1'], 'end' => 1, ...$stamps]),
            'An end of a link that does not exist',
        );

        $this->assertSame(2, DB::table('link_ends')->count());

        // A linked port is not deleted, nor its member nor its device,
        // whose deletion would take it along.
        DB::table('ip_addresses')->update(['device_id' => null]);

        foreach ([
            ['ports', $ports['sw-serveurs/1/0/24']],
            ['ports', $ports['sw-coeur/1/0/1']],
            ['device_members', $ids['members']['sw-serveurs/1']],
            ['device_members', $ids['members']['sw-coeur/1']],
            ['devices', $ids['devices']['sw-serveurs']],
            ['devices', $ids['devices']['sw-coeur']],
        ] as [$table, $id]) {
            $this->assertRefusedByTheDatabase(fn () => DB::table($table)->where('id', $id)->delete(), 'A row of "'.$table.'" that holds a linked port');
            $this->assertTrue(DB::table($table)->where('id', $id)->exists());
        }

        $this->assertSame(9, DB::table('ports')->count());

        // What holds no linked port goes as before.
        DB::table('ports')->where('id', $ports['sw-serveurs/2/0/1'])->delete();
        DB::table('device_members')->where('id', $ids['members']['sw-serveurs/2'])->delete();
        DB::table('devices')->where('id', $ids['devices']['fw-siege'])->delete();

        $this->assertSame(5, DB::table('ports')->count());
    }

    public function test_a_link_deleted_frees_its_ports_and_leaves_them_there(): void
    {
        $ids = $this->documentTheNetwork();
        $ports = $ids['ports'];

        $this->artisan('migrate')->assertSuccessful();

        $before = $this->everything();

        $first = $this->link($ports, 'sw-serveurs/1/0/24', 'sw-coeur/1/0/1', ['medium' => 'direct_attach']);
        $second = $this->link($ports, 'sw-serveurs/2/0/24', 'sw-coeur/1/0/2', ['medium' => 'direct_attach', 'status' => 'planned']);
        // A link between two sites would be written the same way: nothing
        // in the tables knows of sites.
        $this->link($ports, 'fw-siege/port1', 'sw-coeur/1/0/24', ['description' => 'Uplink of the firewall']);

        $this->assertSame([3, 6], [DB::table('links')->count(), DB::table('link_ends')->count()]);

        // The ends go with their link; the ports stay, and nothing else
        // changes.
        DB::table('links')->where('id', $first)->delete();

        $this->assertSame([2, 4], [DB::table('links')->count(), DB::table('link_ends')->count()]);
        $this->assertSame(0, DB::table('link_ends')->where('link_id', $first)->count());
        $this->assertEquals($before, $this->everything());

        // A port freed can be linked again, and deleted.
        $this->link($ports, 'sw-serveurs/1/0/24', 'fw-siege/port2');

        DB::table('links')->where('id', $second)->delete();
        DB::table('ports')->where('id', $ports['sw-serveurs/2/0/24'])->delete();

        $this->assertSame(8, DB::table('ports')->count());
        $this->assertSame([2, 4], [DB::table('links')->count(), DB::table('link_ends')->count()]);
    }

    public function test_going_back_keeps_what_the_former_version_knew(): void
    {
        $ids = $this->documentTheNetwork();

        $before = $this->everything();

        $this->artisan('migrate')->assertSuccessful();

        // What the new version adds: links, one of them between two
        // aggregates.
        $this->link($ids['ports'], 'sw-serveurs/1/0/24', 'sw-coeur/1/0/1');
        $this->link($ids['ports'], 'sw-serveurs/2/0/24', 'sw-coeur/1/0/2');
        $this->link($ids['ports'], 'fw-siege/port1', 'sw-coeur/1/0/24', ['medium' => 'copper', 'status' => 'retired']);

        $this->rollBackToTheFormerVersion();

        // The links are gone with their tables; everything else is as it
        // was, the ports that were linked included.
        foreach (self::NEW_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }

        $this->assertEquals($before, $this->everything());

        // And the upgrade can be run again.
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEquals($before, $this->everything());

        foreach (self::NEW_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }
}
