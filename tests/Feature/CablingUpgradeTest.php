<?php

// torsade/tests/Feature/CablingUpgradeTest.php

namespace Tests\Feature;

use App\Models\IpAddress;
use App\Models\Link;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * An installation of version 0.9.0 is upgraded: the patch panels and their
 * ports, the wall jacks, the patch cords and the jack of an address are
 * added to a database that already holds a network with its places, its
 * devices, their ports and their links. Nothing of it is lost, and going
 * back loses nothing the former version knew.
 *
 * These tests change the schema, which no transaction can undo: each one
 * rebuilds the database before and after itself.
 */
class CablingUpgradeTest extends TestCase
{
    /**
     * The first migration of the version: everything from it on is rolled
     * back to get the schema of version 0.9.0.
     */
    private const string FIRST_MIGRATION = '2026_10_14_100000_create_patch_panels_table';

    /**
     * The tables of the network documentation in version 0.9.0, and the
     * activity log that tells its story.
     *
     * @var list<string>
     */
    private const array TABLES = [
        'sites', 'catalog_vlans', 'vlans', 'address_blocks', 'subnets', 'ip_addresses', 'buildings', 'floors', 'rooms', 'devices',
        'device_templates', 'device_template_port_groups', 'device_members', 'port_aggregates', 'ports', 'port_vlans',
        'links', 'link_ends', 'activity_log',
    ];

