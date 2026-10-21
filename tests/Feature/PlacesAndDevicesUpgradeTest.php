<?php

// torsade/tests/Feature/PlacesAndDevicesUpgradeTest.php

namespace Tests\Feature;

use App\Enums\Status;
use App\Models\IpAddress;
use App\Models\Site;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * An installation of version 0.6.0 is upgraded: the status of sites, the
 * places, the devices and the link between an address and its device are
 * added to a database that already holds a network. Nothing of it is lost,
 * and going back loses nothing the former version knew.
 *
 * These tests change the schema, which no transaction can undo: each one
 * rebuilds the database before and after itself.
 */
class PlacesAndDevicesUpgradeTest extends TestCase
{
    /**
     * The first migration of the version: everything from it on is rolled
     * back to get the schema of version 0.6.0.
     */
    private const string FIRST_MIGRATION = '2026_10_12_100000_add_status_to_sites_table';

    /**
     * The tables of the network documentation in version 0.6.0, and the
     * activity log that tells its story.
     *
     * @var list<string>
     */
    private const array TABLES = [
        'sites', 'catalog_vlans', 'vlans', 'address_blocks', 'addressing_rules', 'addressing_rule_ranges',
        'subnets', 'ip_ranges', 'ip_addresses', 'activity_log',
    ];

