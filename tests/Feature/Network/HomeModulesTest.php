<?php

// torsade/tests/Feature/Network/HomeModulesTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\Status;
use App\Enums\SubnetKind;
use App\Models\Activity;
use App\Models\AddressBlock;
use App\Models\AddressingRule;
use App\Models\Building;
use App\Models\CatalogVlan;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\Floor;
use App\Models\IpAddress;
use App\Models\Jack;
use App\Models\Port;
use App\Models\Site;
use App\Models\User;
use App\Models\Vlan;
use App\Services\AuditLogger;
use App\Services\Home\FullestSubnetsModule;
use App\Services\Home\HomeModule;
use App\Services\Home\HomeModules;
use App\Services\Home\RecentActivityModule;
use App\Services\Home\SitesModule;
use App\Support\Actor;
use Database\Factories\LinkFactory;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The home page is made of modules, in a fixed order: each one decides who
 * may see it, has its settings with their bounds, and prepares its own
 * data. A module refused to the user sends nothing.
 */
class HomeModulesTest extends NetworkTestCase
{
    /**
     * The modules of the home page of a user, as the page receives them.
     *
     * @return list<array{key: string, settings: array<string, int>, data: array<string, mixed>}>
     */
    private function modules(User $user): array
    {
        return $this->actingAs($user)->get(route('dashboard'))->assertOk()->inertiaProps('modules');
    }

