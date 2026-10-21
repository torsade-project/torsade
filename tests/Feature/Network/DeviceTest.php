<?php

// torsade/tests/Feature/Network/DeviceTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\DeviceType;
use App\Enums\Status;
use App\Models\Building;
use App\Models\Device;
use App\Models\Floor;
use App\Models\IpAddress;
use App\Models\Room;
use App\Models\Site;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Devices: the machines of a site, kept in one of its rooms or not.
 */
class DeviceTest extends NetworkTestCase
{
    private User $editor;

    private Site $office;

    private Site $workshop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->editor = $this->editor();
        $this->office = Site::factory()->create(['name' => 'Siège']);
        $this->workshop = Site::factory()->create(['name' => 'Atelier']);
    }

    /**
     * A room of a site, in a building and on a floor named after the site.
     */
    private function room(Site $site, string $name = 'Local technique', bool $technical = true): Room
    {
        $building = Building::query()->firstOrCreate(['site_id' => $site->id, 'name' => 'Bâtiment principal']);
        $floor = Floor::query()->firstOrCreate(['building_id' => $building->id, 'level' => 0], ['name' => 'Rez-de-chaussée']);

        return Room::factory()->create(['floor_id' => $floor->id, 'name' => $name, 'is_technical' => $technical]);
    }

    /**
     * What the form of a device sends.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'site_id' => $this->office->id,
            'room_id' => null,
            'name' => 'fw-siege',
            'type' => 'firewall',
            'manufacturer' => '',
            'model' => '',
            'serial_number' => '',
            'status' => 'active',
            'description' => '',
            ...$overrides,
        ];
    }

    // --- Creation and update --------------------------------------------------

    public function test_an_editor_creates_a_device_kept_in_a_room_of_its_site(): void
    {
        $room = $this->room($this->office);

        $response = $this->actingAs($this->editor)->post(route('devices.store'), $this->payload([
            'room_id' => $room->id,
            'name' => 'Pare-feu du siège',
            'manufacturer' => 'Exemple Réseaux',
            'model' => 'FW-200',
            'serial_number' => 'SN-0042',
            'status' => 'planned',
            'description' => 'Sortie Internet',
        ]));

        $device = Device::query()->sole();

        $response->assertRedirect(route('devices.show', $device))->assertSessionHasNoErrors();

        $this->assertSame($this->office->id, $device->site_id);
        $this->assertSame($room->id, $device->room_id);
        // A free text, unlike the host name of an address.
        $this->assertSame('Pare-feu du siège', $device->name);
        $this->assertSame(DeviceType::Firewall, $device->type);
        $this->assertSame(['Exemple Réseaux', 'FW-200', 'SN-0042'], [$device->manufacturer, $device->model, $device->serial_number]);
        $this->assertSame(Status::Planned, $device->status);
        $this->assertSame('Sortie Internet', $device->description);
    }

    public function test_only_the_site_the_name_the_type_and_the_status_are_required(): void
    {
        $this->actingAs($this->editor)
            ->post(route('devices.store'), ['site_id' => $this->office->id, 'name' => 'sw-01', 'type' => 'switch', 'status' => 'active'])
            ->assertSessionHasNoErrors();

        $device = Device::query()->sole();

        // A small site often has no plan of its rooms.
        $this->assertNull($device->room_id);
        $this->assertSame([null, null, null, null], [$device->manufacturer, $device->model, $device->serial_number, $device->description]);

        $response = $this->actingAs($this->editor)->post(route('devices.store'), []);

        $response->assertSessionHasErrors(['site_id', 'name', 'type', 'status']);
        $this->assertDatabaseCount('devices', 1);
    }

    public function test_the_shape_of_a_device_is_checked(): void
    {
        $post = fn (array $overrides) => $this->actingAs($this->editor)->post(route('devices.store'), $this->payload($overrides));

        $this->assertRefused($post(['site_id' => 999999]), 'site_id');
        $this->assertRefused($post(['room_id' => 999999]), 'room_id');
        $this->assertRefused($post(['name' => str_repeat('a', 101)]), 'name');
        $this->assertRefused($post(['type' => 'toaster']), 'type');
        $this->assertRefused($post(['status' => 'broken']), 'status');
        $this->assertRefused($post(['manufacturer' => str_repeat('a', 101)]), 'manufacturer');
        $this->assertRefused($post(['model' => str_repeat('a', 101)]), 'model');
        $this->assertRefused($post(['serial_number' => str_repeat('a', 101)]), 'serial_number');
        $this->assertRefused($post(['description' => str_repeat('a', 1001)]), 'description');

        $this->assertDatabaseCount('devices', 0);

        $post(['name' => str_repeat('a', 100), 'description' => str_repeat('b', 1000)])->assertSessionHasNoErrors();
    }

    public function test_the_name_is_unique_within_the_site_whatever_its_case(): void
    {
        Device::factory()->create(['site_id' => $this->office->id, 'name' => 'SW-Accueil']);

        // The refusal names the device as it is written.
        $this->assertRefused(
            $this->actingAs($this->editor)->post(route('devices.store'), $this->payload(['name' => 'sw-accueil'])),
            'name',
            'Le site Siège a déjà un équipement nommé SW-Accueil.',
        );

        $this->assertRefused($this->actingAs($this->editor)->post(route('devices.store'), $this->payload(['name' => 'SW-ACCUEIL'])), 'name');

        // The same name on another site is another device.
        $this->actingAs($this->editor)
            ->post(route('devices.store'), $this->payload(['site_id' => $this->workshop->id, 'name' => 'sw-accueil']))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['Atelier' => 'sw-accueil', 'Siège' => 'SW-Accueil'],
            Device::query()->with('site')->get()->mapWithKeys(fn (Device $device): array => [$device->site->name => $device->name])->sortKeys()->all(),
        );
    }

    public function test_a_device_keeps_its_own_name_and_cannot_take_the_name_of_another(): void
    {
        $firewall = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'fw-siege', 'type' => DeviceType::Firewall]);
        Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-coeur']);

        // Its own name, written otherwise.
        $this->actingAs($this->editor)
            ->put(route('devices.update', $firewall), $this->payload(['name' => 'FW-Siege']))
            ->assertRedirect(route('devices.show', $firewall))
            ->assertSessionHasNoErrors();

        $this->assertSame('FW-Siege', $firewall->refresh()->name);

        $this->assertRefused(
            $this->actingAs($this->editor)->put(route('devices.update', $firewall), $this->payload(['name' => 'SW-COEUR'])),
            'name',
            'Le site Siège a déjà un équipement nommé sw-coeur.',
        );

        // Moved to a site that already has a device of its name.
        Device::factory()->create(['site_id' => $this->workshop->id, 'name' => 'fw-siege']);

        $this->assertRefused(
            $this->actingAs($this->editor)->put(route('devices.update', $firewall), $this->payload(['site_id' => $this->workshop->id, 'name' => 'FW-Siege'])),
            'name',
            'Le site Atelier a déjà un équipement nommé fw-siege.',
        );

        $this->assertSame($this->office->id, $firewall->refresh()->site_id);
    }

    public function test_the_room_of_a_device_belongs_to_its_site(): void
    {
        $elsewhere = $this->room($this->workshop, 'Armoire atelier', false);

        $this->assertRefused(
            $this->actingAs($this->editor)->post(route('devices.store'), $this->payload(['room_id' => $elsewhere->id])),
            'room_id',
            "Le local Bâtiment principal › Rez-de-chaussée › Armoire atelier appartient au site Atelier : choisissez un local du site de l'équipement.",
        );

        $this->assertDatabaseCount('devices', 0);

        // A switch outside a technical room is accepted: an office switch,
        // a cabinet in a workshop.
        $this->actingAs($this->editor)
            ->post(route('devices.store'), $this->payload(['site_id' => $this->workshop->id, 'room_id' => $elsewhere->id, 'name' => 'sw-atelier', 'type' => 'switch']))
            ->assertSessionHasNoErrors();

        $device = Device::query()->sole();

        $this->assertSame($elsewhere->id, $device->room_id);

        // Nor can it be moved to a room of another site while it stays on
        // its own.
        $this->assertRefused(
            $this->actingAs($this->editor)->put(route('devices.update', $device), $this->payload([
                'site_id' => $this->workshop->id, 'room_id' => $this->room($this->office)->id, 'name' => 'sw-atelier', 'type' => 'switch',
            ])),
            'room_id',
        );

        $this->assertSame($elsewhere->id, $device->refresh()->room_id);
    }

    public function test_a_device_moved_to_another_site_leaves_its_room(): void
    {
        $room = $this->room($this->office);
        $device = Device::factory()->create(['site_id' => $this->office->id, 'room_id' => $room->id, 'name' => 'srv-01']);

        // The form still sends the room the device was in.
        $this->actingAs($this->editor)
            ->put(route('devices.update', $device), $this->payload(['site_id' => $this->workshop->id, 'room_id' => $room->id, 'name' => 'srv-01', 'type' => 'server']))
            ->assertSessionHasNoErrors();

        $device->refresh();

        $this->assertSame($this->workshop->id, $device->site_id);
        $this->assertNull($device->room_id);

        // Both changes are in the log.
        $changes = $this->lastEntry(ActivityEvent::DeviceUpdated)->attribute_changes?->toArray() ?? [];

        $this->assertSame(['site' => 'Siège', 'room' => 'Bâtiment principal › Rez-de-chaussée › Local technique'], $changes['old']);
        $this->assertSame(['site' => 'Atelier', 'room' => null], $changes['attributes']);
    }

    public function test_a_device_moved_to_another_site_takes_the_room_of_that_site_given_with_the_move(): void
    {
        $device = Device::factory()->create(['site_id' => $this->office->id, 'room_id' => $this->room($this->office)->id, 'name' => 'srv-01']);
        $there = $this->room($this->workshop, 'Baie atelier');

        $this->actingAs($this->editor)
            ->put(route('devices.update', $device), $this->payload(['site_id' => $this->workshop->id, 'room_id' => $there->id, 'name' => 'srv-01', 'type' => 'server']))
            ->assertSessionHasNoErrors();

        $this->assertSame([$this->workshop->id, $there->id], [$device->refresh()->site_id, $device->room_id]);

        // A room of a third site is refused, and nothing moves.
        $third = Site::factory()->create(['name' => 'Dépôt']);

        $this->assertRefused(
            $this->actingAs($this->editor)->put(route('devices.update', $device), $this->payload([
                'site_id' => $third->id, 'room_id' => $this->room($this->office, 'Réserve', false)->id, 'name' => 'srv-01', 'type' => 'server',
            ])),
            'room_id',
        );

        $this->assertSame([$this->workshop->id, $there->id], [$device->refresh()->site_id, $device->room_id]);
    }

    // --- Deletion -------------------------------------------------------------

    public function test_a_device_without_address_is_deleted(): void
    {
        $device = Device::factory()->create(['site_id' => $this->office->id]);

        $this->actingAs($this->editor)
            ->delete(route('devices.destroy', $device))
            ->assertRedirect(route('devices.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('devices', 0);
    }

    public function test_a_device_is_not_deleted_while_an_address_is_attached_to_it(): void
    {
        $device = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'fw-siege']);
        $subnet = $this->subnet('10.10.10.0/24', ['site_id' => $this->office->id]);

        $first = IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.10.10.1', 'device_id' => $device->id]);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.10.10.2', 'device_id' => $device->id]);

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('devices.destroy', $device)),
            'device',
            "Des adresses IP sont encore rattachées à cet équipement (2) : détachez-les d'abord.",
        );

        $first->update(['device_id' => null]);

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('devices.destroy', $device)),
            'device',
            "Une adresse IP est encore rattachée à cet équipement : détachez-la d'abord.",
        );

        $this->assertDatabaseHas('devices', ['id' => $device->id]);
        $this->assertSame(0, $this->countEntries(ActivityEvent::DeviceDeleted));

        // Once every address is detached, by the action its page offers,
        // the device goes; the addresses stay.
        $this->actingAs($this->editor)->delete(route('devices.ip-addresses.destroy-all', $device))->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->delete(route('devices.destroy', $device))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('devices', 0);
        $this->assertSame(2, IpAddress::query()->whereNull('device_id')->count());
    }

    // --- List -----------------------------------------------------------------

    public function test_the_list_shows_each_device_with_its_site_its_place_and_its_number_of_addresses(): void
    {
        $room = $this->room($this->office);
        $firewall = Device::factory()->create([
            'site_id' => $this->office->id, 'room_id' => $room->id, 'name' => 'fw-siege', 'type' => DeviceType::Firewall,
            'manufacturer' => 'Exemple Réseaux', 'model' => 'FW-200', 'serial_number' => 'SN-0042',
        ]);
        Device::factory()->create(['site_id' => $this->workshop->id, 'name' => 'Imprimante atelier', 'type' => DeviceType::Printer, 'status' => Status::Retired]);

        $subnet = $this->subnet('10.10.10.0/24', ['site_id' => $this->office->id]);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.10.10.1', 'device_id' => $firewall->id]);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.10.10.2', 'device_id' => $firewall->id]);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.10.10.3']);

        $this->actingAs($this->reader())
            ->get(route('devices.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('devices/Index')
                ->has('devices', 2)
                // By name.
                ->where('devices.0', [
                    'id' => $firewall->id,
                    'name' => 'fw-siege',
                    'type' => 'firewall',
                    'type_label' => 'Pare-feu',
                    'manufacturer' => 'Exemple Réseaux',
                    'model' => 'FW-200',
                    'serial_number' => 'SN-0042',
                    'description' => null,
                    'status' => 'active',
                    'status_label' => 'Actif',
                    'site' => ['id' => $this->office->id, 'name' => 'Siège'],
                    // The path of the room, and whether it is technical.
                    'room' => ['id' => $room->id, 'name' => 'Local technique', 'path' => 'Bâtiment principal › Rez-de-chaussée › Local technique', 'is_technical' => true],
                    'addresses_count' => 2,
                ])
                ->where('devices.1.name', 'Imprimante atelier')
                ->where('devices.1.type_label', 'Imprimante')
                ->where('devices.1.site.name', 'Atelier')
                ->where('devices.1.room', null)
                ->where('devices.1.addresses_count', 0)
                // Agreed with the device: "Retiré".
                ->where('devices.1.status_label', 'Retiré')
                ->where('filters', ['site' => '', 'type' => '', 'status' => '', 'search' => ''])
                ->has('sites', 2)
                ->has('types', count(DeviceType::cases()))
                ->where('statuses.0', ['value' => 'planned', 'label' => 'Prévu']));
    }

    public function test_the_list_is_filtered_by_site_by_type_and_by_status(): void
    {
        Device::factory()->create(['site_id' => $this->office->id, 'name' => 'fw-siege', 'type' => DeviceType::Firewall]);
        Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-coeur', 'type' => DeviceType::Switch]);
        Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-futur', 'type' => DeviceType::Switch, 'status' => Status::Planned]);
        Device::factory()->create(['site_id' => $this->workshop->id, 'name' => 'sw-atelier', 'type' => DeviceType::Switch]);

        $names = fn (array $filters): array => array_column(
            (array) $this->actingAs($this->reader())->get(route('devices.index', $filters))->assertOk()->inertiaProps('devices'),
            'name',
        );

        $this->assertSame(['fw-siege', 'sw-coeur', 'sw-futur'], $names(['site' => $this->office->id]));
        $this->assertSame(['sw-atelier', 'sw-coeur', 'sw-futur'], $names(['type' => 'switch']));
        $this->assertSame(['sw-futur'], $names(['status' => 'planned']));
        $this->assertSame(['sw-coeur'], $names(['site' => $this->office->id, 'type' => 'switch', 'status' => 'active']));
        $this->assertSame([], $names(['site' => $this->workshop->id, 'type' => 'firewall']));

        // The filters come back to the page, which shows them chosen.
        $this->actingAs($this->reader())
            ->get(route('devices.index', ['site' => $this->office->id, 'type' => 'switch', 'status' => 'active']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters', ['site' => (string) $this->office->id, 'type' => 'switch', 'status' => 'active', 'search' => '']));

        // What is not a filter is refused, not ignored.
        $reader = $this->reader();

        $this->actingAs($reader)->get(route('devices.index', ['site' => 999999]))->assertSessionHasErrors('site');
        $this->actingAs($reader)->get(route('devices.index', ['type' => 'toaster']))->assertSessionHasErrors('type');
        $this->actingAs($reader)->get(route('devices.index', ['status' => 'broken']))->assertSessionHasErrors('status');
    }

    public function test_the_list_is_searched_by_name_by_model_and_by_serial_number(): void
    {
        Device::factory()->create(['site_id' => $this->office->id, 'name' => 'fw-siege', 'manufacturer' => 'Exemple Réseaux', 'model' => 'FW-200', 'serial_number' => 'SN-0042']);
        Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-coeur', 'model' => 'CS-48P', 'serial_number' => 'XK200-77']);
        Device::factory()->create(['site_id' => $this->workshop->id, 'name' => 'Imprimante 100%', 'model' => 'Laser_A4']);
        Device::factory()->create(['site_id' => $this->workshop->id, 'name' => 'Imprimante 1000', 'model' => 'LaserXA4', 'description' => 'fw-siege est son voisin']);

        $names = fn (array $filters): array => array_column(
            (array) $this->actingAs($this->reader())->get(route('devices.index', $filters))->assertOk()->inertiaProps('devices'),
            'name',
        );

        // In the name, whatever the case.
        $this->assertSame(['fw-siege'], $names(['search' => 'FW-SI']));
        // In the model and in the serial number.
        $this->assertSame(['sw-coeur'], $names(['search' => 'cs-48']));
        $this->assertSame(['fw-siege', 'sw-coeur'], $names(['search' => '200']));
        $this->assertSame(['fw-siege'], $names(['search' => 'sn-00']));
        // Not in the manufacturer nor in the description.
        $this->assertSame([], $names(['search' => 'Exemple']));
        $this->assertSame([], $names(['search' => 'voisin']));
        // The characters a search could take for jokers are looked for as
        // they are.
        $this->assertSame(['Imprimante 100%'], $names(['search' => '100%']));
        $this->assertSame(['Imprimante 100%'], $names(['search' => 'Laser_']));
        // With the filters.
        $this->assertSame(['Imprimante 100%', 'Imprimante 1000'], $names(['search' => ' imprimante ', 'site' => $this->workshop->id]));
        $this->assertSame([], $names(['search' => 'imprimante', 'site' => $this->office->id]));

        $this->actingAs($this->reader())
            ->get(route('devices.index', ['search' => ' sw ']))
            ->assertInertia(fn (Assert $page) => $page->where('filters.search', 'sw'));

        $this->actingAs($this->reader())->get(route('devices.index', ['search' => str_repeat('a', 101)]))->assertSessionHasErrors('search');
    }

    // --- Form -----------------------------------------------------------------

    public function test_the_form_offers_the_rooms_with_their_path_and_their_site(): void
    {
        $technical = $this->room($this->office);
        $reception = $this->room($this->office, 'Accueil', false);
        $cabinet = $this->room($this->workshop, 'Armoire', false);

        $this->actingAs($this->editor)
            ->get(route('devices.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('devices/Form')
                ->where('device', null)
                ->where('site', null)
                ->where('room', null)
                ->has('sites', 2)
                ->has('types', count(DeviceType::cases()))
                ->has('statuses', 3)
                // Every room, by path: the form shows those of the site
                // chosen.
                ->where('rooms', [
                    ['id' => $reception->id, 'name' => 'Accueil', 'path' => 'Bâtiment principal › Rez-de-chaussée › Accueil', 'is_technical' => false, 'site_id' => $this->office->id],
                    ['id' => $cabinet->id, 'name' => 'Armoire', 'path' => 'Bâtiment principal › Rez-de-chaussée › Armoire', 'is_technical' => false, 'site_id' => $this->workshop->id],
                    ['id' => $technical->id, 'name' => 'Local technique', 'path' => 'Bâtiment principal › Rez-de-chaussée › Local technique', 'is_technical' => true, 'site_id' => $this->office->id],
                ]));

        // Opened from a site, or from one of its rooms.
        $this->actingAs($this->editor)
            ->get(route('devices.create', ['site' => $this->workshop->id]))
            ->assertInertia(fn (Assert $page) => $page->where('site', $this->workshop->id)->where('room', null));

        $this->actingAs($this->editor)
            ->get(route('devices.create', ['room' => $cabinet->id]))
            ->assertInertia(fn (Assert $page) => $page->where('site', $this->workshop->id)->where('room', $cabinet->id));

        $this->actingAs($this->editor)->get(route('devices.create', ['site' => 999999]))->assertSessionHasErrors('site');
    }

    public function test_the_form_of_an_existing_device_is_filled_in(): void
    {
        $room = $this->room($this->office);
        $device = Device::factory()->create([
            'site_id' => $this->office->id, 'room_id' => $room->id, 'name' => 'fw-siege', 'type' => DeviceType::Firewall,
            'model' => 'FW-200', 'status' => Status::Planned, 'description' => 'Sortie Internet',
        ]);

        $this->actingAs($this->editor)
            ->get(route('devices.edit', $device))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('devices/Form')
                ->where('device', [
                    'id' => $device->id, 'site_id' => $this->office->id, 'room_id' => $room->id, 'name' => 'fw-siege', 'type' => 'firewall',
                    'manufacturer' => null, 'model' => 'FW-200', 'serial_number' => null, 'status' => 'planned', 'description' => 'Sortie Internet',
                    'has_members' => false,
                ])
                ->has('rooms', 1));
    }

    // --- Rights ---------------------------------------------------------------

    public function test_a_reader_reads_the_devices_but_cannot_write_them(): void
    {
        $reader = $this->reader();
        $device = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'fw-siege']);

        $this->actingAs($reader)->get(route('devices.index'))->assertOk();
        $this->actingAs($reader)->get(route('devices.show', $device))->assertOk();

        $this->actingAs($reader)->get(route('devices.create'))->assertForbidden();
        $this->actingAs($reader)->post(route('devices.store'), $this->payload(['name' => 'sw-01']))->assertForbidden();
        $this->actingAs($reader)->get(route('devices.edit', $device))->assertForbidden();
        $this->actingAs($reader)->put(route('devices.update', $device), $this->payload(['name' => 'renamed']))->assertForbidden();
        $this->actingAs($reader)->delete(route('devices.destroy', $device))->assertForbidden();

        $this->assertSame(['fw-siege'], Device::query()->pluck('name')->all());
    }

    public function test_an_administrator_writes_devices_like_an_editor_and_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('devices.index'))->assertRedirect(route('login'));
        $this->post(route('devices.store'), $this->payload())->assertRedirect(route('login'));

        $this->actingAs($this->admin())->post(route('devices.store'), $this->payload())->assertSessionHasNoErrors();

        $this->assertDatabaseHas('devices', ['name' => 'fw-siege']);

        $this->actingAs($this->editor)->get(route('devices.show', 999999))->assertNotFound();
    }

    // --- Activity log ---------------------------------------------------------

    public function test_the_life_of_a_device_is_logged(): void
    {
        $room = $this->room($this->office);

        $this->actingAs($this->editor)->post(route('devices.store'), $this->payload([
            'room_id' => $room->id, 'manufacturer' => 'Exemple Réseaux', 'model' => 'FW-200', 'serial_number' => 'SN-0042',
        ]));

        $device = Device::query()->sole();
        $created = $this->lastEntry(ActivityEvent::DeviceCreated);

        $this->assertTrue($this->editor->is($created->causer));
        $this->assertTrue($device->is($created->subject));
        $this->assertSame([
            'site' => 'Siège',
            'room' => 'Bâtiment principal › Rez-de-chaussée › Local technique',
            'name' => 'fw-siege',
            'type' => 'firewall',
            'manufacturer' => 'Exemple Réseaux',
            'model' => 'FW-200',
            'serial_number' => 'SN-0042',
            'status' => 'active',
            'description' => null,
        ], ($created->attribute_changes?->toArray() ?? [])['attributes']);
        // With its site: two sites may each have a device of that name.
        $this->assertSame('fw-siege (Siège)', $created->getProperty('label'));

        $this->actingAs($this->editor)->put(route('devices.update', $device), $this->payload([
            'room_id' => null, 'name' => 'fw-siege-01', 'manufacturer' => 'Exemple Réseaux', 'model' => 'FW-300', 'serial_number' => 'SN-0042', 'status' => 'retired',
        ]));

        $updated = $this->lastEntry(ActivityEvent::DeviceUpdated)->attribute_changes?->toArray() ?? [];

        // Only what changed, before and after.
        $this->assertSame(['room' => 'Bâtiment principal › Rez-de-chaussée › Local technique', 'name' => 'fw-siege', 'model' => 'FW-200', 'status' => 'active'], $updated['old']);
        $this->assertSame(['room' => null, 'name' => 'fw-siege-01', 'model' => 'FW-300', 'status' => 'retired'], $updated['attributes']);

        // An update that changes nothing writes nothing.
        $this->actingAs($this->editor)->put(route('devices.update', $device), $this->payload([
            'room_id' => null, 'name' => 'fw-siege-01', 'manufacturer' => 'Exemple Réseaux', 'model' => 'FW-300', 'serial_number' => 'SN-0042', 'status' => 'retired',
        ]));

        $this->assertSame(1, $this->countEntries(ActivityEvent::DeviceUpdated));

        $this->actingAs($this->editor)->delete(route('devices.destroy', $device));

        $deleted = $this->lastEntry(ActivityEvent::DeviceDeleted);

        $this->assertNull($deleted->subject);
        $this->assertSame('fw-siege-01 (Siège)', $deleted->getProperty('label'));
        $this->assertSame('FW-300', ($deleted->attribute_changes?->toArray() ?? [])['old']['model']);
    }

    public function test_the_log_page_reads_a_device_in_french(): void
    {
        $device = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-futur', 'type' => DeviceType::Switch, 'status' => Status::Planned]);

        $this->actingAs($this->editor)->put(route('devices.update', $device), $this->payload([
            'name' => 'sw-futur', 'type' => 'access_point', 'serial_number' => 'SN-1', 'status' => 'active',
        ]));

        $this->actingAs($this->admin())
            ->get(route('activity-log.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.event_label', 'Équipement modifié')
                ->where('entries.data.0.subject.title', 'sw-futur (Siège)')
                ->where('entries.data.0.changes', [
                    ['field' => 'Type', 'old' => 'Switch', 'new' => 'Borne Wi-Fi'],
                    ['field' => 'Numéro de série', 'old' => '—', 'new' => 'SN-1'],
                    // Agreed with the device: "Prévu", "Actif".
                    ['field' => 'Statut', 'old' => 'Prévu', 'new' => 'Actif'],
                ]));
    }
}
