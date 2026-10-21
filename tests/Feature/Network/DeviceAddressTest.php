<?php

// torsade/tests/Feature/Network/DeviceAddressTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\DeviceType;
use App\Enums\Status;
use App\Http\Controllers\Network\DeviceController;
use App\Models\Activity;
use App\Models\Building;
use App\Models\Device;
use App\Models\Floor;
use App\Models\IpAddress;
use App\Models\Room;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\User;
use App\Models\Vlan;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The addresses of a device are its interfaces: attached, created and
 * detached from its page, or chosen in the window of an address. An
 * address on the network of another site than its device is accepted, and
 * shown as a deviation.
 */
class DeviceAddressTest extends NetworkTestCase
{
    private User $editor;

    private Site $office;

    private Site $workshop;

    private Subnet $offices;

    private Subnet $machines;

    private Device $firewall;

    protected function setUp(): void
    {
        parent::setUp();

        $this->editor = $this->editor();
        $this->office = Site::factory()->create(['name' => 'Siège']);
        $this->workshop = Site::factory()->create(['name' => 'Atelier']);

        $vlan = Vlan::factory()->create(['site_id' => $this->office->id, 'vid' => 10, 'name' => 'Bureautique']);

        // A subnet of each site: the first one carried by a VLAN.
        $this->offices = Subnet::factory()->cidr('10.10.10.0/24')->create(['vlan_id' => $vlan->id]);
        $this->machines = $this->subnet('10.20.60.0/24', ['site_id' => $this->workshop->id, 'name' => 'Machines']);

        $this->firewall = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'fw-siege', 'type' => DeviceType::Firewall]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function address(Subnet $subnet, string $address, array $attributes = []): IpAddress
    {
        return IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => $address, ...$attributes]);
    }

    /**
     * What the last entry of the log says changed, before and after.
     *
     * @return array{old: array<string, mixed>, new: array<string, mixed>}
     */
    private function lastChange(ActivityEvent $event): array
    {
        $changes = $this->lastEntry($event)->attribute_changes?->toArray() ?? [];

        return ['old' => $changes['old'] ?? [], 'new' => $changes['attributes'] ?? []];
    }

    // --- The page of a device -------------------------------------------------

    public function test_the_page_of_a_device_shows_its_facts_its_place_and_its_addresses(): void
    {
        $building = Building::factory()->create(['site_id' => $this->office->id, 'name' => 'Bâtiment principal']);
        $floor = Floor::factory()->create(['building_id' => $building->id, 'level' => 0, 'name' => 'Rez-de-chaussée']);
        $room = Room::factory()->technical()->create(['floor_id' => $floor->id, 'name' => 'Local technique']);

        $this->firewall->update([
            'room_id' => $room->id, 'manufacturer' => 'Exemple Réseaux', 'model' => 'FW-200', 'serial_number' => 'SN-0042',
            'status' => Status::Planned, 'description' => 'Sortie Internet',
        ]);

        // Created out of order: the addresses come back in numeric order.
        $lan = $this->address($this->offices, '10.10.10.10', ['device_id' => $this->firewall->id, 'hostname' => 'fw-siege-lan', 'status' => Status::Planned]);
        $first = $this->address($this->offices, '10.10.10.9', ['device_id' => $this->firewall->id, 'hostname' => 'fw-siege']);
        // The address of another device, and one that belongs to none.
        $this->address($this->offices, '10.10.10.20', ['device_id' => Device::factory()->create(['site_id' => $this->office->id])->id]);
        $this->address($this->offices, '10.10.10.30', ['hostname' => 'srv-01']);

        $vlan = $this->offices->vlan;

        $this->actingAs($this->reader())
            ->get(route('devices.show', $this->firewall))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('devices/Show')
                ->where('device', [
                    'id' => $this->firewall->id,
                    'name' => 'fw-siege',
                    'type' => 'firewall',
                    'type_label' => 'Pare-feu',
                    'manufacturer' => 'Exemple Réseaux',
                    'model' => 'FW-200',
                    'serial_number' => 'SN-0042',
                    'description' => 'Sortie Internet',
                    'status' => 'planned',
                    'status_label' => 'Prévu',
                    'site' => ['id' => $this->office->id, 'name' => 'Siège'],
                    'room' => ['id' => $room->id, 'name' => 'Local technique', 'path' => 'Bâtiment principal › Rez-de-chaussée › Local technique', 'is_technical' => true],
                    'addresses_count' => 2,
                ])
                ->where('addresses', [
                    [
                        'id' => $first->id, 'address' => '10.10.10.9', 'hostname' => 'fw-siege', 'status' => 'active',
                        // Agreed with the address: "Active".
                        'status_label' => 'Active',
                        'subnet' => ['id' => $this->offices->id, 'cidr' => '10.10.10.0/24', 'site' => 'Siège'],
                        'vlan' => ['id' => $vlan?->id, 'vid' => 10, 'name' => 'Bureautique'],
                        'jack' => null,
                        'deviations' => [],
                    ],
                    [
                        'id' => $lan->id, 'address' => '10.10.10.10', 'hostname' => 'fw-siege-lan', 'status' => 'planned',
                        'status_label' => 'Prévue',
                        'subnet' => ['id' => $this->offices->id, 'cidr' => '10.10.10.0/24', 'site' => 'Siège'],
                        'vlan' => ['id' => $vlan?->id, 'vid' => 10, 'name' => 'Bureautique'],
                        'jack' => null,
                        'deviations' => [],
                    ],
                ])
                // What the window of a new address chooses among: every
                // subnet, those of the site of the device first.
                ->where('subnets', [
                    ['id' => $this->offices->id, 'cidr' => '10.10.10.0/24', 'label' => '10 — Bureautique', 'first_usable' => '10.10.10.1', 'last_usable' => '10.10.10.254', 'site_id' => $this->office->id, 'site' => 'Siège'],
                    ['id' => $this->machines->id, 'cidr' => '10.20.60.0/24', 'label' => 'Machines', 'first_usable' => '10.20.60.1', 'last_usable' => '10.20.60.254', 'site_id' => $this->workshop->id, 'site' => 'Atelier'],
                ])
                ->has('deviceTypes', count(DeviceType::cases()))
                ->has('assignments', 2)
                // The statuses of an address, agreed with it.
                ->where('statuses.0', ['value' => 'planned', 'label' => 'Prévue'])
                // Not worked out until the window that lists them asks.
                ->missing('attachable'));
    }

    public function test_a_device_without_room_nor_address_has_a_page_too(): void
    {
        $this->actingAs($this->reader())
            ->get(route('devices.show', $this->firewall))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('device.room', null)
                ->where('device.manufacturer', null)
                ->where('device.addresses_count', 0)
                ->where('addresses', []));
    }

    public function test_the_subnets_of_the_site_of_the_device_are_offered_first(): void
    {
        // Lower than every subnet of the head office, and of no site.
        $link = $this->subnet('10.0.0.0/30');
        $servers = $this->subnet('10.10.20.0/24', ['site_id' => $this->office->id]);

        $order = fn (Device $device): array => array_column(
            (array) $this->actingAs($this->reader())->get(route('devices.show', $device))->assertOk()->inertiaProps('subnets'),
            'cidr',
        );

        $this->assertSame(['10.10.10.0/24', '10.10.20.0/24', '10.0.0.0/30', '10.20.60.0/24'], $order($this->firewall));

        $press = Device::factory()->create(['site_id' => $this->workshop->id, 'name' => 'presse-01']);

        $this->assertSame(['10.20.60.0/24', '10.0.0.0/30', '10.10.10.0/24', '10.10.20.0/24'], $order($press));
        $this->assertSame([$link->id, $servers->id], [$link->id, $servers->id]);
    }

    // --- Looking for an address to attach -------------------------------------

    /**
     * What the window "Attach an address" lists for what was typed.
     *
     * @return array{items: list<array<string, mixed>>, more: bool}
     */
    private function attachable(string $search): array
    {
        $found = null;

        $this->actingAs($this->editor)
            ->get(route('devices.show', ['device' => $this->firewall, 'search' => $search]))
            ->assertOk()
            // The page does not carry the list; asked for alone, as the
            // window does, it comes.
            ->assertInertia(function (Assert $page) use (&$found): void {
                $page
                    ->missing('attachable')
                    ->reloadOnly('attachable', function (Assert $reload) use (&$found): void {
                        $found = $reload->toArray()['props']['attachable'];
                    });
            });

        $this->assertIsArray($found);

        return $found;
    }

    public function test_the_addresses_without_a_device_are_looked_for_by_address_or_by_host_name_on_every_site(): void
    {
        $server = $this->address($this->offices, '10.10.10.30', ['hostname' => 'SRV-Fichiers-01']);
        $this->address($this->offices, '10.10.10.1', ['hostname' => 'fw-siege']);
        $this->address($this->offices, '10.10.10.100', ['hostname' => 'pc-accueil']);
        $press = $this->address($this->machines, '10.20.60.10', ['hostname' => 'presse-01']);
        // Without a site, and without a host name.
        $link = $this->subnet('172.16.0.0/30');
        $this->address($link, '172.16.0.1');
        // Attached already: never offered.
        $this->address($this->offices, '10.10.10.31', ['hostname' => 'srv-fichiers-02', 'device_id' => Device::factory()->create(['site_id' => $this->office->id])->id]);

        $addresses = fn (string $search): array => array_column($this->attachable($search)['items'], 'address');

        // Nothing typed: every address without a device, in numeric order.
        $this->assertSame(['10.10.10.1', '10.10.10.30', '10.10.10.100', '10.20.60.10', '172.16.0.1'], $addresses(''));

        // The beginning of an address: "10.10.10.1" is 10.10.10.1 and
        // 10.10.10.100, not 10.10.10.30.
        $this->assertSame(['10.10.10.1', '10.10.10.100'], $addresses('10.10.10.1'));
        $this->assertSame(['10.10.10.1', '10.10.10.30', '10.10.10.100'], $addresses('10.10.'));
        $this->assertSame(['10.20.60.10'], $addresses('10.2'));
        $this->assertSame(['172.16.0.1'], $addresses('172'));
        $this->assertSame(['10.10.10.30'], $addresses('10.10.10.30'));

        // A part of a host name, whatever the case.
        $this->assertSame(['10.10.10.30'], $addresses('fichiers'));
        $this->assertSame(['10.10.10.30'], $addresses('  srv-FICH '));
        $this->assertSame(['10.20.60.10'], $addresses('presse'));
        $this->assertSame([], $addresses('srv-fichiers-02'));
        $this->assertSame([], $addresses('nothing-of-the-kind'));
        // The characters a search could take for jokers are looked for as
        // they are.
        $this->assertSame([], $addresses('%'));
        $this->assertSame([], $addresses('srv_fichiers'));

        // Each line says where the address is.
        $this->assertSame(
            [
                ['id' => $server->id, 'address' => '10.10.10.30', 'hostname' => 'SRV-Fichiers-01', 'subnet' => '10.10.10.0/24', 'site' => 'Siège'],
            ],
            $this->attachable('fichiers')['items'],
        );
        $this->assertSame(
            ['id' => $press->id, 'address' => '10.20.60.10', 'hostname' => 'presse-01', 'subnet' => '10.20.60.0/24', 'site' => 'Atelier'],
            $this->attachable('presse')['items'][0],
        );
        $this->assertNull($this->attachable('172.16')['items'][0]['site']);
        $this->assertFalse($this->attachable('')['more']);
    }

    public function test_the_window_lists_the_first_addresses_only_and_says_there_are_more(): void
    {
        $listed = DeviceController::ATTACHABLE_LISTED;

        for ($host = 1; $host <= $listed + 5; $host++) {
            $this->address($this->offices, '10.10.10.'.$host, ['hostname' => 'pc-'.$host]);
        }

        $found = $this->attachable('pc-');

        $this->assertCount($listed, $found['items']);
        $this->assertTrue($found['more']);
        $this->assertSame('10.10.10.1', $found['items'][0]['address']);
        $this->assertSame('10.10.10.'.$listed, $found['items'][$listed - 1]['address']);

        // A search that finds no more than is listed says nothing.
        $this->assertFalse($this->attachable('10.10.10.2')['more']);
        // 10.10.10.2, and 10.10.10.20 to 10.10.10.25.
        $this->assertCount(7, $this->attachable('10.10.10.2')['items']);

        $this->actingAs($this->editor)
            ->get(route('devices.show', ['device' => $this->firewall, 'search' => str_repeat('a', 254)]))
            ->assertSessionHasErrors('search');
    }

    // --- Attaching an address -------------------------------------------------

    public function test_an_editor_attaches_a_documented_address_to_a_device(): void
    {
        $address = $this->address($this->offices, '10.10.10.1', ['hostname' => 'FW-Siege-LAN', 'device_type' => 'router', 'description' => 'Passerelle']);

        $this->actingAs($this->editor)
            ->post(route('devices.ip-addresses.store', $this->firewall), ['ip_address_id' => $address->id])
            ->assertRedirect(route('devices.show', $this->firewall))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Adresse 10.10.10.1 rattachée.');

        $address->refresh();

        $this->assertSame($this->firewall->id, $address->device_id);
        // The address keeps what describes the interface.
        $this->assertSame(['FW-Siege-LAN', DeviceType::Router, 'Passerelle'], [$address->hostname, $address->device_type, $address->description]);

        // Logged as an event of its own, which names the device and its
        // site; the address itself was not updated.
        $entry = $this->lastEntry(ActivityEvent::IpAddressAttached);

        $this->assertTrue($this->editor->is($entry->causer));
        $this->assertTrue($address->is($entry->subject));
        $this->assertSame('10.10.10.1', $entry->getProperty('label'));
        $this->assertSame(['old' => [], 'new' => ['device' => 'fw-siege (Siège)']], $this->lastChange(ActivityEvent::IpAddressAttached));
        $this->assertSame(0, $this->countEntries(ActivityEvent::IpAddressUpdated));
        $this->assertSame(0, $this->countEntries(ActivityEvent::IpAddressDetached));
    }

    public function test_an_address_that_already_belongs_to_a_device_is_not_attached_to_another(): void
    {
        $switch = Device::factory()->create(['site_id' => $this->workshop->id, 'name' => 'sw-atelier']);
        $taken = $this->address($this->offices, '10.10.10.2', ['device_id' => $switch->id]);
        $own = $this->address($this->offices, '10.10.10.1', ['device_id' => $this->firewall->id]);

        // The refusal names the device that has it.
        $this->assertRefused(
            $this->actingAs($this->editor)->post(route('devices.ip-addresses.store', $this->firewall), ['ip_address_id' => $taken->id]),
            'ip_address_id',
            "L'adresse 10.10.10.2 est déjà rattachée à l'équipement sw-atelier (Atelier) : détachez-la d'abord.",
        );

        $this->assertRefused(
            $this->actingAs($this->editor)->post(route('devices.ip-addresses.store', $this->firewall), ['ip_address_id' => $own->id]),
            'ip_address_id',
            "L'adresse 10.10.10.1 est déjà rattachée à cet équipement.",
        );

        $this->assertRefused($this->actingAs($this->editor)->post(route('devices.ip-addresses.store', $this->firewall), ['ip_address_id' => 999999]), 'ip_address_id');
        $this->assertRefused($this->actingAs($this->editor)->post(route('devices.ip-addresses.store', $this->firewall), []), 'ip_address_id');

        $this->assertSame($switch->id, $taken->refresh()->device_id);
        $this->assertSame(0, $this->countEntries(ActivityEvent::IpAddressAttached));
    }

    // --- The deviation of an address on another site --------------------------

    public function test_an_address_of_another_site_is_attached_and_shown_as_a_deviation(): void
    {
        $foreign = $this->address($this->machines, '10.20.60.2', ['hostname' => 'fw-siege-atelier']);
        $own = $this->address($this->offices, '10.10.10.1', ['hostname' => 'fw-siege']);

        // Accepted like any other: a deviation refuses nothing.
        $this->actingAs($this->editor)
            ->post(route('devices.ip-addresses.store', $this->firewall), ['ip_address_id' => $foreign->id])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->editor)
            ->post(route('devices.ip-addresses.store', $this->firewall), ['ip_address_id' => $own->id])
            ->assertSessionHasNoErrors();

        $expected = [['kind' => 'address_on_device_of_another_site', 'reason' => 'Sous-réseau du site Atelier, équipement du site Siège']];

        // On the page of the device.
        $this->actingAs($this->reader())
            ->get(route('devices.show', $this->firewall))
            ->assertInertia(fn (Assert $page) => $page
                ->has('addresses', 2)
                ->where('addresses.0.address', '10.10.10.1')
                ->where('addresses.0.deviations', [])
                ->where('addresses.1.address', '10.20.60.2')
                ->where('addresses.1.subnet.site', 'Atelier')
                ->where('addresses.1.deviations', $expected));

        // In the table of the addresses of the subnet.
        $this->actingAs($this->reader())
            ->get(route('subnets.show', $this->machines))
            ->assertInertia(fn (Assert $page) => $page
                ->has('addresses', 1)
                ->where('addresses.0.device', ['id' => $this->firewall->id, 'name' => 'fw-siege', 'site' => 'Siège'])
                ->where('addresses.0.deviations', $expected));

        // Where the address is on the site of its device, nothing differs.
        $this->actingAs($this->reader())
            ->get(route('subnets.show', $this->offices))
            ->assertInertia(fn (Assert $page) => $page
                ->where('addresses.0.device.name', 'fw-siege')
                ->where('addresses.0.deviations', []));
    }

    public function test_a_subnet_without_a_site_gives_no_deviation(): void
    {
        // A link between sites belongs to none of them.
        $link = $this->subnet('172.16.0.0/30');
        $address = $this->address($link, '172.16.0.1', ['device_id' => $this->firewall->id]);

        $this->actingAs($this->reader())
            ->get(route('devices.show', $this->firewall))
            ->assertInertia(fn (Assert $page) => $page
                ->where('addresses.0.id', $address->id)
                ->where('addresses.0.subnet.site', null)
                ->where('addresses.0.vlan', null)
                ->where('addresses.0.deviations', []));

        $this->actingAs($this->reader())
            ->get(route('subnets.show', $link))
            ->assertInertia(fn (Assert $page) => $page
                ->where('addresses.0.device.name', 'fw-siege')
                ->where('addresses.0.deviations', []));
    }

    public function test_the_deviation_follows_the_site_of_the_device_and_the_site_of_the_subnet(): void
    {
        $address = $this->address($this->machines, '10.20.60.2', ['device_id' => $this->firewall->id]);

        $deviations = fn (): array => (array) $this->actingAs($this->reader())
            ->get(route('subnets.show', $this->machines))
            ->inertiaProps('addresses.0.deviations');

        $this->assertCount(1, $deviations());

        // The device moves to the site of the subnet: nothing differs.
        $this->firewall->update(['site_id' => $this->workshop->id]);

        $this->assertSame([], $deviations());

        // Detached, the address differs from nothing.
        $this->firewall->update(['site_id' => $this->office->id]);
        $address->update(['device_id' => null]);

        $this->assertSame([], $deviations());
    }

    // --- Detaching ------------------------------------------------------------

    public function test_an_editor_detaches_an_address_which_stays_documented(): void
    {
        $address = $this->address($this->offices, '10.10.10.1', ['hostname' => 'fw-siege', 'device_type' => 'firewall', 'device_id' => $this->firewall->id]);
        $other = $this->address($this->offices, '10.10.10.2', ['device_id' => $this->firewall->id]);

        $this->actingAs($this->editor)
            ->delete(route('devices.ip-addresses.destroy', [$this->firewall, $address]))
            ->assertRedirect(route('devices.show', $this->firewall))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Adresse 10.10.10.1 détachée.');

        $address->refresh();

        $this->assertNull($address->device_id);
        $this->assertSame(['fw-siege', DeviceType::Firewall], [$address->hostname, $address->device_type]);
        // The other address of the device is still attached.
        $this->assertSame($this->firewall->id, $other->refresh()->device_id);

        $this->assertSame(['old' => ['device' => 'fw-siege (Siège)'], 'new' => []], $this->lastChange(ActivityEvent::IpAddressDetached));
        $this->assertSame(1, $this->countEntries(ActivityEvent::IpAddressDetached));
        $this->assertSame(0, $this->countEntries(ActivityEvent::IpAddressUpdated));
    }

    public function test_an_address_is_only_detached_from_the_device_it_belongs_to(): void
    {
        $switch = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-coeur']);
        $theirs = $this->address($this->offices, '10.10.10.2', ['device_id' => $switch->id]);
        $free = $this->address($this->offices, '10.10.10.3');

        $this->actingAs($this->editor)->delete(route('devices.ip-addresses.destroy', [$this->firewall, $theirs]))->assertNotFound();
        $this->actingAs($this->editor)->delete(route('devices.ip-addresses.destroy', [$this->firewall, $free]))->assertNotFound();

        $this->assertSame($switch->id, $theirs->refresh()->device_id);
        $this->assertSame(0, $this->countEntries(ActivityEvent::IpAddressDetached));
    }

    public function test_every_address_of_a_device_is_detached_at_once_each_one_logged(): void
    {
        $this->address($this->offices, '10.10.10.1', ['device_id' => $this->firewall->id]);
        $this->address($this->offices, '10.10.10.2', ['device_id' => $this->firewall->id]);
        $this->address($this->machines, '10.20.60.2', ['device_id' => $this->firewall->id]);

        $switch = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-coeur']);
        $theirs = $this->address($this->offices, '10.10.10.3', ['device_id' => $switch->id]);

        $this->actingAs($this->editor)
            ->delete(route('devices.ip-addresses.destroy-all', $this->firewall))
            ->assertRedirect(route('devices.show', $this->firewall))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '3 adresses détachées.');

        $this->assertSame(0, $this->firewall->ipAddresses()->count());
        $this->assertSame(4, IpAddress::query()->count());
        // The addresses of another device are not touched.
        $this->assertSame($switch->id, $theirs->refresh()->device_id);

        // One entry per address, each with the device it left.
        $entries = Activity::query()->where('event', ActivityEvent::IpAddressDetached->value)->orderBy('id')->get();

        $this->assertSame(['10.10.10.1', '10.10.10.2', '10.20.60.2'], $entries->map(fn (Activity $entry): mixed => $entry->getProperty('label'))->all());

        foreach ($entries as $entry) {
            $changes = $entry->attribute_changes?->toArray() ?? [];

            $this->assertSame(['device' => 'fw-siege (Siège)'], $changes['old']);
            $this->assertSame([], $changes['attributes']);
        }

        // With one address, and with none.
        $this->address($this->offices, '10.10.10.4', ['device_id' => $this->firewall->id]);

        $this->actingAs($this->editor)
            ->delete(route('devices.ip-addresses.destroy-all', $this->firewall))
            ->assertInertiaFlash('toast.message', '1 adresse détachée.');

        $this->actingAs($this->editor)
            ->delete(route('devices.ip-addresses.destroy-all', $this->firewall))
            ->assertInertiaFlash('toast.message', '0 adresse détachée.');

        $this->assertSame(4, $this->countEntries(ActivityEvent::IpAddressDetached));
        $this->assertSame(0, $this->countEntries(ActivityEvent::IpAddressUpdated));
    }

    // --- A new address, from the page of the device ---------------------------

    public function test_an_address_created_from_the_page_of_a_device_is_one_of_its_interfaces(): void
    {
        $this->actingAs($this->editor)
            ->post(route('ip-addresses.store', $this->offices), [
                'address' => '10.10.10.1', 'hostname' => 'fw-siege-lan', 'device_type' => 'firewall',
                'assignment' => 'static', 'status' => 'active', 'device_id' => $this->firewall->id, 'from_device' => true,
            ])
            // Back to the device, not to the subnet.
            ->assertRedirect(route('devices.show', $this->firewall))
            ->assertSessionHasNoErrors();

        $address = IpAddress::query()->sole();

        $this->assertSame([$this->offices->id, $this->firewall->id, 'fw-siege-lan'], [$address->subnet_id, $address->device_id, $address->hostname]);
        // Created, and attached: both are in the log.
        $this->assertSame('fw-siege (Siège)', $this->lastChange(ActivityEvent::IpAddressCreated)['new']['device']);
        $this->assertSame(['old' => [], 'new' => ['device' => 'fw-siege (Siège)']], $this->lastChange(ActivityEvent::IpAddressAttached));

        // In a subnet of another site: accepted, and shown as a deviation.
        $this->actingAs($this->editor)
            ->post(route('ip-addresses.store', $this->machines), [
                'address' => '10.20.60.2', 'assignment' => 'static', 'status' => 'active', 'device_id' => $this->firewall->id, 'from_device' => true,
            ])
            ->assertRedirect(route('devices.show', $this->firewall))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->reader())
            ->get(route('devices.show', $this->firewall))
            ->assertInertia(fn (Assert $page) => $page
                ->where('device.addresses_count', 2)
                ->where('addresses.1.deviations.0.kind', 'address_on_device_of_another_site'));

        // The rules of the addressing plan are the same from here: the
        // refusal comes back on its field.
        $this->assertRefused(
            $this->actingAs($this->editor)->post(route('ip-addresses.store', $this->offices), [
                'address' => '10.10.10.1', 'assignment' => 'static', 'status' => 'active', 'device_id' => $this->firewall->id, 'from_device' => true,
            ]),
            'address',
            'Cette adresse est déjà documentée.',
        );
    }

    // --- The device in the window of an address -------------------------------

    public function test_the_window_of_an_address_gives_it_a_device_changes_it_and_takes_it_away(): void
    {
        $switch = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-coeur']);

        $this->actingAs($this->editor)
            ->post(route('ip-addresses.store', $this->offices), [
                'address' => '10.10.10.1', 'assignment' => 'static', 'status' => 'active', 'device_id' => $this->firewall->id,
            ])
            // The window of the subnet comes back to the subnet.
            ->assertRedirect(route('subnets.show', $this->offices))
            ->assertSessionHasNoErrors();

        $address = IpAddress::query()->sole();

        $this->assertSame($this->firewall->id, $address->device_id);
        $this->assertSame(1, $this->countEntries(ActivityEvent::IpAddressAttached));

        $update = fn (array $data) => $this->actingAs($this->editor)->put(route('ip-addresses.update', $address), [
            'address' => '10.10.10.1', 'assignment' => 'static', 'status' => 'active', ...$data,
        ]);

        $update(['device_id' => $switch->id])->assertRedirect(route('subnets.show', $this->offices))->assertSessionHasNoErrors();

        // From one device to another: detached from the first, attached
        // to the second, and nothing else changed.
        $this->assertSame($switch->id, $address->refresh()->device_id);
        $this->assertSame(['old' => ['device' => 'fw-siege (Siège)'], 'new' => []], $this->lastChange(ActivityEvent::IpAddressDetached));
        $this->assertSame(['old' => [], 'new' => ['device' => 'sw-coeur (Siège)']], $this->lastChange(ActivityEvent::IpAddressAttached));
        $this->assertSame(0, $this->countEntries(ActivityEvent::IpAddressUpdated));

        // A request that says nothing of the device leaves it.
        $update(['hostname' => 'sw-coeur'])->assertSessionHasNoErrors();

        $this->assertSame($switch->id, $address->refresh()->device_id);
        $this->assertSame(['old' => ['hostname' => null], 'new' => ['hostname' => 'sw-coeur']], $this->lastChange(ActivityEvent::IpAddressUpdated));
        $this->assertSame(2, $this->countEntries(ActivityEvent::IpAddressAttached));

        // Sent empty, the device is taken away; another value changed with
        // it is an update of the address, told apart.
        $update(['hostname' => 'sw-coeur-01', 'device_id' => null])->assertSessionHasNoErrors();

        $this->assertNull($address->refresh()->device_id);
        $this->assertSame(['old' => ['device' => 'sw-coeur (Siège)'], 'new' => []], $this->lastChange(ActivityEvent::IpAddressDetached));
        $this->assertSame(['old' => ['hostname' => 'sw-coeur'], 'new' => ['hostname' => 'sw-coeur-01']], $this->lastChange(ActivityEvent::IpAddressUpdated));
        $this->assertSame([2, 2, 2], [
            $this->countEntries(ActivityEvent::IpAddressAttached),
            $this->countEntries(ActivityEvent::IpAddressDetached),
            $this->countEntries(ActivityEvent::IpAddressUpdated),
        ]);

        // A device that does not exist is refused.
        $this->assertRefused($update(['device_id' => 999999]), 'device_id');
        $this->assertRefused(
            $this->actingAs($this->editor)->post(route('ip-addresses.store', $this->offices), [
                'address' => '10.10.10.2', 'assignment' => 'static', 'status' => 'active', 'device_id' => 'fw-siege',
            ]),
            'device_id',
        );

        $this->assertDatabaseCount('ip_addresses', 1);
    }

    public function test_the_quick_entry_and_an_address_without_device_leave_the_device_empty(): void
    {
        $this->actingAs($this->editor)
            ->post(route('ip-addresses.bulk', $this->offices), ['addresses' => [['address' => '10.10.10.1'], ['address' => '10.10.10.2', 'hostname' => 'srv-01']]])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->editor)
            ->post(route('ip-addresses.store', $this->offices), ['address' => '10.10.10.3', 'assignment' => 'static', 'status' => 'active'])
            ->assertSessionHasNoErrors();

        $this->assertSame(3, IpAddress::query()->whereNull('device_id')->count());
    }

    public function test_the_page_of_a_subnet_shows_the_device_of_each_address_and_offers_every_device(): void
    {
        $switch = Device::factory()->create(['site_id' => $this->workshop->id, 'name' => 'sw-atelier']);

        $this->address($this->offices, '10.10.10.1', ['device_id' => $this->firewall->id]);
        $this->address($this->offices, '10.10.10.2');

        $this->actingAs($this->reader())
            ->get(route('subnets.show', $this->offices))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('addresses.0.device', ['id' => $this->firewall->id, 'name' => 'fw-siege', 'site' => 'Siège'])
                ->where('addresses.0.deviations', [])
                ->where('addresses.1.device', null)
                ->where('addresses.1.deviations', [])
                // Every device, by name, with its site: the window shows
                // those of the site of the subnet first.
                ->where('devices', [
                    ['id' => $this->firewall->id, 'name' => 'fw-siege', 'site_id' => $this->office->id, 'site' => 'Siège'],
                    ['id' => $switch->id, 'name' => 'sw-atelier', 'site_id' => $this->workshop->id, 'site' => 'Atelier'],
                ])
                ->where('subnet.site', ['id' => $this->office->id, 'name' => 'Siège']));
    }

    // --- Rights ---------------------------------------------------------------

    public function test_a_reader_cannot_attach_nor_detach_an_address(): void
    {
        $reader = $this->reader();
        $free = $this->address($this->offices, '10.10.10.1');
        $attached = $this->address($this->offices, '10.10.10.2', ['device_id' => $this->firewall->id]);

        $this->actingAs($reader)->post(route('devices.ip-addresses.store', $this->firewall), ['ip_address_id' => $free->id])->assertForbidden();
        $this->actingAs($reader)->delete(route('devices.ip-addresses.destroy', [$this->firewall, $attached]))->assertForbidden();
        $this->actingAs($reader)->delete(route('devices.ip-addresses.destroy-all', $this->firewall))->assertForbidden();
        $this->actingAs($reader)->put(route('ip-addresses.update', $free), [
            'address' => '10.10.10.1', 'assignment' => 'static', 'status' => 'active', 'device_id' => $this->firewall->id,
        ])->assertForbidden();

        $this->assertNull($free->refresh()->device_id);
        $this->assertSame($this->firewall->id, $attached->refresh()->device_id);
        $this->assertSame(0, $this->countEntries(ActivityEvent::IpAddressAttached));
        $this->assertSame(0, $this->countEntries(ActivityEvent::IpAddressDetached));

        // A guest is sent to the login page.
        auth()->logout();

        $this->post(route('devices.ip-addresses.store', $this->firewall), ['ip_address_id' => $free->id])->assertRedirect(route('login'));
    }

    public function test_the_log_page_reads_an_attachment_and_a_detachment_in_french_and_filters_on_them(): void
    {
        $address = $this->address($this->offices, '10.10.10.1');
        $other = $this->address($this->offices, '10.10.10.2');
        $admin = $this->admin();

        $this->actingAs($this->editor)->post(route('devices.ip-addresses.store', $this->firewall), ['ip_address_id' => $address->id]);
        $this->actingAs($this->editor)->post(route('devices.ip-addresses.store', $this->firewall), ['ip_address_id' => $other->id]);
        $this->actingAs($this->editor)->delete(route('devices.ip-addresses.destroy', [$this->firewall, $address]));

        $this->actingAs($admin)
            ->get(route('activity-log.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.event_label', 'Adresse détachée')
                ->where('entries.data.0.subject.title', '10.10.10.1')
                // The device, with its site.
                ->where('entries.data.0.changes', [['field' => 'Équipement', 'old' => 'fw-siege (Siège)', 'new' => null]])
                ->where('entries.data.1.event_label', 'Adresse rattachée')
                ->where('entries.data.1.subject.title', '10.10.10.2')
                ->where('entries.data.1.changes', [['field' => 'Équipement', 'old' => null, 'new' => 'fw-siege (Siège)']])
                // Both are offered among the filters of the log.
                ->where('events', fn ($events) => collect($events)->contains(fn (array $event): bool => $event == ['value' => 'ip_address.attached', 'label' => 'Adresse rattachée'])
                    && collect($events)->contains(fn (array $event): bool => $event == ['value' => 'ip_address.detached', 'label' => 'Adresse détachée'])));

        $this->actingAs($admin)
            ->get(route('activity-log.index', ['event' => 'ip_address.attached']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 2)
                ->where('entries.data.0.subject.title', '10.10.10.2')
                ->where('entries.data.1.subject.title', '10.10.10.1'));

        $this->actingAs($admin)
            ->get(route('activity-log.index', ['event' => 'ip_address.detached']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.event_label', 'Adresse détachée'));
    }

    public function test_an_entry_written_before_the_events_existed_is_still_read(): void
    {
        $address = $this->address($this->offices, '10.10.10.1', ['device_id' => $this->firewall->id]);

        // What the former version wrote for an attachment: an update of
        // the address, with its device before and after.
        activity()
            ->event(ActivityEvent::IpAddressUpdated->value)
            ->performedOn($address)
            ->withProperties(['label' => '10.10.10.1', 'source' => 'web'])
            ->tap(function (Activity $activity): void {
                $activity->attribute_changes = collect(['old' => ['device' => null], 'attributes' => ['device' => 'fw-siege (Siège)']]);
            })
            ->log(ActivityEvent::IpAddressUpdated->value);

        $this->actingAs($this->admin())
            ->get(route('activity-log.index', ['event' => 'ip_address.updated']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.event_label', 'Adresse IP modifiée')
                ->where('entries.data.0.changes', [['field' => 'Équipement', 'old' => '—', 'new' => 'fw-siege (Siège)']]));
    }
}