    /**
     * The tables the version adds.
     *
     * @var list<string>
     */
    private const array NEW_TABLES = ['buildings', 'floors', 'rooms', 'devices'];

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
     * Fill the database the way version 0.6.0 would have: with the tables
     * and the columns it had, and nothing else.
     *
     * @return array{sites: array<string, int>, subnets: array<string, int>, addresses: array<string, int>}
     */
    private function documentTheNetwork(): array
    {
        $now = now();
        $stamps = ['created_at' => $now, 'updated_at' => $now];

        $sites = [];

        foreach ([['Siège', 'SIE', 10, 'Bureaux'], ['Atelier', 'ATE', 20, null], ['Dépôt', null, null, 'Site prévu']] as [$name, $code, $number, $description]) {
            $sites[$name] = (int) DB::table('sites')->insertGetId(['name' => $name, 'code' => $code, 'number' => $number, 'description' => $description, ...$stamps]);
        }

        $entry = (int) DB::table('catalog_vlans')->insertGetId(['vid' => 10, 'name' => 'Bureautique', 'description' => null, ...$stamps]);

        $vlans = [];

        foreach ([['Siège', 10, 'Bureautique', $entry, 'active'], ['Atelier', 10, 'Bureaux', $entry, 'planned'], ['Atelier', 900, 'Divers', null, 'retired']] as [$site, $vid, $name, $catalog, $status]) {
            $vlans[$site.'/'.$vid] = (int) DB::table('vlans')->insertGetId([
                'site_id' => $sites[$site], 'catalog_vlan_id' => $catalog,
                'vid' => $vid, 'name' => $name, 'description' => null, 'status' => $status, ...$stamps,
            ]);
        }

        $blocks = [];

        foreach ([['10.0.0.0', 8, null], ['10.10.0.0', 16, 'Siège'], ['10.20.0.0', 16, 'Atelier']] as [$network, $prefix, $site]) {
            $blocks[$network.'/'.$prefix] = (int) DB::table('address_blocks')->insertGetId([
                'site_id' => $site === null ? null : $sites[$site],
                'ip_version' => 4, 'network' => inet_pton($network), 'prefix_length' => $prefix,
                'name' => null, 'description' => null, 'status' => 'active', ...$stamps,
            ]);
        }

        $rule = (int) DB::table('addressing_rules')->insertGetId([
            'aggregate_block_id' => $blocks['10.0.0.0/8'],
            'site_prefix_length' => 16, 'vlan_prefix_length' => 24, 'gateway_position' => 'first', ...$stamps,
        ]);

        DB::table('addressing_rule_ranges')->insert([
            ['addressing_rule_id' => $rule, 'kind' => 'reserved', 'start_offset' => 2, 'end_offset' => 20, 'name' => 'Infrastructure', ...$stamps],
            ['addressing_rule_id' => $rule, 'kind' => 'dhcp', 'start_offset' => 100, 'end_offset' => 200, 'name' => null, ...$stamps],
        ]);

        $subnets = [];

        foreach ([
            ['10.10.10.0', 24, 'Siège/10', 'Siège', 'segment', '10.10.10.1'],
            ['10.20.10.0', 24, 'Atelier/10', 'Atelier', 'segment', '10.20.10.1'],
            ['10.255.0.0', 31, null, null, 'point_to_point', null],
        ] as [$network, $prefix, $vlan, $site, $kind, $gateway]) {
            $subnets[$network] = (int) DB::table('subnets')->insertGetId([
                'vlan_id' => $vlan === null ? null : $vlans[$vlan],
                'site_id' => $site === null ? null : $sites[$site],
                'kind' => $kind, 'ip_version' => 4, 'network' => inet_pton($network), 'prefix_length' => $prefix,
                'gateway' => $gateway === null ? null : inet_pton($gateway),
                'name' => null, 'description' => null, 'status' => 'active', ...$stamps,
            ]);
        }

        DB::table('ip_ranges')->insert([
            'subnet_id' => $subnets['10.10.10.0'], 'kind' => 'dhcp',
            'start_address' => inet_pton('10.10.10.100'), 'end_address' => inet_pton('10.10.10.200'),
            'name' => null, 'description' => null, ...$stamps,
        ]);

        $addresses = [];

        foreach ([
            ['10.10.10.0', '10.10.10.1', 'fw-siege', 'firewall', 'static', 'active'],
            ['10.10.10.0', '10.10.10.10', 'srv-fichiers-01', 'server', 'static', 'active'],
            ['10.10.10.0', '10.10.10.110', 'pc-accueil-01', null, 'dhcp_reservation', 'planned'],
            ['10.20.10.0', '10.20.10.2', 'fw-siege', 'firewall', 'static', 'active'],
            ['10.255.0.0', '10.255.0.1', null, 'router', 'static', 'retired'],
        ] as [$subnet, $address, $hostname, $type, $assignment, $status]) {
            $addresses[$address] = (int) DB::table('ip_addresses')->insertGetId([
                'subnet_id' => $subnets[$subnet], 'address' => inet_pton($address), 'hostname' => $hostname,
                'mac_address' => null, 'device_type' => $type, 'assignment' => $assignment,
                'description' => null, 'status' => $status, ...$stamps,
            ]);
        }

        // What the former version wrote about a site and about an address.
        foreach ([
            ['site.created', 'App\\Models\\Site', $sites['Siège'], ['name' => 'Siège', 'code' => 'SIE', 'number' => 10, 'description' => 'Bureaux'], 'Siège'],
            ['ip_address.created', 'App\\Models\\IpAddress', $addresses['10.10.10.1'], ['subnet' => '10.10.10.0/24', 'address' => '10.10.10.1', 'hostname' => 'fw-siege'], '10.10.10.1'],
        ] as [$event, $type, $id, $attributes, $label]) {
            DB::table('activity_log')->insert([
                'log_name' => 'default', 'description' => $event, 'subject_type' => $type, 'subject_id' => $id, 'event' => $event,
                'causer_type' => null, 'causer_id' => null,
                'attribute_changes' => json_encode(['old' => [], 'attributes' => $attributes], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'properties' => json_encode(['source' => 'console', 'label' => $label], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ...$stamps,
            ]);
        }

        return ['sites' => $sites, 'subnets' => $subnets, 'addresses' => $addresses];
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
     * The same rows without the two columns the upgrade adds.
     *
     * @param  array<string, list<array<string, mixed>>>  $tables
     * @return array<string, list<array<string, mixed>>>
     */
    private function withoutTheNewColumns(array $tables): array
    {
        $tables['sites'] = array_map(fn (array $row): array => array_diff_key($row, ['status' => true]), $tables['sites']);
        $tables['ip_addresses'] = array_map(fn (array $row): array => array_diff_key($row, ['device_id' => true, 'jack_id' => true]), $tables['ip_addresses']);

        return $tables;
    }

    /**
     * A building, a floor, a room and a device of the given site, written
     * straight to their tables.
     *
     * @return array{building: int, floor: int, room: int, device: int}
     */
    private function furnish(int $site): array
    {
        $stamps = ['created_at' => now(), 'updated_at' => now()];

        $building = (int) DB::table('buildings')->insertGetId(['site_id' => $site, 'name' => 'Bâtiment principal', 'code' => 'A', 'description' => null, ...$stamps]);
        $floor = (int) DB::table('floors')->insertGetId(['building_id' => $building, 'level' => 0, 'name' => 'Rez-de-chaussée', 'description' => null, ...$stamps]);
        $room = (int) DB::table('rooms')->insertGetId(['floor_id' => $floor, 'name' => 'Local technique', 'is_technical' => true, 'description' => null, ...$stamps]);
        $device = (int) DB::table('devices')->insertGetId([
            'site_id' => $site, 'room_id' => $room, 'name' => 'FW-Siège', 'type' => 'firewall',
            'manufacturer' => null, 'model' => null, 'serial_number' => null, 'description' => null, 'status' => 'active', ...$stamps,
        ]);

        return ['building' => $building, 'floor' => $floor, 'room' => $room, 'device' => $device];
    }

    public function test_the_former_schema_is_the_one_of_the_former_version(): void
    {
        $this->assertFalse(Schema::hasColumn('sites', 'status'));
        $this->assertFalse(Schema::hasColumn('ip_addresses', 'device_id'));

        foreach (self::NEW_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }

        // What version 0.6.0 had is there.
        $this->assertTrue(Schema::hasColumn('sites', 'number'));
        $this->assertTrue(Schema::hasTable('addressing_rules'));
        $this->assertTrue(Schema::hasTable('addressing_rule_ranges'));
    }

    public function test_the_upgrade_keeps_every_object_as_it_was_and_makes_every_site_active(): void
    {
        $this->documentTheNetwork();

        $before = $this->everything();

        $this->assertSame(
            ['sites' => 3, 'catalog_vlans' => 1, 'vlans' => 3, 'address_blocks' => 3, 'addressing_rules' => 1, 'addressing_rule_ranges' => 2, 'subnets' => 3, 'ip_ranges' => 1, 'ip_addresses' => 5, 'activity_log' => 2],
            array_map(count(...), $before),
        );

        $this->artisan('migrate')->assertSuccessful();

        // What the tables held is still there, column for column and byte
        // for byte, the activity log included.
        $this->assertEquals($before, $this->withoutTheNewColumns($this->everything()));

        // Every site is in service, the planned one included: the former
        // version had no way to say otherwise.
        $this->assertSame(['active', 'active', 'active'], DB::table('sites')->orderBy('id')->pluck('status')->all());
        $this->assertSame(
            ['Atelier' => Status::Active, 'Dépôt' => Status::Active, 'Siège' => Status::Active],
            Site::query()->orderBy('name')->get()->mapWithKeys(fn (Site $site): array => [$site->name => $site->status])->all(),
        );

        // No address belongs to a device, and each one kept its host name
        // and its type.
        $this->assertSame(5, IpAddress::query()->whereNull('device_id')->count());
        $this->assertSame(
            ['10.10.10.1' => 'fw-siege/firewall', '10.10.10.10' => 'srv-fichiers-01/server', '10.10.10.110' => 'pc-accueil-01/', '10.20.10.2' => 'fw-siege/firewall', '10.255.0.1' => '/router'],
            IpAddress::query()->orderBy('address')->get()
                ->mapWithKeys(fn (IpAddress $address): array => [$address->address => $address->hostname.'/'.$address->device_type?->value])
                ->all(),
        );

        // The new tables start empty.
        foreach (self::NEW_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_the_database_itself_keeps_places_and_devices_consistent(): void
    {
        $ids = $this->documentTheNetwork();

        $this->artisan('migrate')->assertSuccessful();

        $site = $ids['sites']['Siège'];
        $made = $this->furnish($site);
        $stamps = ['created_at' => now(), 'updated_at' => now()];

        DB::table('ip_addresses')->where('id', $ids['addresses']['10.10.10.1'])->update(['device_id' => $made['device']]);

        // Nothing is deleted under what refers to it: a device under its
        // address, a room under its device, a floor under its room, a
        // building under its floor, a site under its building.
        foreach ([['devices', $made['device']], ['rooms', $made['room']], ['floors', $made['floor']], ['buildings', $made['building']], ['sites', $site]] as [$table, $id]) {
            try {
                DB::table($table)->where('id', $id)->delete();
                $this->fail('A row of "'.$table.'" was deleted under what refers to it.');
            } catch (QueryException) {
                $this->assertTrue(DB::table($table)->where('id', $id)->exists());
            }
        }

        // A device of the same name on the same site, whatever its case.
        try {
            DB::table('devices')->insert(['site_id' => $site, 'room_id' => null, 'name' => 'fw-siège', 'type' => 'firewall', 'status' => 'active', ...$stamps]);
            $this->fail('Two devices of one site have the same name.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, DB::table('devices')->count());
        }

        // The same name on another site is another device.
        DB::table('devices')->insert(['site_id' => $ids['sites']['Atelier'], 'room_id' => null, 'name' => 'FW-Siège', 'type' => 'firewall', 'status' => 'active', ...$stamps]);

        // One floor per level, one name per floor, per building and per
        // site.
        foreach ([
            ['floors', ['building_id' => $made['building'], 'level' => 0, 'name' => 'Autre']],
            ['floors', ['building_id' => $made['building'], 'level' => 1, 'name' => 'Rez-de-chaussée']],
            ['rooms', ['floor_id' => $made['floor'], 'name' => 'Local technique']],
            ['buildings', ['site_id' => $site, 'name' => 'Bâtiment principal']],
            ['buildings', ['site_id' => $site, 'name' => 'Annexe', 'code' => 'A']],
        ] as [$table, $row]) {
            try {
                DB::table($table)->insert([...$row, ...$stamps]);
                $this->fail('A duplicate was accepted in "'.$table.'".');
            } catch (UniqueConstraintViolationException) {
                $this->addToAssertionCount(1);
            }
        }

        // An address cannot be attached to a device that does not exist.
        try {
            DB::table('ip_addresses')->where('id', $ids['addresses']['10.10.10.10'])->update(['device_id' => 999999]);
            $this->fail('An address was attached to a device that does not exist.');
        } catch (QueryException) {
            $this->assertNull(DB::table('ip_addresses')->where('id', $ids['addresses']['10.10.10.10'])->value('device_id'));
        }
    }

    public function test_going_back_keeps_what_the_former_version_knew(): void
    {
        $ids = $this->documentTheNetwork();

        $before = $this->everything();

        $this->artisan('migrate')->assertSuccessful();

        // What the new version adds: a status, places, a device and its
        // two interfaces.
        DB::table('sites')->where('id', $ids['sites']['Dépôt'])->update(['status' => 'planned']);

        $made = $this->furnish($ids['sites']['Siège']);

        DB::table('ip_addresses')
            ->whereIn('id', [$ids['addresses']['10.10.10.1'], $ids['addresses']['10.20.10.2']])
            ->update(['device_id' => $made['device']]);

        $this->rollBackToTheFormerVersion();

        // The status, the places, the devices and what attached addresses
        // to them are gone with their columns and tables; everything else
        // is as it was, the addresses with their host name and their type.
        $this->assertFalse(Schema::hasColumn('sites', 'status'));
        $this->assertFalse(Schema::hasColumn('ip_addresses', 'device_id'));

        foreach (self::NEW_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }

        $this->assertEquals($before, $this->everything());

        // And the upgrade can be run again.
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEquals($before, $this->withoutTheNewColumns($this->everything()));
        $this->assertSame(3, Site::query()->where('status', 'active')->count());
        $this->assertSame(5, IpAddress::query()->whereNull('device_id')->count());
    }
}