    /**
     * @return list<string>
     */
    private function keys(User $user): array
    {
        return array_column($this->modules($user), 'key');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function module(User $user, string $key): ?array
    {
        return collect($this->modules($user))->firstWhere('key', $key)['data'] ?? null;
    }

    /**
     * What makes the first steps done: a site, a VLAN, a subnet and a
     * device.
     */
    private function documentTheEssentials(): Site
    {
        $site = Site::factory()->create(['name' => 'Siège']);
        $vlan = Vlan::factory()->create(['site_id' => $site->id, 'vid' => 10, 'name' => 'Bureautique']);
        $this->subnet('10.10.10.0/24', ['vlan_id' => $vlan->id]);
        Device::factory()->create(['site_id' => $site->id, 'name' => 'sw-coeur']);

        return $site;
    }

    public function test_the_modules_come_in_their_order_and_the_activity_only_for_an_administrator(): void
    {
        $this->documentTheEssentials();

        $this->assertSame(['search', 'summary', 'sites', 'fullest-subnets', 'recent-activity'], $this->keys($this->admin()));
        $this->assertSame(['search', 'summary', 'sites', 'fullest-subnets'], $this->keys($this->editor()));
        $this->assertSame(['search', 'summary', 'sites', 'fullest-subnets'], $this->keys($this->reader()));
    }

    public function test_a_module_refused_to_the_user_sends_nothing(): void
    {
        $site = $this->documentTheEssentials();
        app(AuditLogger::class)->record(ActivityEvent::SiteUpdated, Actor::console(), $site, ['name' => 'Old-head-office'], ['name' => 'Siège']);

        // What the page receives, as the browser reads it.
        $sent = fn (User $user): string => (string) json_encode(
            $this->actingAs($user)->get(route('dashboard'))->assertOk()->inertiaProps('modules'),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        foreach ([$this->reader(), $this->editor()] as $user) {
            // Neither the entry nor the way to the log.
            $this->assertStringNotContainsString('Old-head-office', $sent($user));
            $this->assertStringNotContainsString(route('activity-log.index'), $sent($user));
        }

        // Both are sent to an administrator: the checks above can fail.
        $this->assertStringContainsString('Old-head-office', $sent($this->admin()));
        $this->assertStringContainsString(route('activity-log.index'), $sent($this->admin()));

        // The module is not even asked for its data.
        $module = new class extends HomeModule
        {
            public function key(): string
            {
                return 'recent-activity';
            }

            public function authorize(User $user): bool
            {
                return $user->can('view-activity-log');
            }

            public function data(User $user, array $settings): array
            {
                throw new LogicException('The data of a refused module were asked for.');
            }
        };
        $this->app->instance(RecentActivityModule::class, $module);

        $this->assertNotContains('recent-activity', $this->keys($this->editor()));
    }

    public function test_the_recent_activity_shows_the_latest_entries_as_the_log_does(): void
    {
        $site = $this->documentTheEssentials();

        for ($number = 1; $number <= 12; $number++) {
            app(AuditLogger::class)->record(ActivityEvent::SiteUpdated, Actor::console(), $site, ['name' => 'Siège '.($number - 1)], ['name' => 'Siège '.$number]);
        }

        $activity = $this->module($this->admin(), 'recent-activity');

        $this->assertNotNull($activity);
        $this->assertCount(10, $activity['entries']);
        $this->assertSame(route('activity-log.index'), $activity['url']);
        // The newest first, labelled as on the page of the log.
        $this->assertSame('Site modifié', $activity['entries'][0]['event_label']);
        $this->assertSame([['field' => 'Nom', 'old' => 'Siège 11', 'new' => 'Siège 12']], $activity['entries'][0]['changes']);
        $this->assertSame(['type' => 'console'], $activity['entries'][0]['author']);
    }

    public function test_on_an_empty_installation_an_editor_sees_the_first_steps(): void
    {
        $steps = $this->module($this->editor(), 'getting-started')['steps'] ?? null;

        $this->assertNotNull($steps);
        $this->assertSame(['site', 'standard', 'vlans', 'addresses', 'devices', 'links', 'jacks'], array_column($steps, 'key'));
        $this->assertSame([false, false, false, false, false, false, false], array_column($steps, 'done'));
        $this->assertSame([false, true, false, false, false, false, false], array_column($steps, 'optional'));
        $this->assertSame('Créer un site', $steps[0]['title']);
        $this->assertSame([route('sites.index')], array_column($steps[0]['links'], 'url'));
        $this->assertSame(
            [route('address-blocks.index'), route('addressing-rule.show'), route('catalog-vlans.index')],
            array_column($steps[1]['links'], 'url'),
        );
        // There is no list of addresses: their step leads to the subnets.
        $this->assertSame('Documenter les adresses IP', $steps[3]['title']);
        $this->assertSame([route('subnets.index')], array_column($steps[3]['links'], 'url'));

        // The same for an administrator; it comes right after the search.
        $this->assertSame(['search', 'getting-started', 'summary', 'sites', 'fullest-subnets', 'recent-activity'], $this->keys($this->admin()));
    }

    public function test_a_step_is_done_when_each_kind_it_covers_has_an_object(): void
    {
        $editor = $this->editor();
        $done = fn (): array => array_column($this->module($editor, 'getting-started')['steps'] ?? [], 'done', 'key');

        $site = Site::factory()->create(['name' => 'Siège']);
        $this->assertTrue($done()['site']);

        // A VLAN without a subnet: the step of the VLANs and their subnets
        // is not done yet.
        $vlan = Vlan::factory()->create(['site_id' => $site->id, 'vid' => 10, 'name' => 'Bureautique']);
        $this->assertFalse($done()['vlans']);

        $subnet = $this->subnet('10.10.10.0/24', ['vlan_id' => $vlan->id]);
        $this->assertTrue($done()['vlans']);

        $this->assertFalse($done()['addresses']);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.10.10.20']);
        $this->assertTrue($done()['addresses']);

        Jack::factory()->create();
        $this->assertTrue($done()['jacks']);
        $this->assertFalse($done()['links']);

        $ports = [];

        foreach (['sw-1', 'sw-2'] as $name) {
            $member = DeviceMember::factory()->create(['device_id' => Device::factory()->create(['site_id' => $site->id, 'name' => $name])->id]);
            $ports[] = Port::factory()->create(['device_member_id' => $member->id, 'name' => '1/0/1']);
        }

        LinkFactory::between($ports[0], $ports[1]);

        // A site, a VLAN, a subnet and a device: the module is no longer shown.
        $this->assertNotContains('getting-started', $this->keys($editor));
    }

    public function test_declaring_the_standard_is_done_by_a_block_the_rule_or_an_entry_of_the_catalogue(): void
    {
        $editor = $this->editor();
        $standard = fn (): bool => collect($this->module($editor, 'getting-started')['steps'] ?? [])->firstWhere('key', 'standard')['done'];

        $this->assertFalse($standard());

        $entry = CatalogVlan::factory()->create(['vid' => 10, 'name' => 'BUREAUTIQUE']);
        $this->assertTrue($standard());
        $entry->delete();

        $block = AddressBlock::factory()->cidr('10.0.0.0/8')->create();
        $this->assertTrue($standard());

        AddressingRule::factory()->from($block)->create();
        $block->refresh();
        $this->assertTrue($standard());
    }

    public function test_a_reader_is_only_told_who_creates_the_first_site(): void
    {
        $reader = $this->reader();

        $this->assertSame(['search', 'getting-started', 'summary', 'sites', 'fullest-subnets'], $this->keys($reader));
        $this->assertSame(['steps' => null], $this->module($reader, 'getting-started'));

        // Once a site exists, a reader no longer sees the module, even if
        // the rest is missing.
        Site::factory()->create();
        $this->assertNotContains('getting-started', $this->keys($reader));
        $this->assertContains('getting-started', $this->keys($this->editor()));
    }

    public function test_the_summary_counts_what_is_not_retired_and_says_how_much_is_planned(): void
    {
        $site = Site::factory()->create(['name' => 'Siège']);
        Site::factory()->create(['status' => Status::Planned]);
        Site::factory()->create(['status' => Status::Retired]);
        $subnet = $this->subnet('10.10.10.0/24', ['site_id' => $site->id]);

        foreach ([Status::Planned, Status::Planned, Status::Planned, Status::Active, Status::Retired] as $host => $status) {
            IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.10.10.'.($host + 1), 'status' => $status]);
        }

        $floor = Floor::factory()->create(['building_id' => Building::factory()->create(['site_id' => $site->id])->id]);
        Jack::factory()->create(['floor_id' => $floor->id, 'status' => Status::Planned]);

        $counters = collect($this->module($this->reader(), 'summary')['counters'] ?? [])->keyBy('key');

        $this->assertSame(['sites', 'vlans', 'subnets', 'addresses', 'devices', 'links', 'jacks'], $counters->keys()->all());

        $this->assertSame(2, $counters['sites']['count']);
        $this->assertSame('Sites', $counters['sites']['label']);
        $this->assertSame('dont 1 prévu', $counters['sites']['planned_label']);

        $this->assertSame(4, $counters['addresses']['count']);
        $this->assertSame('Adresses IP', $counters['addresses']['label']);
        $this->assertSame('dont 3 prévues', $counters['addresses']['planned_label']);
        // The addresses have no list of their own.
        $this->assertNull($counters['addresses']['url']);

        $this->assertSame('Prise', $counters['jacks']['label']);
        $this->assertSame('dont 1 prévue', $counters['jacks']['planned_label']);

        // None planned: nothing said. Zero is singular in French.
        $this->assertSame(0, $counters['vlans']['count']);
        // The same in French whatever the number.
        $this->assertSame('VLAN', $counters['vlans']['label']);
        $this->assertNull($counters['vlans']['planned_label']);
        $this->assertSame('Sous-réseau', $counters['subnets']['label']);
        $this->assertSame('Équipement', $counters['devices']['label']);

        foreach (['sites' => 'sites.index', 'vlans' => 'vlans.index', 'subnets' => 'subnets.index', 'devices' => 'devices.index', 'links' => 'links.index', 'jacks' => 'jacks.index'] as $key => $route) {
            $this->assertSame(route($route), $counters[$key]['url']);
        }
    }

    public function test_the_sites_come_by_name_with_what_they_hold_and_their_diagrams(): void
    {
        $head = Site::factory()->create(['name' => 'Siège', 'code' => 'SIE']);
        Site::factory()->create(['name' => 'Atelier', 'status' => Status::Planned]);
        Site::factory()->create(['name' => 'Ancien dépôt', 'status' => Status::Retired]);
        Vlan::factory()->create(['site_id' => $head->id, 'vid' => 10, 'name' => 'Bureautique']);
        Vlan::factory()->create(['site_id' => $head->id, 'vid' => 20, 'name' => 'Ancien', 'status' => Status::Retired]);
        $this->subnet('10.10.10.0/24', ['site_id' => $head->id]);
        Device::factory()->count(2)->create(['site_id' => $head->id]);

        $sites = $this->module($this->reader(), 'sites');

        $this->assertNotNull($sites);
        $this->assertSame(['Atelier', 'Siège'], array_column($sites['sites'], 'name'));
        $this->assertSame(2, $sites['total']);
        $this->assertSame(route('sites.index'), $sites['url']);

        $siege = $sites['sites'][1];
        $this->assertSame([1, 1, 2], [$siege['vlans_count'], $siege['subnets_count'], $siege['devices_count']]);
        $this->assertSame('SIE', $siege['code']);
        $this->assertSame(route('sites.show', $head), $siege['url']);
        $this->assertSame(route('diagrams.physical', ['site' => $head->id]), $siege['physical_diagram_url']);
        $this->assertSame(route('diagrams.logical', ['site' => $head->id]), $siege['logical_diagram_url']);
    }

    public function test_past_twelve_sites_the_module_leads_to_all_of_them(): void
    {
        for ($number = 1; $number <= 14; $number++) {
            Site::factory()->create(['name' => sprintf('Site %02d', $number)]);
        }

        $sites = $this->module($this->reader(), 'sites');

        $this->assertNotNull($sites);
        $this->assertCount(12, $sites['sites']);
        $this->assertSame(14, $sites['total']);
    }

    public function test_the_fullest_subnets_are_segments_in_service_by_occupancy(): void
    {
        $site = Site::factory()->create(['name' => 'Siège']);
        $vlan = Vlan::factory()->create(['site_id' => $site->id, 'vid' => 10, 'name' => 'Bureautique']);

        $fill = function (string $cidr, int $addresses, array $attributes = []): void {
            $subnet = $this->subnet($cidr, $attributes);

            for ($host = 1; $host <= $addresses; $host++) {
                IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => preg_replace('/\.0\/\d+$/', '.'.$host, $cidr)]);
            }
        };

        // Half full, a quarter, three quarters, then two as full as each other.
        $fill('10.10.10.0/29', 3, ['vlan_id' => $vlan->id, 'name' => 'Bureaux']);
        $fill('10.10.20.0/29', 1);
        $fill('10.10.30.0/30', 2);
        $fill('10.10.40.0/30', 1);
        $fill('10.10.5.0/30', 1);
        $fill('10.10.6.0/29', 5);
        // Left aside: empty, retired, not a segment.
        $this->subnet('10.10.50.0/24');
        $fill('10.10.60.0/30', 2, ['status' => Status::Retired]);
        $fill('10.10.70.0/30', 2, ['kind' => SubnetKind::PointToPoint]);

        $fullest = $this->module($this->reader(), 'fullest-subnets')['subnets'] ?? [];

        // As full as each other: by address, 10.10.5.0 before 10.10.10.0.
        $this->assertSame(['10.10.30.0/30', '10.10.6.0/29', '10.10.5.0/30', '10.10.10.0/29', '10.10.40.0/30'], array_column($fullest, 'cidr'));
        $this->assertSame([2, 2], [$fullest[0]['documented_count'], $fullest[0]['usable_count']]);
        $this->assertSame([3, 6], [$fullest[3]['documented_count'], $fullest[3]['usable_count']]);
        $this->assertSame(['vid' => 10, 'name' => 'Bureautique'], $fullest[3]['vlan']);
        $this->assertSame('Siège', $fullest[3]['site']);
        $this->assertSame('Bureaux', $fullest[3]['name']);
    }

