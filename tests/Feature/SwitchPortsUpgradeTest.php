<?php

// torsade/tests/Feature/SwitchPortsUpgradeTest.php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\IpAddress;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * An installation of version 0.7.0 is upgraded: the device templates, the
 * members of devices, their ports, the aggregates of ports and the VLANs
 * ports carry are added to a database that already holds a network with
 * its places and its devices. Nothing of it is lost, and going back loses
 * nothing the former version knew.
 *
 * These tests change the schema, which no transaction can undo: each one
 * rebuilds the database before and after itself.
 */
class SwitchPortsUpgradeTest extends TestCase
{
    /**
     * The first migration of the version: everything from it on is rolled
     * back to get the schema of version 0.7.0.
     */
    private const string FIRST_MIGRATION = '2026_10_12_200000_create_device_templates_table';

    /**
     * The tables of the network documentation in version 0.7.0, and the
     * activity log that tells its story.
     *
     * @var list<string>
     */
    private const array TABLES = [
        'sites', 'catalog_vlans', 'vlans', 'address_blocks', 'addressing_rules', 'addressing_rule_ranges',
        'subnets', 'ip_ranges', 'ip_addresses', 'buildings', 'floors', 'rooms', 'devices', 'activity_log',
    ];

    /**
     * The tables the version adds.
     *
     * @var list<string>
     */
    private const array NEW_TABLES = [
        'device_templates', 'device_template_port_groups', 'device_members', 'port_aggregates', 'ports', 'port_vlans',
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
     * Fill the database the way version 0.7.0 would have: with the tables
     * and the columns it had, and nothing else.
     *
     * @return array{sites: array<string, int>, vlans: array<string, int>, rooms: array<string, int>, devices: array<string, int>}
     */
    private function documentTheNetwork(): array
    {
        $now = now();
        $stamps = ['created_at' => $now, 'updated_at' => $now];

        $sites = [];

        foreach ([['Siège', 'SIE', 10, 'active'], ['Atelier', 'ATE', 20, 'active'], ['Dépôt', 'DEP', 30, 'planned']] as [$name, $code, $number, $status]) {
            $sites[$name] = (int) DB::table('sites')->insertGetId(['name' => $name, 'code' => $code, 'number' => $number, 'status' => $status, 'description' => null, ...$stamps]);
        }

        $entry = (int) DB::table('catalog_vlans')->insertGetId(['vid' => 10, 'name' => 'Bureautique', 'description' => null, ...$stamps]);

        $vlans = [];

        foreach ([['Siège', 10, 'Bureautique', $entry], ['Siège', 30, 'Téléphonie', null], ['Siège', 99, 'Administration', null], ['Atelier', 10, 'Bureautique', $entry]] as [$site, $vid, $name, $catalog]) {
            $vlans[$site.'/'.$vid] = (int) DB::table('vlans')->insertGetId([
                'site_id' => $sites[$site], 'catalog_vlan_id' => $catalog,
                'vid' => $vid, 'name' => $name, 'description' => null, 'status' => 'active', ...$stamps,
            ]);
        }

        $block = (int) DB::table('address_blocks')->insertGetId([
            'site_id' => null, 'ip_version' => 4, 'network' => inet_pton('10.0.0.0'), 'prefix_length' => 8,
            'name' => null, 'description' => null, 'status' => 'active', ...$stamps,
        ]);

        $rule = (int) DB::table('addressing_rules')->insertGetId([
            'aggregate_block_id' => $block, 'site_prefix_length' => 16, 'vlan_prefix_length' => 24, 'gateway_position' => 'first', ...$stamps,
        ]);

        DB::table('addressing_rule_ranges')->insert([
            'addressing_rule_id' => $rule, 'kind' => 'dhcp', 'start_offset' => 100, 'end_offset' => 200, 'name' => null, ...$stamps,
        ]);

        $subnet = (int) DB::table('subnets')->insertGetId([
            'vlan_id' => $vlans['Siège/99'], 'site_id' => $sites['Siège'], 'kind' => 'segment', 'ip_version' => 4,
            'network' => inet_pton('10.10.99.0'), 'prefix_length' => 24, 'gateway' => inet_pton('10.10.99.254'),
            'name' => null, 'description' => null, 'status' => 'active', ...$stamps,
        ]);

        DB::table('ip_ranges')->insert([
            'subnet_id' => $subnet, 'kind' => 'static', 'start_address' => inet_pton('10.10.99.1'), 'end_address' => inet_pton('10.10.99.99'),
            'name' => 'Équipements réseau', 'description' => null, ...$stamps,
        ]);

        // The places of the head office, and its devices.
        $building = (int) DB::table('buildings')->insertGetId(['site_id' => $sites['Siège'], 'name' => 'Bâtiment principal', 'code' => 'A', 'description' => null, ...$stamps]);
        $floor = (int) DB::table('floors')->insertGetId(['building_id' => $building, 'level' => 0, 'name' => 'Rez-de-chaussée', 'description' => null, ...$stamps]);

        $rooms = [];

        foreach ([['Local technique', true], ['Accueil', false]] as [$name, $technical]) {
            $rooms[$name] = (int) DB::table('rooms')->insertGetId(['floor_id' => $floor, 'name' => $name, 'is_technical' => $technical, 'description' => null, ...$stamps]);
        }

        $devices = [];

        foreach ([
            ['Siège', 'Local technique', 'sw-coeur', 'switch', 'SN-COEUR-1', 'active'],
            ['Siège', 'Local technique', 'fw-siege', 'firewall', null, 'active'],
            ['Siège', null, 'imp-accueil', 'printer', 'SN-IMP-7', 'retired'],
            ['Atelier', null, 'sw-atelier', 'switch', null, 'planned'],
        ] as [$site, $room, $name, $type, $serial, $status]) {
            $devices[$name] = (int) DB::table('devices')->insertGetId([
                'site_id' => $sites[$site], 'room_id' => $room === null ? null : $rooms[$room], 'name' => $name, 'type' => $type,
                'manufacturer' => null, 'model' => null, 'serial_number' => $serial, 'description' => null, 'status' => $status, ...$stamps,
            ]);
        }

        foreach ([['10.10.99.11', 'sw-coeur', 'sw-coeur'], ['10.10.99.254', 'fw-siege', 'fw-siege'], ['10.10.99.50', 'onduleur-01', null]] as [$address, $hostname, $device]) {
            DB::table('ip_addresses')->insert([
                'subnet_id' => $subnet, 'address' => inet_pton($address), 'hostname' => $hostname, 'mac_address' => null,
                'device_type' => null, 'assignment' => 'static', 'device_id' => $device === null ? null : $devices[$device],
                'description' => null, 'status' => 'active', ...$stamps,
            ]);
        }

        // What the former version wrote about a device and an attachment.
        foreach ([
            ['device.created', 'App\\Models\\Device', $devices['sw-coeur'], [], ['name' => 'sw-coeur', 'type' => 'switch', 'serial_number' => 'SN-COEUR-1'], 'sw-coeur (Siège)'],
            ['ip_address.updated', 'App\\Models\\IpAddress', 1, ['device' => null], ['device' => 'sw-coeur (Siège)'], '10.10.99.11'],
        ] as [$event, $type, $id, $old, $attributes, $label]) {
            DB::table('activity_log')->insert([
                'log_name' => 'default', 'description' => $event, 'subject_type' => $type, 'subject_id' => $id, 'event' => $event,
                'causer_type' => null, 'causer_id' => null,
                'attribute_changes' => json_encode(['old' => $old, 'attributes' => $attributes], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'properties' => json_encode(['source' => 'web', 'label' => $label], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ...$stamps,
            ]);
        }

        return ['sites' => $sites, 'vlans' => $vlans, 'rooms' => $rooms, 'devices' => $devices];
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

        // The jack of an address is a column of a later version than 0.8.0:
        // what this version knew of an address is the rest.
        $tables['ip_addresses'] = array_map(fn (array $row): array => array_diff_key($row, ['jack_id' => true]), $tables['ip_addresses']);

        return $tables;
    }

    /**
     * A template with a group of ports, a stack of two members on a device,
     * four ports, an aggregate over both members and the VLANs they carry,
     * written straight to their tables.
     *
     * @param  array{sites: array<string, int>, vlans: array<string, int>, rooms: array<string, int>, devices: array<string, int>}  $ids
     * @return array{template: int, group: int, members: array<int, int>, ports: array<string, int>, aggregate: int}
     */
    private function equip(array $ids): array
    {
        $stamps = ['created_at' => now(), 'updated_at' => now()];
        $device = $ids['devices']['sw-coeur'];

        $template = (int) DB::table('device_templates')->insertGetId([
            'name' => 'Switch 24 ports', 'device_type' => 'switch', 'manufacturer' => null, 'model' => null,
            'poe_budget_watts' => 370, 'description' => null, ...$stamps,
        ]);

        $group = (int) DB::table('device_template_port_groups')->insertGetId([
            'device_template_id' => $template, 'position' => 1, 'quantity' => 24, 'name_pattern' => '{m}/0/{n}', 'first_number' => 1,
            'connector' => 'rj45', 'max_speed' => '1g', 'poe_type' => '802.3at', 'poe_label' => null, ...$stamps,
        ]);

        $members = [];

        foreach ([[1, 'primary', $ids['rooms']['Local technique']], [2, 'secondary', null]] as [$position, $role, $room]) {
            $members[$position] = (int) DB::table('device_members')->insertGetId([
                'device_id' => $device, 'position' => $position, 'role' => $role, 'device_template_id' => $template,
                'serial_number' => 'SN-M'.$position, 'room_id' => $room, 'description' => null, ...$stamps,
            ]);
        }

        $aggregate = (int) DB::table('port_aggregates')->insertGetId([
            'device_id' => $device, 'name' => 'Po1', 'protocol' => 'lacp', 'mode' => 'trunk', 'description' => null, ...$stamps,
        ]);

        $ports = [];

        foreach ([[1, '1/0/1', 'access', null], [1, '1/0/24', null, $aggregate], [2, '2/0/1', null, null], [2, '2/0/24', null, $aggregate]] as $order => [$member, $name, $mode, $inAggregate]) {
            $ports[$name] = (int) DB::table('ports')->insertGetId([
                'device_member_id' => $members[$member], 'device_id' => $device, 'name' => $name, 'position' => $order + 1,
                'connector' => 'rj45', 'max_speed' => '1g', 'poe_type' => '802.3at', 'poe_label' => null, 'enabled' => true,
                'mode' => $mode, 'port_aggregate_id' => $inAggregate, 'description' => null, ...$stamps,
            ]);
        }

        DB::table('port_vlans')->insert([
            // An access port with its VLAN and its voice VLAN.
            ['port_id' => $ports['1/0/1'], 'port_aggregate_id' => null, 'vlan_id' => $ids['vlans']['Siège/10'], 'tagged' => false, ...$stamps],
            ['port_id' => $ports['1/0/1'], 'port_aggregate_id' => null, 'vlan_id' => $ids['vlans']['Siège/30'], 'tagged' => true, ...$stamps],
            // A trunk on the aggregate: a native VLAN and a tagged one.
            ['port_id' => null, 'port_aggregate_id' => $aggregate, 'vlan_id' => $ids['vlans']['Siège/99'], 'tagged' => false, ...$stamps],
            ['port_id' => null, 'port_aggregate_id' => $aggregate, 'vlan_id' => $ids['vlans']['Siège/10'], 'tagged' => true, ...$stamps],
        ]);

        return ['template' => $template, 'group' => $group, 'members' => $members, 'ports' => $ports, 'aggregate' => $aggregate];
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

        // What version 0.7.0 had is there.
        $this->assertTrue(Schema::hasColumn('sites', 'status'));
        $this->assertTrue(Schema::hasColumn('ip_addresses', 'device_id'));
        $this->assertTrue(Schema::hasTable('devices'));
        $this->assertTrue(Schema::hasTable('rooms'));
    }

    public function test_the_upgrade_keeps_every_object_as_it_was(): void
    {
        $this->documentTheNetwork();

        $before = $this->everything();

        $this->assertSame(
            [
                'sites' => 3, 'catalog_vlans' => 1, 'vlans' => 4, 'address_blocks' => 1, 'addressing_rules' => 1, 'addressing_rule_ranges' => 1,
                'subnets' => 1, 'ip_ranges' => 1, 'ip_addresses' => 3, 'buildings' => 1, 'floors' => 1, 'rooms' => 2, 'devices' => 4, 'activity_log' => 2,
            ],
            array_map(count(...), $before),
        );

        $this->artisan('migrate')->assertSuccessful();

        // No table of the former version gets a column: what they held is
        // there, column for column and byte for byte, the log included.
        $this->assertEquals($before, $this->everything());

        // The new tables start empty: no device has a member nor a port.
        foreach (self::NEW_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }

        // The devices are read as before, with their own serial number and
        // their addresses.
        $this->assertSame(
            ['fw-siege' => null, 'imp-accueil' => 'SN-IMP-7', 'sw-atelier' => null, 'sw-coeur' => 'SN-COEUR-1'],
            Device::query()->orderBy('name')->pluck('serial_number', 'name')->all(),
        );
        $this->assertSame(0, Device::query()->has('members')->count());
        $this->assertSame(2, IpAddress::query()->whereNotNull('device_id')->count());
    }

    public function test_the_database_itself_keeps_members_ports_and_aggregates_consistent(): void
    {
        $ids = $this->documentTheNetwork();

        $this->artisan('migrate')->assertSuccessful();

        $made = $this->equip($ids);
        $stamps = ['created_at' => now(), 'updated_at' => now()];
        $device = $ids['devices']['sw-coeur'];
        $other = $ids['devices']['fw-siege'];

        $port = fn (array $values): array => [
            'device_member_id' => $made['members'][1], 'device_id' => $device, 'name' => '1/0/2', 'position' => 2,
            'connector' => 'rj45', 'max_speed' => '1g', 'poe_type' => 'none', 'enabled' => true, ...$values, ...$stamps,
        ];

        // A name once per device, for templates, members, ports and
        // aggregates.
        $unique = UniqueConstraintViolationException::class;

        $this->assertRefusedByTheDatabase(
            fn () => DB::table('device_templates')->insert(['name' => 'Switch 24 ports', 'device_type' => 'switch', ...$stamps]),
            'A second template of the same name', $unique,
        );
        $this->assertRefusedByTheDatabase(
            fn () => DB::table('device_members')->insert(['device_id' => $device, 'position' => 2, 'role' => 'member', ...$stamps]),
            'A second member with the same number', $unique,
        );
        $this->assertRefusedByTheDatabase(fn () => DB::table('ports')->insert($port(['name' => '1/0/1'])), 'A second port of the same name on a device', $unique);
        // On another member of the same device, too.
        $this->assertRefusedByTheDatabase(
            fn () => DB::table('ports')->insert($port(['device_member_id' => $made['members'][2], 'name' => '1/0/1'])),
            'A port named like a port of another member', $unique,
        );
        $this->assertRefusedByTheDatabase(
            fn () => DB::table('port_aggregates')->insert(['device_id' => $device, 'name' => 'Po1', 'protocol' => 'static', ...$stamps]),
            'A second aggregate of the same name on a device', $unique,
        );

        // The same names on another device are other objects.
        $elsewhere = (int) DB::table('device_members')->insertGetId(['device_id' => $other, 'position' => 1, 'role' => 'primary', ...$stamps]);

        DB::table('ports')->insert($port(['device_member_id' => $elsewhere, 'device_id' => $other, 'name' => '1/0/1']));
        DB::table('port_aggregates')->insert(['device_id' => $other, 'name' => 'Po1', 'protocol' => 'lacp', ...$stamps]);

        // A port belongs to the device of its member, and to no other.
        $this->assertRefusedByTheDatabase(
            fn () => DB::table('ports')->insert($port(['device_id' => $other, 'name' => '1/0/3'])),
            'A port that names another device than the one of its member',
        );
        $this->assertRefusedByTheDatabase(
            fn () => DB::table('ports')->insert($port(['device_member_id' => 999999, 'name' => '1/0/3'])),
            'A port of a member that does not exist',
        );

        // A line of VLAN belongs to a port or to an aggregate: one of the
        // two, never both, never none.
        $vlan = $ids['vlans']['Siège/99'];

        $this->assertRefusedByTheDatabase(
            fn () => DB::table('port_vlans')->insert(['port_id' => null, 'port_aggregate_id' => null, 'vlan_id' => $vlan, 'tagged' => true, ...$stamps]),
            'A VLAN carried by nothing',
        );
        $this->assertRefusedByTheDatabase(
            fn () => DB::table('port_vlans')->insert(['port_id' => $made['ports']['2/0/1'], 'port_aggregate_id' => $made['aggregate'], 'vlan_id' => $vlan, 'tagged' => true, ...$stamps]),
            'A VLAN carried by a port and an aggregate at once',
        );
        // A port, or an aggregate, carries a VLAN once.
        $this->assertRefusedByTheDatabase(
            fn () => DB::table('port_vlans')->insert(['port_id' => $made['ports']['1/0/1'], 'port_aggregate_id' => null, 'vlan_id' => $ids['vlans']['Siège/10'], 'tagged' => true, ...$stamps]),
            'A VLAN carried twice by a port', $unique,
        );
        $this->assertRefusedByTheDatabase(
            fn () => DB::table('port_vlans')->insert(['port_id' => null, 'port_aggregate_id' => $made['aggregate'], 'vlan_id' => $vlan, 'tagged' => true, ...$stamps]),
            'A VLAN carried twice by an aggregate', $unique,
        );

        $this->assertSame(4, DB::table('port_vlans')->count());

        // Nothing is deleted under what refers to it: a template under a
        // member, a room under a member, a VLAN under a port.
        foreach ([['device_templates', $made['template']], ['rooms', $ids['rooms']['Local technique']], ['vlans', $ids['vlans']['Siège/30']], ['vlans', $vlan]] as [$table, $id]) {
            $this->assertRefusedByTheDatabase(fn () => DB::table($table)->where('id', $id)->delete(), 'A row of "'.$table.'" still referred to');
            $this->assertTrue(DB::table($table)->where('id', $id)->exists());
        }
    }

    public function test_what_goes_with_its_parent_goes_and_what_stays_is_freed(): void
    {
        $ids = $this->documentTheNetwork();

        $this->artisan('migrate')->assertSuccessful();

        $made = $this->equip($ids);
        $before = $this->everything();

        // An aggregate deleted: its ports stay, freed, and its VLANs go.
        DB::table('port_aggregates')->where('id', $made['aggregate'])->delete();

        $this->assertSame(4, DB::table('ports')->count());
        $this->assertSame(0, DB::table('ports')->whereNotNull('port_aggregate_id')->count());
        $this->assertSame(2, DB::table('port_vlans')->count());
        $this->assertSame(0, DB::table('port_vlans')->whereNotNull('port_aggregate_id')->count());

        // A port deleted: the VLANs it carried go with it.
        DB::table('ports')->where('id', $made['ports']['1/0/1'])->delete();

        $this->assertSame(0, DB::table('port_vlans')->count());

        // A member deleted: its ports go with it.
        DB::table('device_members')->where('id', $made['members'][2])->delete();

        $this->assertSame(['1/0/24'], DB::table('ports')->pluck('name')->all());

        // A device deleted, once no address is attached to it: its members,
        // its ports and its aggregates go with it.
        DB::table('port_aggregates')->insert(['device_id' => $ids['devices']['sw-coeur'], 'name' => 'Po2', 'protocol' => 'lacp', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ip_addresses')->where('device_id', $ids['devices']['sw-coeur'])->update(['device_id' => null]);
        DB::table('devices')->where('id', $ids['devices']['sw-coeur'])->delete();

        $this->assertSame([0, 0, 0], [DB::table('device_members')->count(), DB::table('ports')->count(), DB::table('port_aggregates')->count()]);

        // A template deleted, once no member uses it: its groups go.
        DB::table('device_templates')->where('id', $made['template'])->delete();

        $this->assertSame(0, DB::table('device_template_port_groups')->count());

        // Nothing else was touched: the VLANs, the rooms and the other
        // devices are all there.
        $after = $this->everything();

        foreach (['sites', 'vlans', 'rooms', 'subnets', 'buildings', 'floors', 'activity_log'] as $table) {
            $this->assertEquals($before[$table], $after[$table], $table);
        }

        $this->assertSame(['fw-siege', 'imp-accueil', 'sw-atelier'], DB::table('devices')->orderBy('name')->pluck('name')->all());
    }

    public function test_going_back_keeps_what_the_former_version_knew(): void
    {
        $ids = $this->documentTheNetwork();

        $before = $this->everything();

        $this->artisan('migrate')->assertSuccessful();

        // What the new version adds: a template, a stack, ports, an
        // aggregate and VLANs on them.
        $this->equip($ids);

        $this->rollBackToTheFormerVersion();

        // All of it is gone with its tables; everything else is as it was,
        // the devices with their own serial number and their addresses.
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
