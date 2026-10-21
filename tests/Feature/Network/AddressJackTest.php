<?php

// torsade/tests/Feature/Network/AddressJackTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\DeviceType;
use App\Enums\PortMode;
use App\Models\Building;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\Floor;
use App\Models\IpAddress;
use App\Models\Jack;
use App\Models\PatchPanel;
use App\Models\PatchPanelPort;
use App\Models\PortVlan;
use App\Models\Room;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\User;
use App\Models\Vlan;
use Database\Factories\PatchCordFactory;
use Database\Factories\PortFactory;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * The jack an address is plugged into: one of the site of its subnet, told
 * by events of its own, and shown with its path wherever the address is.
 */
class AddressJackTest extends NetworkTestCase
{
    private User $editor;

    private Site $office;

    private Floor $first;

    private Subnet $offices;

    private Jack $jack;

    protected function setUp(): void
    {
        parent::setUp();

        $this->editor = $this->editor();
        $this->office = Site::factory()->create(['name' => 'Siège']);

        $building = Building::factory()->create(['site_id' => $this->office->id, 'name' => 'Bâtiment principal']);
        $this->first = Floor::factory()->create(['building_id' => $building->id, 'level' => 1, 'name' => '1er étage']);

        $this->offices = $this->subnet('10.10.10.0/24', ['site_id' => $this->office->id]);
        $this->jack = Jack::factory()->create(['floor_id' => $this->first->id, 'label' => 'B.15']);
    }

    /**
     * What the window of an address sends.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'address' => '10.10.10.15',
            'hostname' => 'PC-ACCUEIL',
            'mac_address' => null,
            'device_type' => null,
            'assignment' => 'static',
            'status' => 'active',
            'description' => null,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function store(array $overrides = [], ?Subnet $subnet = null): TestResponse
    {
        return $this->actingAs($this->editor)->post(route('ip-addresses.store', $subnet ?? $this->offices), $this->payload($overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function update(IpAddress $address, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->editor)->put(route('ip-addresses.update', $address), $this->payload([
            'address' => $address->address,
            'hostname' => $address->hostname,
            ...$overrides,
        ]));
    }

    /**
     * A jack on a floor of another site.
     */
    private function faraway(): Jack
    {
        $site = Site::factory()->create(['name' => 'Atelier']);
        $building = Building::factory()->create(['site_id' => $site->id, 'name' => 'Hangar']);

        return Jack::factory()->create(['floor_id' => Floor::factory()->create(['building_id' => $building->id, 'level' => 0, 'name' => 'Rez-de-chaussée'])->id, 'label' => 'H.01']);
    }

    /**
     * The old and new values of the last entry of an event.
     *
     * @return array{old: array<string, mixed>, new: array<string, mixed>}
     */
    private function lastChange(ActivityEvent $event): array
    {
        $changes = $this->lastEntry($event)->attribute_changes?->toArray() ?? [];

        return ['old' => $changes['old'] ?? [], 'new' => $changes['attributes'] ?? []];
    }

    // --- Plugging -------------------------------------------------------------