    public function test_each_module_receives_its_settings_by_default(): void
    {
        $this->documentTheEssentials();

        $settings = array_column($this->modules($this->admin()), 'settings', 'key');

        $this->assertSame([
            'search' => [],
            'summary' => [],
            'sites' => ['limit' => 12],
            'fullest-subnets' => ['limit' => 5],
            'recent-activity' => ['limit' => 10],
        ], $settings);
    }

    public function test_the_rules_of_a_module_refuse_a_setting_out_of_its_bounds(): void
    {
        $sites = app(SitesModule::class);

        $this->assertSame(['limit' => 12], $sites->resolve());
        $this->assertSame(['limit' => 1], $sites->resolve(['limit' => 1]));
        $this->assertSame(['limit' => 50], $sites->resolve(['limit' => '50']));

        // A blank value is not a setting left out: it is refused too.
        foreach ([0, 51, 'douze', 2.5, '', '  ', null] as $wrong) {
            try {
                $sites->resolve(['limit' => $wrong]);
                $this->fail('A limit of '.var_export($wrong, true).' was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('limit', $exception->errors());
            }
        }

        $this->expectException(ValidationException::class);
        app(FullestSubnetsModule::class)->resolve(['limit' => 21]);
    }

    public function test_settings_chosen_within_their_bounds_reach_the_module(): void
    {
        for ($number = 1; $number <= 4; $number++) {
            Site::factory()->create(['name' => 'Site '.$number]);
        }

        $modules = app(HomeModules::class)->forUser($this->reader(), ['sites' => ['limit' => 3]]);
        $sites = collect($modules)->firstWhere('key', 'sites');

        $this->assertSame(['limit' => 3], $sites['settings'] ?? null);
        $this->assertCount(3, $sites['data']['sites'] ?? []);

        $this->expectException(ValidationException::class);
        app(HomeModules::class)->forUser($this->reader(), ['sites' => ['limit' => 100]]);
    }

    public function test_every_module_declares_its_bounds_and_a_default_within_them(): void
    {
        foreach (app(HomeModules::class)->all() as $module) {
            $this->assertInstanceOf(HomeModule::class, $module);

            foreach ($module->settings() as $name => $setting) {
                $this->assertGreaterThanOrEqual($setting['min'], $setting['default'], "{$module->key()}.{$name}");
                $this->assertLessThanOrEqual($setting['max'], $setting['default'], "{$module->key()}.{$name}");
            }
        }

        $this->assertSame(
            ['search', 'getting-started', 'summary', 'sites', 'fullest-subnets', 'recent-activity'],
            array_map(fn (HomeModule $module): string => $module->key(), app(HomeModules::class)->all()),
        );
    }

    public function test_the_home_page_writes_nothing_in_the_activity_log(): void
    {
        $this->documentTheEssentials();
        $admin = $this->admin();
        $before = Activity::query()->count();

        $this->modules($admin);

        $this->assertSame($before, Activity::query()->count());
    }
}
