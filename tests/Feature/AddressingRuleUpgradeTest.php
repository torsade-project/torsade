<?php

// torsade/tests/Feature/AddressingRuleUpgradeTest.php

namespace Tests\Feature;

use App\Models\AddressingRule;
use App\Models\Site;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * An installation of version 0.5.1 is upgraded: the number of sites and the
 * addressing rule are added to a database that already holds a network.
 * Nothing of it is lost, and going back loses nothing the former version
 * knew.
 *
 * These tests change the schema, which no transaction can undo: each one
 * rebuilds the database before and after itself.
 */
class AddressingRuleUpgradeTest extends TestCase
{
    /**
     * The first migration of the version: everything from it on is rolled
     * back to get the schema of version 0.5.1.
     */
    private const string FIRST_MIGRATION = '2026_10_11_200000_add_number_to_sites_table';

    /**
     * The tables of the network documentation in version 0.5.1, and the
     * activity log that tells its story.
     *
     * @var list<string>
     */
    private const array TABLES = [
        'sites', 'catalog_vlans', 'vlans', 'address_blocks', 'subnets', 'ip_ranges', 'ip_addresses', 'activity_log',
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
     * Fill the database the way version 0.5.1 would have: with the tables
     * and the columns it had, and nothing else.
     *
     * @return array{sites: array<string, int>, blocks: array<string, int>}
     */
    private function documentTheNetwork(): array
    {
        $now = now();
        $stamps = ['created_at' => $now, 'updated_at' => $now];

        $sites = [];

        foreach ([['Siège', 'SIE', 'Bureaux'], ['Atelier', 'ATE', null], ['Dépôt', null, null]] as [$name, $code, $description]) {
            $sites[$name] = (int) DB::table('sites')->insertGetId(['name' => $name, 'code' => $code, 'description' => $description, ...$stamps]);
        }

        $entries = [];

        foreach ([[10, 'Bureautique'], [20, 'Serveurs']] as [$vid, $name]) {
            $entries[$vid] = (int) DB::table('catalog_vlans')->insertGetId(['vid' => $vid, 'name' => $name, 'description' => null, ...$stamps]);
        }

        $vlans = [];

        foreach ([['Siège', 10, 'Bureautique', 10, 'active'], ['Siège', 20, 'Serveurs', 20, 'planned'], ['Atelier', 110, 'Bureaux', 10, 'active'], ['Atelier', 900, 'Divers', null, 'retired']] as [$site, $vid, $name, $entry, $status]) {
            $vlans[$site.'/'.$vid] = (int) DB::table('vlans')->insertGetId([
                'site_id' => $sites[$site],
                'catalog_vlan_id' => $entry === null ? null : $entries[$entry],
                'vid' => $vid, 'name' => $name, 'description' => null, 'status' => $status, ...$stamps,
            ]);
        }

        $blocks = [];

        foreach ([['10.0.0.0', 8, null, 'Adressage interne'], ['10.10.0.0', 16, 'Siège', null], ['10.20.0.0', 16, 'Atelier', 'Atelier']] as [$network, $prefix, $site, $name]) {
            $blocks[$network.'/'.$prefix] = (int) DB::table('address_blocks')->insertGetId([
                'site_id' => $site === null ? null : $sites[$site],
                'ip_version' => 4, 'network' => inet_pton($network), 'prefix_length' => $prefix,
                'name' => $name, 'description' => null, 'status' => 'active', ...$stamps,
            ]);
        }

        $subnets = [];

        foreach ([
            ['10.10.10.0', 24, 'Siège/10', 'Siège', 'segment', '10.10.10.254'],
            ['10.20.110.0', 24, 'Atelier/110', 'Atelier', 'segment', '10.20.110.1'],
            ['203.0.113.8', 29, null, 'Siège', 'external', '203.0.113.9'],
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

        foreach ([['10.10.10.0', 'dhcp', '10.10.10.100', '10.10.10.199', null], ['10.10.10.0', 'static', '10.10.10.10', '10.10.10.99', 'Serveurs'], ['10.20.110.0', 'reserved', '10.20.110.200', '10.20.110.250', 'Extension']] as [$subnet, $kind, $start, $end, $name]) {
            DB::table('ip_ranges')->insert([
                'subnet_id' => $subnets[$subnet], 'kind' => $kind,
                'start_address' => inet_pton($start), 'end_address' => inet_pton($end),
                'name' => $name, 'description' => null, ...$stamps,
            ]);
        }

        foreach ([['10.10.10.0', '10.10.10.10', 'srv-fichiers-01', 'static'], ['10.10.10.0', '10.10.10.110', 'pc-accueil-01', 'dhcp_reservation'], ['10.255.0.0', '10.255.0.1', 'rtr-b', 'static']] as [$subnet, $address, $hostname, $assignment]) {
            DB::table('ip_addresses')->insert([
                'subnet_id' => $subnets[$subnet], 'address' => inet_pton($address), 'hostname' => $hostname,
                'mac_address' => null, 'device_type' => null, 'assignment' => $assignment,
                'description' => null, 'status' => 'active', ...$stamps,
            ]);
        }

        // What the former version wrote about a site and about a block.
        foreach ([['site.created', 'App\\Models\\Site', $sites['Siège'], ['name' => 'Siège', 'code' => 'SIE', 'description' => 'Bureaux'], 'Siège'], ['address_block.created', 'App\\Models\\AddressBlock', $blocks['10.0.0.0/8'], ['network' => '10.0.0.0', 'prefix_length' => 8], '10.0.0.0/8']] as [$event, $type, $id, $attributes, $label]) {
            DB::table('activity_log')->insert([
                'log_name' => 'default', 'description' => $event, 'subject_type' => $type, 'subject_id' => $id, 'event' => $event,
                'causer_type' => null, 'causer_id' => null,
                'attribute_changes' => json_encode(['old' => [], 'attributes' => $attributes], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'properties' => json_encode(['source' => 'console', 'label' => $label], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ...$stamps,
            ]);
        }

        return ['sites' => $sites, 'blocks' => $blocks];
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
     * The same rows without the column the upgrade adds, nor those the
     * versions after it add.
     *
     * @param  array<string, list<array<string, mixed>>>  $tables
     * @return array<string, list<array<string, mixed>>>
     */
    private function withoutTheNumberOfSites(array $tables): array
    {
        $tables['sites'] = array_map(fn (array $row): array => array_diff_key($row, ['number' => true, 'status' => true]), $tables['sites']);
        $tables['ip_addresses'] = array_map(fn (array $row): array => array_diff_key($row, ['device_id' => true, 'jack_id' => true]), $tables['ip_addresses']);

        return $tables;
    }

    /**
     * A rule written straight to its table, as nothing in the application
     * does: what the database itself accepts.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function insertRule(int $blockId, array $overrides = []): int
    {
        return (int) DB::table('addressing_rules')->insertGetId([
            'aggregate_block_id' => $blockId,
            'site_prefix_length' => 16,
            'vlan_prefix_length' => 24,
            'gateway_position' => 'first',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    public function test_the_former_schema_is_the_one_of_the_former_version(): void
    {
        $this->assertFalse(Schema::hasColumn('sites', 'number'));
        $this->assertFalse(Schema::hasTable('addressing_rules'));
        $this->assertFalse(Schema::hasTable('addressing_rule_ranges'));

        // What version 0.5.1 had is there.
        $this->assertTrue(Schema::hasColumn('sites', 'code'));
        $this->assertTrue(Schema::hasTable('address_blocks'));
        $this->assertTrue(Schema::hasTable('ip_ranges'));
    }

    public function test_the_upgrade_keeps_every_object_as_it_was_and_leaves_the_sites_without_a_number(): void
    {
        $this->documentTheNetwork();

        $before = $this->everything();

        $this->assertSame(
            ['sites' => 3, 'catalog_vlans' => 2, 'vlans' => 4, 'address_blocks' => 3, 'subnets' => 4, 'ip_ranges' => 3, 'ip_addresses' => 3, 'activity_log' => 2],
            array_map(count(...), $before),
        );

        $this->artisan('migrate')->assertSuccessful();

        // What the tables held is still there, column for column and byte
        // for byte, the activity log included.
        $this->assertEquals($before, $this->withoutTheNumberOfSites($this->everything()));

        // The new column starts empty, and there is no rule.
        $this->assertTrue(Schema::hasColumn('sites', 'number'));
        $this->assertSame(3, Site::query()->whereNull('number')->count());
        $this->assertSame(0, AddressingRule::query()->count());
        $this->assertSame(0, DB::table('addressing_rule_ranges')->count());

        // The sites are read as before, with their code.
        $this->assertSame(
            ['Atelier' => 'ATE', 'Dépôt' => null, 'Siège' => 'SIE'],
            Site::query()->orderBy('name')->pluck('code', 'name')->all(),
        );
    }

    public function test_the_database_itself_refuses_a_second_rule(): void
    {
        $ids = $this->documentTheNetwork();

        $this->artisan('migrate')->assertSuccessful();

        $block = $ids['blocks']['10.0.0.0/8'];

        $this->insertRule($block);

        // A second line, whatever writes it.
        try {
            $this->insertRule($block, ['site_prefix_length' => 20]);
            $this->fail('A second rule was accepted.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, DB::table('addressing_rules')->count());
        }

        // Nor with another value in the column that keeps the rule alone.
        try {
            $this->insertRule($block, ['singleton' => 2]);
            $this->fail('A second rule was accepted with another value.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('addressing_rules_singleton_check', $exception->getMessage());
        }

        try {
            DB::table('addressing_rules')->update(['singleton' => 0]);
            $this->fail('The value that keeps the rule alone was changed.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('addressing_rules_singleton_check', $exception->getMessage());
        }

        $this->assertSame(1, DB::table('addressing_rules')->count());
    }

    public function test_the_block_of_the_rule_is_kept_and_the_ranges_go_with_the_rule(): void
    {
        $ids = $this->documentTheNetwork();

        $this->artisan('migrate')->assertSuccessful();

        $block = $ids['blocks']['10.0.0.0/8'];
        $rule = $this->insertRule($block);

        DB::table('addressing_rule_ranges')->insert([
            ['addressing_rule_id' => $rule, 'kind' => 'reserved', 'start_offset' => 2, 'end_offset' => 20, 'name' => 'Infrastructure', 'created_at' => now(), 'updated_at' => now()],
            ['addressing_rule_id' => $rule, 'kind' => 'dhcp', 'start_offset' => 100, 'end_offset' => 200, 'name' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // The block a rule starts from cannot be deleted under it.
        try {
            DB::table('address_blocks')->where('id', $block)->delete();
            $this->fail('The block of the rule was deleted.');
        } catch (QueryException) {
            $this->assertSame(3, DB::table('address_blocks')->count());
        }

        // Deleting the rule deletes its ranges, and nothing else.
        $before = $this->everything();

        DB::table('addressing_rules')->where('id', $rule)->delete();

        $this->assertSame(0, DB::table('addressing_rule_ranges')->count());
        $this->assertEquals($before, $this->everything());
    }

    public function test_going_back_keeps_what_the_former_version_knew(): void
    {
        $ids = $this->documentTheNetwork();

        $before = $this->everything();

        $this->artisan('migrate')->assertSuccessful();

        // What the new version adds: numbers, a rule and its ranges.
        DB::table('sites')->where('id', $ids['sites']['Siège'])->update(['number' => 10]);
        DB::table('sites')->where('id', $ids['sites']['Atelier'])->update(['number' => 20]);

        $rule = $this->insertRule($ids['blocks']['10.0.0.0/8']);

        DB::table('addressing_rule_ranges')->insert([
            'addressing_rule_id' => $rule, 'kind' => 'dhcp', 'start_offset' => 100, 'end_offset' => 200, 'name' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->rollBackToTheFormerVersion();

        // The numbers, the rule and its ranges are gone with their columns
        // and tables; everything else is as it was.
        $this->assertFalse(Schema::hasColumn('sites', 'number'));
        $this->assertFalse(Schema::hasTable('addressing_rules'));
        $this->assertFalse(Schema::hasTable('addressing_rule_ranges'));
        $this->assertEquals($before, $this->everything());

        // And the upgrade can be run again.
        $this->artisan('migrate')->assertSuccessful();

        $this->assertEquals($before, $this->withoutTheNumberOfSites($this->everything()));
        $this->assertSame(3, Site::query()->whereNull('number')->count());
    }
}