    public function test_an_address_is_created_plugged_into_a_jack_of_the_site_of_its_subnet(): void
    {
        $this->store(['jack_id' => $this->jack->id])->assertSessionHasNoErrors();

        $address = IpAddress::query()->sole();

        $this->assertSame($this->jack->id, $address->jack_id);
        $this->assertSame('Bâtiment principal › 1er étage › B.15 (Siège)', $this->lastChange(ActivityEvent::IpAddressCreated)['new']['jack']);
        $this->assertSame(['old' => [], 'new' => ['jack' => 'Bâtiment principal › 1er étage › B.15 (Siège)']], $this->lastChange(ActivityEvent::IpAddressPlugged));

        // Without a jack, nothing is told of one.
        $this->store(['address' => '10.10.10.16', 'jack_id' => null])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->countEntries(ActivityEvent::IpAddressPlugged));
    }

    public function test_the_jack_of_an_address_is_of_the_site_of_its_subnet_when_it_has_one(): void
    {
        $faraway = $this->faraway();

        $this->assertRefused(
            $this->store(['jack_id' => $faraway->id]),
            'jack_id',
            'La prise H.01 est sur le site Atelier : choisissez une prise du site du sous-réseau, Siège.',
        );
        $this->assertRefused($this->store(['jack_id' => 999999]), 'jack_id', "Cette prise n'existe plus.");
        $this->assertRefused($this->store(['jack_id' => 'B.15']), 'jack_id');

        $this->assertDatabaseCount('ip_addresses', 0);

        // A subnet without a site takes a jack of any site.
        $this->store(['address' => '192.0.2.10', 'jack_id' => $faraway->id], $this->subnet('192.0.2.0/24'))->assertSessionHasNoErrors();

        $this->assertSame($faraway->id, IpAddress::query()->sole()->jack_id);
    }

    public function test_an_address_is_plugged_unplugged_and_moved_with_events_of_their_own(): void
    {
        $address = IpAddress::factory()->create(['subnet_id' => $this->offices->id, 'address' => '10.10.10.15', 'hostname' => 'PC-ACCUEIL']);
        $other = Jack::factory()->create(['floor_id' => $this->first->id, 'label' => 'B.16']);

        // Plugged: one entry, and no update of the address.
        $this->update($address, ['jack_id' => $this->jack->id])->assertSessionHasNoErrors();

        $this->assertSame($this->jack->id, $address->refresh()->jack_id);
        $this->assertSame(1, $this->countEntries(ActivityEvent::IpAddressPlugged));
        $this->assertSame(0, $this->countEntries(ActivityEvent::IpAddressUpdated));

        // Left out, the jack stays.
        $this->actingAs($this->editor)->put(route('ip-addresses.update', $address), $this->payload(['description' => 'Accueil']))->assertSessionHasNoErrors();

        $this->assertSame($this->jack->id, $address->refresh()->jack_id);

        // Moved to another jack: unplugged, then plugged.
        $this->update($address, ['jack_id' => $other->id])->assertSessionHasNoErrors();

        $this->assertSame(['old' => ['jack' => 'Bâtiment principal › 1er étage › B.15 (Siège)'], 'new' => []], $this->lastChange(ActivityEvent::IpAddressUnplugged));
        $this->assertSame(['old' => [], 'new' => ['jack' => 'Bâtiment principal › 1er étage › B.16 (Siège)']], $this->lastChange(ActivityEvent::IpAddressPlugged));

        // Sent empty, the address is unplugged.
        $this->update($address, ['jack_id' => null])->assertSessionHasNoErrors();

        $this->assertNull($address->refresh()->jack_id);
        $this->assertSame(2, $this->countEntries(ActivityEvent::IpAddressUnplugged));
        $this->assertSame(2, $this->countEntries(ActivityEvent::IpAddressPlugged));
    }

    public function test_the_activity_log_tells_the_jack_of_an_address_and_filters_on_it(): void
    {
        $address = IpAddress::factory()->create(['subnet_id' => $this->offices->id, 'address' => '10.10.10.15']);

        $this->update($address, ['jack_id' => $this->jack->id])->assertSessionHasNoErrors();
        $this->update($address, ['jack_id' => null])->assertSessionHasNoErrors();

        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('activity-log.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.event_label', "Adresse débranchée d'une prise")
                ->where('entries.data.0.changes', [['field' => 'Prise', 'old' => 'Bâtiment principal › 1er étage › B.15 (Siège)', 'new' => null]])
                ->where('entries.data.1.event_label', 'Adresse raccordée à une prise')
                ->where('entries.data.1.subject.title', '10.10.10.15')
                ->where('events', fn ($events) => collect($events)->contains(fn (array $event): bool => $event == ['value' => 'ip_address.plugged', 'label' => 'Adresse raccordée à une prise'])
                    && collect($events)->contains(fn (array $event): bool => $event == ['value' => 'ip_address.unplugged', 'label' => "Adresse débranchée d'une prise"])));

        $this->actingAs($admin)
            ->get(route('activity-log.index', ['event' => 'ip_address.plugged']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.event_label', 'Adresse raccordée à une prise'));
    }

    // --- Pages ----------------------------------------------------------------

    public function test_the_page_of_a_subnet_and_the_page_of_a_device_show_the_jack_of_each_address_with_its_path(): void
    {
        $rack = Room::factory()->technical()->create(['floor_id' => $this->first->id, 'name' => 'Local technique']);
        $panel = PatchPanel::factory()->create(['room_id' => $rack->id, 'name' => 'PB-A', 'port_count' => 24]);
        $switch = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-etage-1', 'type' => DeviceType::Switch]);
        $port = PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $switch->id]), 15)[14];
        $vlan = Vlan::factory()->create(['site_id' => $this->office->id, 'vid' => 10]);
        $port->update(['mode' => PortMode::Access]);
        PortVlan::factory()->create(['port_id' => $port->id, 'vlan_id' => $vlan->id, 'tagged' => false]);

        $panelPort = PatchPanelPort::query()->where('patch_panel_id', $panel->id)->where('number', 15)->sole();
        $this->jack->update(['patch_panel_port_id' => $panelPort->id]);
        PatchCordFactory::between($panelPort, $port);

        $pc = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'pc-accueil']);
        IpAddress::factory()->create(['subnet_id' => $this->offices->id, 'address' => '10.10.10.15', 'jack_id' => $this->jack->id, 'device_id' => $pc->id]);
        IpAddress::factory()->create(['subnet_id' => $this->offices->id, 'address' => '10.10.10.16']);

        $expected = [
            'id' => $this->jack->id,
            'label' => 'B.15',
            'place' => 'Bâtiment principal › 1er étage',
            'path' => [
                'jack' => ['id' => $this->jack->id, 'label' => 'B.15', 'place' => 'Bâtiment principal › 1er étage'],
                'plugged' => null,
                'panel_port' => ['id' => $panelPort->id, 'name' => 'PB-A 15', 'panel_id' => $panel->id],
                'device' => ['id' => $switch->id, 'name' => 'sw-etage-1'],
                'port' => ['id' => $port->id, 'name' => '1/0/15'],
                'configuration' => 'Accès 10',
            ],
        ];

        $this->actingAs($this->reader())
            ->get(route('subnets.show', $this->offices))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('addresses.0.jack', $expected)
                ->where('addresses.1.jack', null)
                ->missing('jackChoices'));

        $this->actingAs($this->reader())
            ->get(route('devices.show', $pc))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('addresses.0.jack', $expected)
                ->missing('jackChoices'));
    }

    public function test_the_window_of_an_address_finds_the_jacks_of_the_site_by_their_label(): void
    {
        foreach (['B.2', 'B.10', 'C.1'] as $label) {
            Jack::factory()->create(['floor_id' => $this->first->id, 'label' => $label]);
        }

        $this->faraway();

        // On the page of a subnet: the jacks of its site.
        $this->assertSame(['B.2', 'B.10', 'B.15', 'C.1'], array_column($this->jackChoices(route('subnets.show', $this->offices))['items'], 'label'));
        $this->assertSame(['B.10', 'B.15', 'C.1'], array_column($this->jackChoices(route('subnets.show', ['subnet' => $this->offices, 'jack_search' => '1']))['items'], 'label'));
        $this->assertSame(
            ['id' => $this->jack->id, 'label' => 'B.15', 'place' => 'Bâtiment principal › 1er étage', 'site' => 'Siège'],
            $this->jackChoices(route('subnets.show', ['subnet' => $this->offices, 'jack_search' => '15']))['items'][0],
        );

        // On the page of a device: those of the site of the subnet chosen,
        // or of every site.
        $device = Device::factory()->create(['site_id' => $this->office->id]);

        $this->assertSame(['H.01'], array_column($this->jackChoices(route('devices.show', ['device' => $device, 'jack_site' => Site::query()->where('name', 'Atelier')->value('id')]))['items'], 'label'));
        $this->assertSame(['H.01', 'B.2', 'B.10', 'B.15', 'C.1'], array_column($this->jackChoices(route('devices.show', $device))['items'], 'label'));

        // Twenty at most: a search that finds more is to be made more
        // precise.
        foreach (range(1, 21) as $number) {
            Jack::factory()->create(['floor_id' => $this->first->id, 'label' => 'D.'.$number]);
        }

        $found = $this->jackChoices(route('subnets.show', ['subnet' => $this->offices, 'jack_search' => 'D.']));

        $this->assertCount(20, $found['items']);
        $this->assertTrue($found['more']);
        $this->assertSame('D.20', $found['items'][19]['label']);
        $this->assertFalse($this->jackChoices(route('subnets.show', ['subnet' => $this->offices, 'jack_search' => 'B.']))['more']);

        $this->actingAs($this->reader())->get(route('subnets.show', ['subnet' => $this->offices, 'jack_search' => str_repeat('a', 33)]))->assertSessionHasErrors('jack_search');
        $this->actingAs($this->reader())->get(route('devices.show', ['device' => $device, 'jack_site' => 999999]))->assertSessionHasErrors('jack_site');
    }

    /**
     * What the field "Jack" of the window of an address lists, on a page.
     *
     * @return array{items: list<array<string, mixed>>, more: bool}
     */
    private function jackChoices(string $url): array
    {
        $found = null;

        $this->actingAs($this->reader())
            ->get($url)
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$found): void {
                $page->missing('jackChoices')->reloadOnly('jackChoices', function (Assert $reload) use (&$found): void {
                    $found = $reload->toArray()['props']['jackChoices'];
                });
            });

        $this->assertIsArray($found);

        return $found;
    }
}