    /**
     * The tables the version adds.
     *
     * @var list<string>
     */
    private const array NEW_TABLES = ['patch_panels', 'patch_panel_ports', 'jacks', 'patch_cords'];

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
     * Fill the database the way version 0.9.0 would have: two sites with
     * their places, a stack, a switch alone and a firewall with their
     * ports, an aggregate, the VLANs they carry, two links, addresses and
     * what the activity log tells of them.
     *
     * @return array{floors: array<string, int>, rooms: array<string, int>, devices: array<string, int>, members: array<string, int>, ports: array<string, int>, addresses: array<string, int>}
     */
    private function documentTheNetwork(): array
    {
        $stamps = ['created_at' => now(), 'updated_at' => now()];

        $sites = [];

        foreach ([['Siège', 'SIE', 10], ['Atelier', 'ATE', 20]] as [$name, $code, $number]) {
            $sites[$name] = (int) DB::table('sites')->insertGetId(['name' => $name, 'code' => $code, 'number' => $number, 'status' => 'active', 'description' => null, ...$stamps]);
        }

        $vlans = [];

        foreach ([['Siège', 10, 'Bureautique'], ['Siège', 99, 'Administration'], ['Atelier', 10, 'Bureautique']] as [$site, $vid, $name]) {
            $vlans[$site.'/'.$vid] = (int) DB::table('vlans')->insertGetId([
                'site_id' => $sites[$site], 'catalog_vlan_id' => null,
                'vid' => $vid, 'name' => $name, 'description' => null, 'status' => 'active', ...$stamps,
            ]);
        }

        $subnet = (int) DB::table('subnets')->insertGetId([
            'vlan_id' => $vlans['Siège/10'], 'site_id' => $sites['Siège'], 'kind' => 'segment', 'ip_version' => 4,
            'network' => inet_pton('10.10.10.0'), 'prefix_length' => 24, 'gateway' => inet_pton('10.10.10.1'),
            'name' => null, 'description' => null, 'status' => 'active', ...$stamps,
        ]);

        // The places of the two sites.
        $floors = [];
        $rooms = [];

        foreach ([['Siège', 'Bâtiment principal', [[0, 'Rez-de-chaussée', ['Local technique', 'Accueil']], [1, '1er étage', ['Bureaux']]]], ['Atelier', 'Hall', [[0, 'Rez-de-chaussée', ['Baie']]]]] as [$site, $buildingName, $levels]) {
            $building = (int) DB::table('buildings')->insertGetId(['site_id' => $sites[$site], 'name' => $buildingName, 'code' => null, 'description' => null, ...$stamps]);

            foreach ($levels as [$level, $floorName, $roomNames]) {
                $floors[$site.'/'.$floorName] = (int) DB::table('floors')->insertGetId(['building_id' => $building, 'level' => $level, 'name' => $floorName, 'description' => null, ...$stamps]);

                foreach ($roomNames as $roomName) {
                    $rooms[$site.'/'.$roomName] = (int) DB::table('rooms')->insertGetId([
                        'floor_id' => $floors[$site.'/'.$floorName], 'name' => $roomName, 'is_technical' => in_array($roomName, ['Local technique', 'Baie'], true), 'description' => null, ...$stamps,
                    ]);
                }
            }
        }

        $devices = [];

        foreach ([
            ['Siège', 'Local technique', 'sw-etage', 'switch'],
            ['Siège', 'Local technique', 'sw-coeur', 'switch'],
            ['Siège', 'Local technique', 'fw-siege', 'firewall'],
            ['Atelier', 'Baie', 'sw-atelier', 'switch'],
        ] as [$site, $room, $name, $type]) {
            $devices[$name] = (int) DB::table('devices')->insertGetId([
                'site_id' => $sites[$site], 'room_id' => $rooms[$site.'/'.$room], 'name' => $name, 'type' => $type,
                'manufacturer' => null, 'model' => null, 'serial_number' => null, 'description' => null, 'status' => 'active', ...$stamps,
            ]);
        }

        $members = [];

        foreach ([['sw-etage', 1, 'primary'], ['sw-etage', 2, 'secondary'], ['sw-coeur', 1, 'primary'], ['fw-siege', 1, 'primary'], ['sw-atelier', 1, 'primary']] as [$device, $position, $role]) {
            $members[$device.'/'.$position] = (int) DB::table('device_members')->insertGetId([
                'device_id' => $devices[$device], 'position' => $position, 'role' => $role, 'device_template_id' => null,
                'serial_number' => null, 'room_id' => null, 'description' => null, ...$stamps,
            ]);
        }

        $aggregate = (int) DB::table('port_aggregates')->insertGetId([
            'device_id' => $devices['sw-coeur'], 'name' => 'Po1', 'protocol' => 'lacp', 'mode' => 'trunk', 'description' => null, ...$stamps,
        ]);

        $ports = [];

        foreach ([
            ['sw-etage', 1, '1/0/1', 'access', null],
            ['sw-etage', 1, '1/0/2', 'access', null],
            ['sw-etage', 2, '2/0/1', null, null],
            ['sw-etage', 1, '1/1/1', 'trunk', null],
            ['sw-coeur', 1, '1/0/1', null, $aggregate],
            ['sw-coeur', 1, '1/0/2', null, $aggregate],
            ['fw-siege', 1, 'port1', null, null],
            ['sw-atelier', 1, '1/0/1', 'access', null],
        ] as $order => [$device, $member, $name, $mode, $inAggregate]) {
            $ports[$device.'/'.$name] = (int) DB::table('ports')->insertGetId([
                'device_member_id' => $members[$device.'/'.$member], 'device_id' => $devices[$device], 'name' => $name, 'position' => $order + 1,
                'connector' => 'rj45', 'max_speed' => '1g', 'poe_type' => 'none', 'poe_label' => null, 'enabled' => true,
                'mode' => $mode, 'port_aggregate_id' => $inAggregate, 'description' => null, ...$stamps,
            ]);
        }

        DB::table('port_vlans')->insert([
            ['port_id' => $ports['sw-etage/1/0/1'], 'port_aggregate_id' => null, 'vlan_id' => $vlans['Siège/10'], 'tagged' => false, ...$stamps],
            ['port_id' => $ports['sw-etage/1/0/2'], 'port_aggregate_id' => null, 'vlan_id' => $vlans['Siège/10'], 'tagged' => false, ...$stamps],
            ['port_id' => $ports['sw-etage/1/1/1'], 'port_aggregate_id' => null, 'vlan_id' => $vlans['Siège/99'], 'tagged' => false, ...$stamps],
            ['port_id' => null, 'port_aggregate_id' => $aggregate, 'vlan_id' => $vlans['Siège/99'], 'tagged' => false, ...$stamps],
            ['port_id' => $ports['sw-atelier/1/0/1'], 'port_aggregate_id' => null, 'vlan_id' => $vlans['Atelier/10'], 'tagged' => false, ...$stamps],
        ]);

        // Two links: the uplink of the floor, and the firewall.
        foreach ([['sw-etage/1/1/1', 'sw-coeur/1/0/1', 'fiber_multimode'], ['fw-siege/port1', 'sw-coeur/1/0/2', null]] as [$first, $second, $medium]) {
            $link = (int) DB::table('links')->insertGetId(['medium' => $medium, 'description' => null, 'status' => 'active', ...$stamps]);

            DB::table('link_ends')->insert([
                ['link_id' => $link, 'port_id' => $ports[$first], 'end' => 1, ...$stamps],
                ['link_id' => $link, 'port_id' => $ports[$second], 'end' => 2, ...$stamps],
            ]);
        }

        $addresses = [];

        foreach ([['10.10.10.21', 'pc-accueil', null], ['10.10.10.22', 'pc-compta', null], ['10.10.10.2', 'sw-etage', 'sw-etage']] as [$address, $hostname, $device]) {
            $addresses[$address] = (int) DB::table('ip_addresses')->insertGetId([
                'subnet_id' => $subnet, 'address' => inet_pton($address), 'hostname' => $hostname, 'mac_address' => null,
                'device_type' => null, 'assignment' => 'static', 'device_id' => $device === null ? null : $devices[$device],
                'description' => null, 'status' => 'active', ...$stamps,
            ]);
        }

        // What the former version wrote about a link and an address.
        foreach ([
            ['link.created', 'App\\Models\\Link', 1, [], ['end_1' => 'sw-etage (Siège) › 1/1/1', 'end_2' => 'sw-coeur (Siège) › 1/0/1', 'status' => 'active'], 'sw-etage (Siège) › 1/1/1 ↔ sw-coeur (Siège) › 1/0/1'],
            ['ip_address.created', 'App\\Models\\IpAddress', $addresses['10.10.10.21'], [], ['address' => '10.10.10.21', 'hostname' => 'pc-accueil'], '10.10.10.21'],
        ] as [$event, $type, $id, $old, $attributes, $label]) {
            DB::table('activity_log')->insert([
                'log_name' => 'default', 'description' => $event, 'subject_type' => $type, 'subject_id' => $id, 'event' => $event,
                'causer_type' => null, 'causer_id' => null,
                'attribute_changes' => json_encode(['old' => $old, 'attributes' => $attributes], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'properties' => json_encode(['source' => 'web', 'label' => $label], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ...$stamps,
            ]);
        }

        return ['floors' => $floors, 'rooms' => $rooms, 'devices' => $devices, 'members' => $members, 'ports' => $ports, 'addresses' => $addresses];
    }

    /**
     * Every row of every table of the former version, as plain arrays. The
     * jack of an address, a column the version adds, is left out: what the
     * former version knew of an address is the rest.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function everything(): array
    {
        $tables = [];

        foreach (self::TABLES as $table) {
            $tables[$table] = DB::table($table)->orderBy('id')->get()
                ->map(fn (object $row): array => array_diff_key((array) $row, ['jack_id' => true]))
                ->all();
        }

        return $tables;
    }

    /**
     * A panel of so many ports in a room, written straight to its tables.
     *
     * @return array{panel: int, ports: array<int, int>}
     */
    private function panel(int $room, string $name, int $count): array
    {
        $stamps = ['created_at' => now(), 'updated_at' => now()];

        $panel = (int) DB::table('patch_panels')->insertGetId([
            'room_id' => $room, 'name' => $name, 'port_count' => $count, 'connector' => 'rj45', 'category' => '6A',
            'description' => null, 'status' => 'active', ...$stamps,
        ]);

        $ports = [];

        foreach (range(1, $count) as $number) {
            $ports[$number] = (int) DB::table('patch_panel_ports')->insertGetId(['patch_panel_id' => $panel, 'number' => $number, ...$stamps]);
        }

        return ['panel' => $panel, 'ports' => $ports];
    }

    /**
     * A jack of a floor, written straight to its table.
     */
    private function jack(int $floor, string $label, ?int $panelPort = null): int
    {
        return (int) DB::table('jacks')->insertGetId([
            'floor_id' => $floor, 'label' => $label, 'location' => null, 'patch_panel_port_id' => $panelPort,
            'description' => null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * A patch cord, written straight to its table.
     */
    private function cord(int $panelPort, int $port): int
    {
        return (int) DB::table('patch_cords')->insertGetId([
            'patch_panel_port_id' => $panelPort, 'port_id' => $port, 'color' => null, 'description' => null,
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

    public function test_the_former_schema_is_the_one_of_the_former_version(): void
    {
        foreach (self::NEW_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }

        $this->assertFalse(Schema::hasColumn('ip_addresses', 'jack_id'));

        // What version 0.9.0 had is there.
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertTrue(Schema::hasColumn('ip_addresses', 'device_id'));
    }

    public function test_the_upgrade_keeps_every_object_as_it_was(): void
    {
        $this->documentTheNetwork();

        $before = $this->everything();

        $this->assertSame(
            [
                'sites' => 2, 'catalog_vlans' => 0, 'vlans' => 3, 'address_blocks' => 0, 'subnets' => 1, 'ip_addresses' => 3,
                'buildings' => 2, 'floors' => 3, 'rooms' => 4, 'devices' => 4,
                'device_templates' => 0, 'device_template_port_groups' => 0, 'device_members' => 5, 'port_aggregates' => 1, 'ports' => 8, 'port_vlans' => 5,
                'links' => 2, 'link_ends' => 4, 'activity_log' => 2,
            ],
            array_map(count(...), $before),
        );

        $this->artisan('migrate')->assertSuccessful();

        // What the tables of the former version held is there, column for
        // column and byte for byte, the log included.
        $this->assertEquals($before, $this->everything());

        // The new tables start empty, and no address is plugged into a
        // jack.
        foreach (self::NEW_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }

        $this->assertTrue(Schema::hasColumn('ip_addresses', 'jack_id'));
        $this->assertSame(0, DB::table('ip_addresses')->whereNotNull('jack_id')->count());

        // The objects are read as before.
        $this->assertSame(3, IpAddress::query()->count());
        $this->assertSame(1, IpAddress::query()->whereNotNull('device_id')->count());
        $this->assertSame(2, Link::query()->has('ends', 2)->count());
    }

    public function test_the_database_itself_keeps_a_jack_and_a_cord_on_a_port_at_most(): void
    {
        $ids = $this->documentTheNetwork();

        $this->artisan('migrate')->assertSuccessful();

        $unique = UniqueConstraintViolationException::class;
        $stamps = ['created_at' => now(), 'updated_at' => now()];
        $first = $this->panel($ids['rooms']['Siège/Local technique'], 'PB-A', 4);
        $second = $this->panel($ids['rooms']['Siège/Local technique'], 'PB-B', 2);
        $floor = $ids['floors']['Siège/1er étage'];

        // A name once per room, a number once per panel.
        $this->assertRefusedByTheDatabase(
            fn () => DB::table('patch_panels')->insert(['room_id' => $ids['rooms']['Siège/Local technique'], 'name' => 'PB-A', 'port_count' => 1, 'connector' => 'rj45', 'status' => 'active', ...$stamps]),
            'A second panel of the same name in a room', $unique,
        );
        $this->assertRefusedByTheDatabase(
            fn () => DB::table('patch_panel_ports')->insert(['patch_panel_id' => $first['panel'], 'number' => 4, ...$stamps]),
            'A second port 4 on a panel', $unique,
        );

        // The same name in another room is another panel.
        $this->panel($ids['rooms']['Atelier/Baie'], 'PB-A', 1);

        // A label once per floor; the same label on another floor is
        // another jack.
        $jack = $this->jack($floor, 'B.01', $first['ports'][1]);

        $this->assertRefusedByTheDatabase(fn () => $this->jack($floor, 'B.01'), 'A second jack of the same label on a floor', $unique);
        $this->jack($ids['floors']['Siège/Rez-de-chaussée'], 'B.01');

        // A port of a panel has one jack at most.
        $this->assertRefusedByTheDatabase(fn () => $this->jack($floor, 'B.02', $first['ports'][1]), 'A second jack on a port of a panel', $unique);

        // A port of a panel has one cord at most, and so has a port of a
        // device.
        $this->cord($first['ports'][1], $ids['ports']['sw-etage/1/0/1']);

        $this->assertRefusedByTheDatabase(fn () => $this->cord($first['ports'][1], $ids['ports']['sw-etage/1/0/2']), 'A second cord on a port of a panel', $unique);
        $this->assertRefusedByTheDatabase(fn () => $this->cord($second['ports'][1], $ids['ports']['sw-etage/1/0/1']), 'A second cord on a port of a device', $unique);

        // A cord and a jack need what they refer to.
        $this->assertRefusedByTheDatabase(fn () => $this->cord(999999, $ids['ports']['sw-etage/1/0/2']), 'A cord on a port of a panel that does not exist');
        $this->assertRefusedByTheDatabase(fn () => $this->cord($second['ports'][2], 999999), 'A cord on a port of a device that does not exist');
        $this->assertRefusedByTheDatabase(fn () => $this->jack(999999, 'B.03'), 'A jack of a floor that does not exist');

        $this->assertSame([2, 1], [DB::table('jacks')->count(), DB::table('patch_cords')->count()]);

        // An address is plugged into a jack that exists.
        DB::table('ip_addresses')->where('id', $ids['addresses']['10.10.10.21'])->update(['jack_id' => $jack]);

        $this->assertRefusedByTheDatabase(
            fn () => DB::table('ip_addresses')->where('id', $ids['addresses']['10.10.10.22'])->update(['jack_id' => 999999]),
            'An address plugged into a jack that does not exist',
        );

        // Nothing is deleted under what refers to it: a jack under an
        // address, a floor under a jack, a room under a panel, a port of a
        // panel under a jack or a cord, the panel itself, and a port of a
        // device, its member or its device under a cord.
        DB::table('ip_addresses')->where('device_id', $ids['devices']['sw-etage'])->update(['device_id' => null]);
        DB::table('link_ends')->delete();

        foreach ([
            ['jacks', $jack],
            ['floors', $floor],
            ['rooms', $ids['rooms']['Siège/Local technique']],
            ['patch_panel_ports', $first['ports'][1]],
            ['patch_panels', $first['panel']],
            ['ports', $ids['ports']['sw-etage/1/0/1']],
            ['device_members', $ids['members']['sw-etage/1']],
            ['devices', $ids['devices']['sw-etage']],
        ] as [$table, $id]) {
            $this->assertRefusedByTheDatabase(fn () => DB::table($table)->where('id', $id)->delete(), 'A row of "'.$table.'" still referred to');
            $this->assertTrue(DB::table($table)->where('id', $id)->exists());
        }
    }

    public function test_what_goes_with_its_parent_goes_and_what_stays_is_freed(): void
    {
        $ids = $this->documentTheNetwork();

        $this->artisan('migrate')->assertSuccessful();

        $before = $this->everything();
        $panel = $this->panel($ids['rooms']['Siège/Local technique'], 'PB-A', 3);
        $jack = $this->jack($ids['floors']['Siège/1er étage'], 'B.01', $panel['ports'][1]);
        $cord = $this->cord($panel['ports'][2], $ids['ports']['sw-etage/1/0/2']);

        // A cord deleted: its two ports stay.
        DB::table('patch_cords')->where('id', $cord)->delete();

        $this->assertSame([3, 8], [DB::table('patch_panel_ports')->count(), DB::table('ports')->count()]);

        // A jack taken off its port, then deleted: the port stays.
        DB::table('jacks')->where('id', $jack)->update(['patch_panel_port_id' => null]);
        DB::table('jacks')->where('id', $jack)->delete();

        // A panel with neither jack nor cord: its ports go with it.
        DB::table('patch_panels')->where('id', $panel['panel'])->delete();

        foreach (self::NEW_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }

        // And nothing else was touched.
        $this->assertEquals($before, $this->everything());
    }

    public function test_going_back_keeps_what_the_former_version_knew(): void
    {
        $ids = $this->documentTheNetwork();

        $before = $this->everything();

        $this->artisan('migrate')->assertSuccessful();

        // What the new version adds: a panel, jacks, a cord, and an address
        // plugged into a jack.
        $panel = $this->panel($ids['rooms']['Siège/Local technique'], 'PB-A', 24);
        $jack = $this->jack($ids['floors']['Siège/1er étage'], 'B.01', $panel['ports'][1]);
        $this->jack($ids['floors']['Siège/1er étage'], 'B.02', $panel['ports'][2]);
        $this->cord($panel['ports'][1], $ids['ports']['sw-etage/1/0/1']);

        DB::table('ip_addresses')->where('id', $ids['addresses']['10.10.10.21'])->update(['jack_id' => $jack]);

        $this->rollBackToTheFormerVersion();

        // All of it is gone with its tables and its column; everything else
        // is as it was.
        foreach (self::NEW_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }

        $this->assertFalse(Schema::hasColumn('ip_addresses', 'jack_id'));
        $this->assertEquals($before, $this->everything());

        // And the upgrade can be run again.
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEquals($before, $this->everything());

        foreach (self::NEW_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }
}
