<?php

// torsade/tests/Feature/Network/PatchPanelTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\DeviceType;
use App\Enums\PanelConnector;
use App\Enums\PortMode;
use App\Enums\Status;
use App\Models\Building;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\Floor;
use App\Models\Jack;
use App\Models\PatchPanel;
use App\Models\PatchPanelPort;
use App\Models\Port;
use App\Models\PortAggregate;
use App\Models\PortVlan;
use App\Models\Room;
use App\Models\Site;
use App\Models\User;
use App\Models\Vlan;
use Database\Factories\PatchCordFactory;
use Database\Factories\PortFactory;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * Patch panels: kept in a room, made with their ports, numbered from 1,
 * changed, deleted, and read on a page of their own.
 */
class PatchPanelTest extends NetworkTestCase
{
    private User $editor;

    private Site $office;

    private Floor $ground;

    private Floor $first;

    private Room $rack;

    protected function setUp(): void
    {
        parent::setUp();

        $this->editor = $this->editor();
        $this->office = Site::factory()->create(['name' => 'Siège']);

        $building = Building::factory()->create(['site_id' => $this->office->id, 'name' => 'Bâtiment principal']);

        $this->ground = Floor::factory()->create(['building_id' => $building->id, 'level' => 0, 'name' => 'Rez-de-chaussée']);
        $this->first = Floor::factory()->create(['building_id' => $building->id, 'level' => 1, 'name' => '1er étage']);
        $this->rack = Room::factory()->technical()->create(['floor_id' => $this->ground->id, 'name' => 'Local technique']);
    }

    /**
     * What the window of a panel sends.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'PB-A',
            'port_count' => 24,
            'connector' => 'rj45',
            'category' => '6A',
            'status' => 'active',
            'description' => '',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function create(array $overrides = [], ?Room $room = null): TestResponse
    {
        return $this->actingAs($this->editor)->post(route('patch-panels.store', $room ?? $this->rack), $this->payload($overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function update(PatchPanel $panel, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->editor)->put(route('patch-panels.update', $panel), $this->payload([
            'name' => $panel->name,
            'port_count' => $panel->port_count,
            'connector' => $panel->connector->value,
            'category' => $panel->category,
            'status' => $panel->status->value,
            ...$overrides,
        ]));
    }

    /**
     * The numbers of the ports of a panel, in their order.
     *
     * @return list<int>
     */
    private function numbers(PatchPanel $panel): array
    {
        return array_values(PatchPanelPort::query()->where('patch_panel_id', $panel->id)->orderBy('number')->pluck('number')->all());
    }

    private function port(PatchPanel $panel, int $number): PatchPanelPort
    {
        return PatchPanelPort::query()->where('patch_panel_id', $panel->id)->where('number', $number)->sole();
    }

