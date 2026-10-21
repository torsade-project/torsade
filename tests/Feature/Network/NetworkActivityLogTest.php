<?php

// torsade/tests/Feature/Network/NetworkActivityLogTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Models\IpAddress;
use App\Models\IpRange;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\User;
use App\Models\Vlan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Every creation, update and deletion of a network object is written to the
 * activity log with its author and the values before and after, addresses
 * being recorded as text.
 */
class NetworkActivityLogTest extends NetworkTestCase
{
    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->editor = User::factory()->editor()->create(['name' => 'Eden Editor']);
    }

    /**
     * @return array<string, mixed>
     */
    private function old(Activity $entry): array
    {
        return $entry->attribute_changes?->toArray()['old'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function new(Activity $entry): array
    {
        return $entry->attribute_changes?->toArray()['attributes'] ?? [];
    }

    /**
     * The whole log table as one string, to look for what must not be there.
     */
    private function rawLog(): string
    {
        return DB::table('activity_log')->get()->map(fn (object $row): string => (string) json_encode($row))->implode("\n");
    }

    // --- Sites --------------------------------------------------------------

    public function test_the_life_of_a_site_is_logged(): void
    {
        $this->actingAs($this->editor)->post(route('sites.store'), ['status' => 'active', 'name' => 'Head office', 'description' => 'Main building']);

        $site = Site::query()->sole();
        $created = $this->lastEntry(ActivityEvent::SiteCreated);

        $this->assertTrue($this->editor->is($created->causer));
        $this->assertTrue($site->is($created->subject));
        $this->assertSame(['name' => 'Head office', 'code' => null, 'number' => null, 'status' => 'active', 'description' => 'Main building'], $this->new($created));
        $this->assertSame([], $this->old($created));
        $this->assertSame('Head office', $created->getProperty('label'));
        $this->assertSame('web', $created->getProperty('source'));

        $this->actingAs($this->editor)->put(route('sites.update', $site), ['status' => 'active', 'name' => 'Headquarters', 'description' => 'Main building']);

        $updated = $this->lastEntry(ActivityEvent::SiteUpdated);

        // Only what changed, before and after.
        $this->assertSame(['name' => 'Head office'], $this->old($updated));
        $this->assertSame(['name' => 'Headquarters'], $this->new($updated));

        $this->actingAs($this->editor)->delete(route('sites.destroy', $site));

        $deleted = $this->lastEntry(ActivityEvent::SiteDeleted);

        $this->assertSame(['name' => 'Headquarters', 'code' => null, 'number' => null, 'status' => 'active', 'description' => 'Main building'], $this->old($deleted));
        $this->assertSame([], $this->new($deleted));
        // The site is gone, its name stays in the log.
        $this->assertNull($deleted->subject);
        $this->assertSame('Headquarters', $deleted->getProperty('label'));
    }

    public function test_an_update_that_changes_nothing_is_not_logged(): void
    {
        $site = Site::factory()->create(['name' => 'Head office', 'description' => null]);
        $vlan = Vlan::factory()->create(['site_id' => $site->id, 'vid' => 10, 'name' => 'Offices']);
        $subnet = $this->subnet('192.168.100.0/24', ['gateway' => '192.168.100.1']);
        $range = IpRange::factory()->between('192.168.100.100', '192.168.100.199')->create(['subnet_id' => $subnet->id]);
        $address = IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.100.10']);

        $this->actingAs($this->editor)->put(route('sites.update', $site), ['status' => 'active', 'name' => 'Head office', 'description' => '']);
        $this->actingAs($this->editor)->put(route('vlans.update', $vlan), [
            'site_id' => $site->id, 'vid' => 10, 'name' => 'Offices', 'status' => 'active',
        ]);
        $this->actingAs($this->editor)->put(route('subnets.update', $subnet), [
            'network' => '192.168.100.0/24', 'gateway' => '192.168.100.1', 'status' => 'active',
        ]);
        $this->actingAs($this->editor)->put(route('ip-ranges.update', $range), [
            'kind' => 'dhcp', 'start_address' => '192.168.100.100', 'end_address' => '192.168.100.199',
        ]);
        $this->actingAs($this->editor)->put(route('ip-addresses.update', $address), [
            'address' => '192.168.100.10', 'assignment' => 'static', 'status' => 'active',
        ]);

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_a_refused_change_is_not_logged(): void
    {
        Site::factory()->create(['name' => 'Head office']);
        $subnet = $this->subnet('192.168.100.0/24');

        $this->actingAs($this->editor)->post(route('sites.store'), ['status' => 'active', 'name' => 'Head office']);
        $this->actingAs($this->editor)->post(route('subnets.store'), ['network' => '192.168.100.128/25', 'status' => 'active']);
        $this->actingAs($this->editor)->post(route('ip-addresses.store', $subnet), [
            'address' => '192.168.101.1', 'assignment' => 'static', 'status' => 'active',
        ]);
        $this->actingAs($this->reader())->post(route('sites.store'), ['status' => 'active', 'name' => 'Workshop']);

        $this->assertSame(0, Activity::query()->count());
    }

    // --- VLANs --------------------------------------------------------------

    public function test_the_life_of_a_vlan_is_logged_with_the_name_of_its_site(): void
    {
        $site = Site::factory()->create(['name' => 'Head office']);
        $workshop = Site::factory()->create(['name' => 'Workshop']);

        $this->actingAs($this->editor)->post(route('vlans.store'), [
            'site_id' => $site->id, 'vid' => 10, 'name' => 'Offices', 'description' => null, 'status' => 'planned',
        ]);

        $vlan = Vlan::query()->sole();
        $created = $this->lastEntry(ActivityEvent::VlanCreated);

        $this->assertTrue($vlan->is($created->subject));
        $this->assertSame([
            'site' => 'Head office',
            'catalog' => null,
            'vid' => 10,
            'name' => 'Offices',
            'description' => null,
            'status' => 'planned',
        ], $this->new($created));
        $this->assertSame('VLAN 10 — Offices (Head office)', $created->getProperty('label'));

        $this->actingAs($this->editor)->put(route('vlans.update', $vlan), [
            'site_id' => $workshop->id, 'vid' => 15, 'name' => 'Offices', 'status' => 'active',
        ]);

        $updated = $this->lastEntry(ActivityEvent::VlanUpdated);

        $this->assertSame(['site' => 'Head office', 'vid' => 10, 'status' => 'planned'], $this->old($updated));
        $this->assertSame(['site' => 'Workshop', 'vid' => 15, 'status' => 'active'], $this->new($updated));
        $this->assertSame('VLAN 15 — Offices (Workshop)', $updated->getProperty('label'));

        $this->actingAs($this->editor)->delete(route('vlans.destroy', $vlan));

        $deleted = $this->lastEntry(ActivityEvent::VlanDeleted);

        $this->assertSame('Offices', $this->old($deleted)['name']);
        $this->assertSame('VLAN 15 — Offices (Workshop)', $deleted->getProperty('label'));
    }

    // --- Subnets ------------------------------------------------------------

    public function test_the_life_of_a_subnet_is_logged_with_addresses_as_text(): void
    {
        $site = Site::factory()->create(['name' => 'Head office']);
        $vlan = Vlan::factory()->create(['site_id' => $site->id, 'vid' => 10, 'name' => 'Offices']);

        $this->actingAs($this->editor)->post(route('subnets.store'), [
            'network' => '192.168.100.0/24',
            'vlan_id' => $vlan->id,
            'gateway' => '192.168.100.254',
            'name' => 'Offices',
            'status' => 'active',
        ]);

        $subnet = Subnet::query()->sole();
        $created = $this->lastEntry(ActivityEvent::SubnetCreated);

        $this->assertTrue($subnet->is($created->subject));
        $this->assertSame([
            'network' => '192.168.100.0',
            'prefix_length' => 24,
            'kind' => 'segment',
            'gateway' => '192.168.100.254',
            'vlan' => 'VLAN 10 — Offices (Head office)',
            'site' => 'Head office',
            'name' => 'Offices',
            'description' => null,
            'status' => 'active',
        ], $this->new($created));
        $this->assertSame('192.168.100.0/24', $created->getProperty('label'));

        $this->actingAs($this->editor)->put(route('subnets.update', $subnet), [
            'network' => '192.168.100.0/23',
            'vlan_id' => null,
            'gateway' => '192.168.100.1',
            'name' => 'Offices',
            'status' => 'active',
        ]);

        $updated = $this->lastEntry(ActivityEvent::SubnetUpdated);

        // Without its VLAN, the subnet is no longer on the site of that VLAN.
        $this->assertSame(
            ['prefix_length' => 24, 'gateway' => '192.168.100.254', 'vlan' => 'VLAN 10 — Offices (Head office)', 'site' => 'Head office'],
            $this->old($updated),
        );
        $this->assertSame(['prefix_length' => 23, 'gateway' => '192.168.100.1', 'vlan' => null, 'site' => null], $this->new($updated));
        $this->assertSame('192.168.100.0/23', $updated->getProperty('label'));

        $this->actingAs($this->editor)->delete(route('subnets.destroy', $subnet));

        $deleted = $this->lastEntry(ActivityEvent::SubnetDeleted);

        $this->assertSame('192.168.100.0', $this->old($deleted)['network']);
        $this->assertSame('192.168.100.0/23', $deleted->getProperty('label'));
    }

    public function test_a_vlan_created_with_its_subnet_gives_two_entries(): void
    {
        $site = Site::factory()->create(['name' => 'Head office']);

        $this->actingAs($this->editor)->post(route('vlans.store'), [
            'site_id' => $site->id,
            'vid' => 10,
            'name' => 'Offices',
            'status' => 'active',
            'with_subnet' => true,
            'subnet' => ['network' => '192.168.10.0/24', 'gateway' => '192.168.10.1'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->countEntries(ActivityEvent::VlanCreated));
        $this->assertSame(1, $this->countEntries(ActivityEvent::SubnetCreated));
        $this->assertSame('VLAN 10 — Offices (Head office)', $this->new($this->lastEntry(ActivityEvent::SubnetCreated))['vlan']);
    }

    // --- Ranges -------------------------------------------------------------

    public function test_the_life_of_a_range_is_logged(): void
    {
        $subnet = $this->subnet('192.168.100.0/24');

        $this->actingAs($this->editor)->post(route('ip-ranges.store', $subnet), [
            'kind' => 'dhcp', 'start_address' => '192.168.100.100', 'end_address' => '192.168.100.199', 'description' => 'Workstations',
        ]);

        $range = IpRange::query()->sole();
        $created = $this->lastEntry(ActivityEvent::IpRangeCreated);

        $this->assertTrue($range->is($created->subject));
        $this->assertSame([
            'subnet' => '192.168.100.0/24',
            'kind' => 'dhcp',
            'kind' => 'dhcp', 'start_address' => '192.168.100.100',
            'end_address' => '192.168.100.199',
            'name' => null,
            'description' => 'Workstations',
        ], $this->new($created));
        $this->assertSame('192.168.100.100 – 192.168.100.199', $created->getProperty('label'));

        $this->actingAs($this->editor)->put(route('ip-ranges.update', $range), [
            'kind' => 'dhcp', 'start_address' => '192.168.100.100', 'end_address' => '192.168.100.149', 'description' => 'Workstations',
        ]);

        $updated = $this->lastEntry(ActivityEvent::IpRangeUpdated);

        $this->assertSame(['end_address' => '192.168.100.199'], $this->old($updated));
        $this->assertSame(['end_address' => '192.168.100.149'], $this->new($updated));

        $this->actingAs($this->editor)->delete(route('ip-ranges.destroy', $range));

        $deleted = $this->lastEntry(ActivityEvent::IpRangeDeleted);

        $this->assertSame('192.168.100.149', $this->old($deleted)['end_address']);
        $this->assertSame('192.168.100.100 – 192.168.100.149', $deleted->getProperty('label'));
    }

    // --- IP addresses -------------------------------------------------------

    public function test_the_life_of_an_ip_address_is_logged(): void
    {
        $subnet = $this->subnet('192.168.100.0/24');

        $this->actingAs($this->editor)->post(route('ip-addresses.store', $subnet), [
            'address' => '192.168.100.10',
            'hostname' => 'srv-files-01',
            'mac_address' => 'AA-BB-CC-DD-EE-FF',
            'device_type' => 'server',
            'assignment' => 'static',
            'status' => 'active',
            'description' => 'File server',
        ]);

        $address = IpAddress::query()->sole();
        $created = $this->lastEntry(ActivityEvent::IpAddressCreated);

        $this->assertTrue($this->editor->is($created->causer));
        $this->assertTrue($address->is($created->subject));
        $this->assertSame([
            'subnet' => '192.168.100.0/24',
            'address' => '192.168.100.10',
            'hostname' => 'srv-files-01',
            // As stored, not as typed.
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'device_type' => 'server',
            'assignment' => 'static',
            'device' => null,
            'jack' => null,
            'status' => 'active',
            'description' => 'File server',
        ], $this->new($created));
        $this->assertSame('192.168.100.10', $created->getProperty('label'));

        $this->actingAs($this->editor)->put(route('ip-addresses.update', $address), [
            'address' => '192.168.100.11',
            'hostname' => 'srv-files-01',
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'device_type' => 'nas',
            'assignment' => 'static',
            'status' => 'retired',
            'description' => 'File server',
        ]);

        $updated = $this->lastEntry(ActivityEvent::IpAddressUpdated);

        $this->assertSame(['address' => '192.168.100.10', 'device_type' => 'server', 'status' => 'active'], $this->old($updated));
        $this->assertSame(['address' => '192.168.100.11', 'device_type' => 'nas', 'status' => 'retired'], $this->new($updated));

        $this->actingAs($this->editor)->delete(route('ip-addresses.destroy', $address));

        $deleted = $this->lastEntry(ActivityEvent::IpAddressDeleted);

        // The record is gone and its number is free again: the log still
        // says which address it was and who held it.
        $this->assertNull($deleted->subject);
        $this->assertSame('192.168.100.11', $deleted->getProperty('label'));
        $this->assertSame('srv-files-01', $this->old($deleted)['hostname']);
    }

    public function test_a_quick_entry_gives_one_entry_per_address(): void
    {
        $subnet = $this->subnet('192.168.100.0/24');

        $this->actingAs($this->editor)->post(route('ip-addresses.bulk', $subnet), [
            'addresses' => [
                ['address' => '192.168.100.10', 'hostname' => 'pc-10'],
                ['address' => '192.168.100.11', 'hostname' => 'pc-11'],
                ['address' => '192.168.100.12', 'hostname' => 'pc-12'],
            ],
        ])->assertSessionHasNoErrors();

        $entries = Activity::query()->where('event', ActivityEvent::IpAddressCreated->value)->orderBy('id')->get();

        $this->assertCount(3, $entries);
        $this->assertSame(
            ['192.168.100.10', '192.168.100.11', '192.168.100.12'],
            $entries->map(fn (Activity $entry): mixed => $entry->getProperty('label'))->all(),
        );
        $this->assertSame('pc-12', $this->new($entries[2])['hostname']);

        foreach ($entries as $entry) {
            $this->assertTrue($this->editor->is($entry->causer));
        }
    }

    public function test_no_entry_ever_holds_an_address_in_binary(): void
    {
        $subnet = $this->subnet('192.168.100.0/24');

        $this->actingAs($this->editor)->put(route('subnets.update', $subnet), [
            'network' => '192.168.100.0/24', 'gateway' => '192.168.100.65', 'status' => 'active',
        ]);
        $this->actingAs($this->editor)->post(route('ip-ranges.store', $subnet), [
            'kind' => 'dhcp', 'start_address' => '192.168.100.66', 'end_address' => '192.168.100.67',
        ]);
        $this->actingAs($this->editor)->post(route('ip-addresses.store', $subnet), [
            'address' => '192.168.100.68', 'assignment' => 'static', 'status' => 'active',
        ]);

        $this->assertSame(3, Activity::query()->count());

        $log = $this->rawLog();

        foreach (['192.168.100.65', '192.168.100.66', '192.168.100.67', '192.168.100.68'] as $address) {
            $this->assertStringContainsString($address, $log);
        }

        // The four bytes of 192.168.100.65 to .68 would read "À¨dA" to "À¨dD",
        // or be escaped by the JSON encoder: neither form is in the log.
        foreach (['A', 'B', 'C', 'D'] as $lastByte) {
            $this->assertStringNotContainsString("\xC0\xA8\x64".$lastByte, $log);
            $this->assertStringNotContainsString('À¨d'.$lastByte, $log);
        }
    }

    // --- The activity log page ----------------------------------------------

    public function test_the_page_shows_network_changes_with_french_labels(): void
    {
        $subnet = $this->subnet('192.168.100.0/24');
        $admin = $this->admin();

        $this->actingAs($this->editor)->post(route('ip-addresses.store', $subnet), [
            'address' => '192.168.100.10',
            'hostname' => 'srv-files-01',
            'device_type' => 'server',
            'assignment' => 'static',
            'status' => 'planned',
        ]);

        $this->actingAs($admin)
            ->get(route('activity-log.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.event', 'ip_address.created')
                ->where('entries.data.0.event_label', 'Adresse IP créée')
                ->where('entries.data.0.author.name', 'Eden Editor')
                ->where('entries.data.0.subject.title', '192.168.100.10')
                ->where('entries.data.0.subject.detail', null)
                // Fields left empty are not listed for a creation.
                ->where('entries.data.0.changes', [
                    ['field' => 'Sous-réseau', 'old' => null, 'new' => '192.168.100.0/24'],
                    ['field' => 'Adresse', 'old' => null, 'new' => '192.168.100.10'],
                    ['field' => "Nom d'hôte", 'old' => null, 'new' => 'srv-files-01'],
                    ['field' => 'Type', 'old' => null, 'new' => 'Serveur'],
                    ['field' => 'Affectation', 'old' => null, 'new' => 'Fixe'],
                    // Agreed with the address the entry is about.
                    ['field' => 'Statut', 'old' => null, 'new' => 'Prévue'],
                ]));
    }

    public function test_the_page_shows_what_an_update_emptied(): void
    {
        $subnet = $this->subnet('192.168.100.0/24', ['gateway' => '192.168.100.1', 'status' => 'planned']);

        $this->actingAs($this->editor)->put(route('subnets.update', $subnet), [
            'network' => '192.168.100.0/24', 'gateway' => '', 'status' => 'active',
        ]);

        $this->actingAs($this->admin())
            ->get(route('activity-log.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.event_label', 'Sous-réseau modifié')
                ->where('entries.data.0.subject.title', '192.168.100.0/24')
                ->where('entries.data.0.changes', [
                    ['field' => 'Passerelle', 'old' => '192.168.100.1', 'new' => '—'],
                    ['field' => 'Statut', 'old' => 'Prévu', 'new' => 'Actif'],
                ]));
    }

    public function test_the_page_still_names_a_deleted_object(): void
    {
        $site = Site::factory()->create(['name' => 'Workshop', 'description' => 'North building']);

        $this->actingAs($this->editor)->delete(route('sites.destroy', $site));

        $this->actingAs($this->admin())
            ->get(route('activity-log.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.event_label', 'Site supprimé')
                ->where('entries.data.0.subject.title', 'Workshop')
                ->where('entries.data.0.changes', [
                    ['field' => 'Nom', 'old' => 'Workshop', 'new' => null],
                    ['field' => 'Statut', 'old' => 'Actif', 'new' => null],
                    ['field' => 'Description', 'old' => 'North building', 'new' => null],
                ]));
    }

    public function test_the_page_can_be_filtered_by_network_event(): void
    {
        $subnet = $this->subnet('192.168.100.0/24');
        $admin = $this->admin();

        $this->actingAs($this->editor)->post(route('sites.store'), ['status' => 'active', 'name' => 'Head office']);
        $this->actingAs($this->editor)->post(route('ip-addresses.store', $subnet), [
            'address' => '192.168.100.10', 'assignment' => 'static', 'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->get(route('activity-log.index', ['event' => 'site.created']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.event', 'site.created')
                ->where('entries.data.0.event_label', 'Site créé')
                ->where('filters.event', 'site.created'));

        // The entries of an editor are found through the filter on the user.
        $this->actingAs($admin)
            ->get(route('activity-log.index', ['user' => $this->editor->id]))
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 2));
    }

    public function test_every_network_event_is_offered_by_the_filter(): void
    {
        $this->actingAs($this->admin())
            ->get(route('activity-log.index'))
            ->assertInertia(function (Assert $page): void {
                $events = collect($page->toArray()['props']['events'])->pluck('label', 'value');

                foreach (['site', 'catalog_vlan', 'vlan', 'address_block', 'subnet', 'ip_range', 'ip_address'] as $object) {
                    foreach (['created', 'updated', 'deleted'] as $action) {
                        $this->assertTrue($events->has("{$object}.{$action}"), "[{$object}.{$action}] is not offered.");
                    }
                }

                $this->assertSame('VLAN modifié', $events['vlan.updated']);
                $this->assertSame('Plage supprimée', $events['ip_range.deleted']);
                $this->assertSame("Bloc d'adressage créé", $events['address_block.created']);
                $this->assertSame('Entrée du catalogue modifiée', $events['catalog_vlan.updated']);
            });
    }
}