    /**
     * A switch of the site, kept in the technical room, with ports of its
     * own.
     *
     * @return array<string, Port>
     */
    private function switchPorts(int $count = 4): array
    {
        $device = Device::factory()->create(['site_id' => $this->office->id, 'room_id' => $this->rack->id, 'name' => 'sw-etage-1', 'type' => DeviceType::Switch]);
        $ports = [];

        foreach (PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $device->id, 'position' => 1]), $count) as $port) {
            $ports[$port->name] = $port;
        }

        return $ports;
    }

    // --- Creation -------------------------------------------------------------

    public function test_an_editor_creates_a_panel_in_a_room_with_its_ports(): void
    {
        $this->create(['description' => 'Baie A, en haut'])
            ->assertRedirect(route('sites.show', $this->office))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Panneau créé.');

        $panel = PatchPanel::query()->sole();

        $this->assertSame(
            [$this->rack->id, 'PB-A', 24, PanelConnector::Rj45, '6A', Status::Active, 'Baie A, en haut'],
            [$panel->room_id, $panel->name, $panel->port_count, $panel->connector, $panel->category, $panel->status, $panel->description],
        );

        // Its ports are made with it, numbered from 1.
        $this->assertSame(range(1, 24), $this->numbers($panel));

        // One entry of the log for the panel and its ports.
        $created = $this->lastEntry(ActivityEvent::PatchPanelCreated);

        $this->assertSame('Bâtiment principal › Rez-de-chaussée › Local technique › PB-A (Siège)', $created->getProperty('label'));
        $this->assertSame(
            ['room' => 'Bâtiment principal › Rez-de-chaussée › Local technique', 'name' => 'PB-A', 'port_count' => 24, 'connector' => 'rj45', 'category' => '6A', 'status' => 'active', 'description' => 'Baie A, en haut'],
            ($created->attribute_changes?->toArray() ?? [])['attributes'],
        );
        $this->assertSame(1, $this->countEntries(ActivityEvent::PatchPanelCreated));
    }

    public function test_the_shape_of_a_panel_is_checked(): void
    {
        $this->assertRefused($this->create(['name' => '']), 'name');
        $this->assertRefused($this->create(['name' => str_repeat('a', 65)]), 'name');
        $this->assertRefused($this->create(['port_count' => 0]), 'port_count');
        $this->assertRefused($this->create(['port_count' => 97]), 'port_count');
        $this->assertRefused($this->create(['port_count' => 'vingt']), 'port_count');
        $this->assertRefused($this->create(['connector' => 'st']), 'connector');
        $this->assertRefused($this->create(['category' => str_repeat('a', 17)]), 'category');
        $this->assertRefused($this->create(['status' => 'broken']), 'status');
        $this->assertRefused($this->create(['description' => str_repeat('a', 1001)]), 'description');

        $this->assertDatabaseCount('patch_panels', 0);
        $this->assertDatabaseCount('patch_panel_ports', 0);

        // At the limits, a panel is accepted, with each connector.
        $this->create(['name' => str_repeat('a', 64), 'port_count' => 96, 'category' => str_repeat('b', 16), 'description' => str_repeat('c', 1000)])->assertSessionHasNoErrors();

        foreach (['lc', 'sc', 'other'] as $index => $connector) {
            $this->create(['name' => 'PB-'.$index, 'port_count' => 1, 'connector' => $connector, 'category' => ''])->assertSessionHasNoErrors();
        }

        $this->assertSame([96, 1, 1, 1], PatchPanel::query()->orderBy('id')->pluck('port_count')->all());
        $this->assertNull(PatchPanel::query()->where('name', 'PB-0')->value('category'));
        $this->assertDatabaseCount('patch_panel_ports', 99);
    }

    public function test_the_name_of_a_panel_is_unique_within_its_room_whatever_its_case(): void
    {
        $this->create()->assertSessionHasNoErrors();

        $this->assertRefused($this->create(['name' => 'pb-a']), 'name', 'Le local a déjà un panneau nommé pb-a.');

        // Another room has its own names.
        $office = Room::factory()->create(['floor_id' => $this->first->id, 'name' => 'Bureaux']);

        $this->create([], $office)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('patch_panels', 2);
        $this->assertDatabaseCount('patch_panel_ports', 48);

        // Renamed onto another panel of its room, too.
        $other = PatchPanel::factory()->create(['room_id' => $this->rack->id, 'name' => 'PB-B']);

        $this->assertRefused($this->update($other, ['name' => 'PB-A']), 'name');
    }

    // --- Update ---------------------------------------------------------------

    public function test_more_ports_are_added_after_the_last_and_fewer_take_the_last_away(): void
    {
        $panel = PatchPanel::factory()->create(['room_id' => $this->rack->id, 'name' => 'PB-A', 'port_count' => 12]);
        $kept = $this->port($panel, 3)->id;

        $this->actingAs($this->editor)
            ->from(route('patch-panels.show', $panel))
            ->put(route('patch-panels.update', $panel), $this->payload(['port_count' => 16, 'name' => 'PB-A1']))
            ->assertRedirect(route('patch-panels.show', $panel))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Panneau modifié.');

        $this->assertSame(range(1, 16), $this->numbers($panel));
        // The ports already there are kept as they are.
        $this->assertSame($kept, $this->port($panel, 3)->id);

        $this->update($panel->refresh(), ['port_count' => 8])->assertSessionHasNoErrors();

        $this->assertSame(range(1, 8), $this->numbers($panel));
        $this->assertSame(8, $panel->refresh()->port_count);

        // One entry per change, which tells the number of ports.
        $updated = $this->lastEntry(ActivityEvent::PatchPanelUpdated);

        $this->assertSame(['old' => ['port_count' => 16], 'attributes' => ['port_count' => 8]], $updated->attribute_changes?->toArray());
        $this->assertSame(2, $this->countEntries(ActivityEvent::PatchPanelUpdated));
    }

    public function test_fewer_ports_are_refused_when_a_port_taken_away_has_a_jack_or_a_cord(): void
    {
        $panel = PatchPanel::factory()->create(['room_id' => $this->rack->id, 'name' => 'PB-A', 'port_count' => 24]);
        $ports = $this->switchPorts();

        Jack::factory()->create(['floor_id' => $this->first->id, 'label' => 'B.20', 'patch_panel_port_id' => $this->port($panel, 20)->id]);
        PatchCordFactory::between($this->port($panel, 22), $ports['1/0/1']);

        $this->assertRefused(
            $this->update($panel, ['port_count' => 16]),
            'port_count',
            "Les ports 20, 22 ont une prise ou un cordon : ils ne sont pas retirés. Détachez les prises et retirez les cordons d'abord, ou gardez plus de ports.",
        );
        $this->assertRefused(
            $this->update($panel, ['port_count' => 21]),
            'port_count',
            "Le port 22 a une prise ou un cordon : il n'est pas retiré. Détachez la prise et retirez le cordon d'abord, ou gardez plus de ports.",
        );

        $this->assertSame(range(1, 24), $this->numbers($panel));
        $this->assertSame(24, $panel->refresh()->port_count);
        $this->assertSame(0, $this->countEntries(ActivityEvent::PatchPanelUpdated));

        // Down to the last port in use, it is accepted.
        $this->update($panel, ['port_count' => 22])->assertSessionHasNoErrors();

        $this->assertSame(range(1, 22), $this->numbers($panel));
    }

    // --- Deletion -------------------------------------------------------------

    public function test_a_panel_is_deleted_with_its_ports_once_none_has_a_jack_or_a_cord(): void
    {
        $panel = PatchPanel::factory()->create(['room_id' => $this->rack->id, 'name' => 'PB-A', 'port_count' => 24]);
        $ports = $this->switchPorts();

        $jacks = [];

        foreach (range(1, 12) as $number) {
            $jacks[] = Jack::factory()->create(['floor_id' => $this->first->id, 'label' => 'B.'.$number, 'patch_panel_port_id' => $this->port($panel, $number)->id]);
        }

        $cord = PatchCordFactory::between($this->port($panel, 24), $ports['1/0/4']);

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('patch-panels.destroy', $panel)),
            'panel',
            "Les ports 1, 2, 3, 4, 5, 6, 7, 8, 9, 10 et 3 de plus de ce panneau ont une prise ou un cordon : détachez les prises et retirez les cordons d'abord.",
        );

        $this->assertDatabaseCount('patch_panels', 1);
        $this->assertDatabaseCount('patch_panel_ports', 24);

        // Once the jacks and the cord are gone, the panel goes with its
        // ports.
        Jack::query()->whereKey(array_map(fn (Jack $jack): int => $jack->id, $jacks))->update(['patch_panel_port_id' => null]);
        $cord->delete();

        $this->actingAs($this->editor)
            ->delete(route('patch-panels.destroy', $panel))
            ->assertRedirect(route('sites.show', $this->office))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Panneau supprimé.');

        $this->assertDatabaseCount('patch_panels', 0);
        $this->assertDatabaseCount('patch_panel_ports', 0);
        $this->assertDatabaseCount('jacks', 12);

        $deleted = $this->lastEntry(ActivityEvent::PatchPanelDeleted);

        $this->assertSame('Bâtiment principal › Rez-de-chaussée › Local technique › PB-A (Siège)', $deleted->getProperty('label'));
        $this->assertSame(24, ($deleted->attribute_changes?->toArray() ?? [])['old']['port_count']);
    }

    public function test_a_room_that_holds_a_panel_is_not_deleted(): void
    {
        $panel = PatchPanel::factory()->create(['room_id' => $this->rack->id]);

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('rooms.destroy', $this->rack)),
            'room',
            "Ce local contient encore 1 panneau de brassage : videz-le d'abord.",
        );

        PatchPanel::factory()->create(['room_id' => $this->rack->id]);

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('rooms.destroy', $this->rack)),
            'room',
            "Ce local contient encore 2 panneaux de brassage : videz-le d'abord.",
        );

        $this->assertDatabaseHas('rooms', ['id' => $this->rack->id]);

        PatchPanel::query()->delete();

        $this->actingAs($this->editor)->delete(route('rooms.destroy', $this->rack))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('rooms', ['id' => $this->rack->id]);
        $this->assertNotNull($panel->id);
    }

    // --- Pages ----------------------------------------------------------------

    public function test_the_page_of_a_site_lists_the_panels_of_each_room(): void
    {
        $panel = PatchPanel::factory()->create(['room_id' => $this->rack->id, 'name' => 'PB-B', 'port_count' => 24, 'category' => '6A']);
        PatchPanel::factory()->create(['room_id' => $this->rack->id, 'name' => 'PB-10', 'port_count' => 12, 'connector' => PanelConnector::Lc, 'category' => null]);
        PatchPanel::factory()->create(['room_id' => $this->rack->id, 'name' => 'PB-2', 'port_count' => 6]);
        $ports = $this->switchPorts();

        // Two ports occupied: one with a jack, one with a cord.
        Jack::factory()->create(['floor_id' => $this->first->id, 'patch_panel_port_id' => $this->port($panel, 1)->id]);
        PatchCordFactory::between($this->port($panel, 2), $ports['1/0/1']);

        $this->actingAs($this->reader())
            ->get(route('sites.show', $this->office))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // By name, a number read as a number.
                ->where('places.0.floors.0.rooms.0.panels.0.name', 'PB-2')
                ->where('places.0.floors.0.rooms.0.panels.1.name', 'PB-10')
                ->where('places.0.floors.0.rooms.0.panels.1.connector_label', 'Fibre LC')
                ->where('places.0.floors.0.rooms.0.panels.2', [
                    'id' => $panel->id, 'name' => 'PB-B', 'port_count' => 24, 'occupied' => 2,
                    'connector' => 'rj45', 'connector_label' => 'RJ45', 'category' => '6A',
                    'status' => 'active', 'status_label' => 'Actif', 'description' => null,
                ])
                ->where('places.0.floors.1.rooms', [])
                ->has('panelConnectors', 4));
    }

    public function test_the_page_of_a_panel_lists_its_ports_with_their_jack_and_their_cord(): void
    {
        $panel = PatchPanel::factory()->create(['room_id' => $this->rack->id, 'name' => 'PB-A', 'port_count' => 4, 'category' => '6A']);
        $ports = $this->switchPorts();
        $vlan = Vlan::factory()->create(['site_id' => $this->office->id, 'vid' => 10, 'name' => 'Bureautique']);

        // Port 1: a jack and a cord to an access port. Port 2: a jack alone.
        // Port 3: a cord alone, to a port of an aggregate. Port 4: free.
        $ports['1/0/1']->update(['mode' => PortMode::Access]);
        PortVlan::factory()->create(['port_id' => $ports['1/0/1']->id, 'vlan_id' => $vlan->id, 'tagged' => false]);
        $aggregate = PortAggregate::factory()->create(['device_id' => $ports['1/0/3']->device_id, 'name' => 'Po1', 'mode' => PortMode::Trunk]);
        PortVlan::factory()->create(['port_aggregate_id' => $aggregate->id, 'vlan_id' => $vlan->id, 'tagged' => true]);
        $ports['1/0/3']->update(['port_aggregate_id' => $aggregate->id]);

        $jack = Jack::factory()->create(['floor_id' => $this->first->id, 'label' => 'B.01', 'location' => 'Bureau 12', 'patch_panel_port_id' => $this->port($panel, 1)->id]);
        Jack::factory()->create(['floor_id' => $this->first->id, 'label' => 'B.02', 'patch_panel_port_id' => $this->port($panel, 2)->id, 'status' => Status::Planned]);
        $cord = PatchCordFactory::between($this->port($panel, 1), $ports['1/0/1'], ['color' => 'Bleu']);
        PatchCordFactory::between($this->port($panel, 3), $ports['1/0/3']);

        $this->actingAs($this->reader())
            ->get(route('patch-panels.show', $panel))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('patch-panels/Show')
                ->where('panel.name', 'PB-A')
                ->where('panel.occupied', 3)
                ->where('panel.room.path', 'Bâtiment principal › Rez-de-chaussée › Local technique')
                ->where('panel.site', ['id' => $this->office->id, 'name' => 'Siège'])
                ->has('ports', 4)
                ->where('ports.0', [
                    'id' => $this->port($panel, 1)->id,
                    'number' => 1,
                    'jack' => ['id' => $jack->id, 'label' => 'B.01', 'place' => 'Bâtiment principal › 1er étage', 'location' => 'Bureau 12', 'status' => 'active', 'status_label' => 'Active'],
                    'cord' => [
                        'id' => $cord->id, 'color' => 'Bleu', 'description' => null,
                        'device' => ['id' => $ports['1/0/1']->device_id, 'name' => 'sw-etage-1'],
                        'port' => ['id' => $ports['1/0/1']->id, 'name' => '1/0/1', 'enabled' => true],
                        'configuration' => 'Accès 10',
                    ],
                    'deviations' => [],
                ])
                ->where('ports.1.jack.status_label', 'Prévue')
                ->where('ports.1.cord', null)
                ->where('ports.2.jack', null)
                ->where('ports.2.cord.configuration', 'via Po1 : Trunk : 10')
                ->where('ports.3.jack', null)
                ->where('ports.3.cord', null)
                ->has('connectors', 4)
                ->has('statuses', 3));

        $this->actingAs($this->reader())->get(route('patch-panels.show', 999999))->assertNotFound();
    }

    // --- Rights ---------------------------------------------------------------

    public function test_a_reader_cannot_write_a_panel_and_a_guest_is_sent_to_the_login_page(): void
    {
        $panel = PatchPanel::factory()->create(['room_id' => $this->rack->id, 'name' => 'PB-A']);
        $reader = $this->reader();

        $this->actingAs($reader)->post(route('patch-panels.store', $this->rack), $this->payload(['name' => 'PB-B']))->assertForbidden();
        $this->actingAs($reader)->put(route('patch-panels.update', $panel), $this->payload(['port_count' => 48]))->assertForbidden();
        $this->actingAs($reader)->delete(route('patch-panels.destroy', $panel))->assertForbidden();

        $this->assertSame(['PB-A'], PatchPanel::query()->pluck('name')->all());
        $this->assertSame(24, $panel->refresh()->port_count);

        auth()->logout();

        $this->get(route('patch-panels.show', $panel))->assertRedirect(route('login'));
        $this->post(route('patch-panels.store', $this->rack), $this->payload())->assertRedirect(route('login'));

        // An administrator writes them like an editor.
        $this->actingAs($this->admin())->put(route('patch-panels.update', $panel), $this->payload(['port_count' => 48]))->assertSessionHasNoErrors();

        $this->assertSame(48, $panel->refresh()->port_count);
    }

    public function test_the_activity_log_tells_the_life_of_a_panel_in_words(): void
    {
        $this->create(['connector' => 'lc', 'port_count' => 12])->assertSessionHasNoErrors();

        $panel = PatchPanel::query()->sole();

        $this->update($panel, ['port_count' => 24])->assertSessionHasNoErrors();

        $this->actingAs($this->admin())
            ->get(route('activity-log.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.event_label', 'Panneau modifié')
                ->where('entries.data.0.changes', [['field' => 'Nombre de ports', 'old' => '12', 'new' => '24']])
                ->where('entries.data.1.event_label', 'Panneau créé')
                ->where('entries.data.1.changes', [
                    ['field' => 'Local', 'old' => null, 'new' => 'Bâtiment principal › Rez-de-chaussée › Local technique'],
                    ['field' => 'Nom', 'old' => null, 'new' => 'PB-A'],
                    ['field' => 'Nombre de ports', 'old' => null, 'new' => '12'],
                    ['field' => 'Connecteur', 'old' => null, 'new' => 'Fibre LC'],
                    ['field' => 'Catégorie', 'old' => null, 'new' => '6A'],
                    ['field' => 'Statut', 'old' => null, 'new' => 'Actif'],
                ]));
    }
}
