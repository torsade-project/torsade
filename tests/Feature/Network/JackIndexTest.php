<?php

// torsade/tests/Feature/Network/JackIndexTest.php

namespace Tests\Feature\Network;

use App\Enums\DeviceType;
use App\Enums\PortMode;
use App\Enums\Status;
use App\Models\Building;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\Floor;
use App\Models\IpAddress;
use App\Models\Jack;
use App\Models\PatchPanel;
use App\Models\PatchPanelPort;
use App\Models\Port;
use App\Models\PortVlan;
use App\Models\Room;
use App\Models\Site;
use App\Models\User;
use App\Models\Vlan;
use Database\Factories\PatchCordFactory;
use Database\Factories\PortFactory;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The list of jacks: each one with where it is, its path towards a device
 * and the addresses plugged into it, filtered by place, status and text.
 */
class JackIndexTest extends NetworkTestCase
{
    private User $reader;

    private Site $office;

    private Building $main;

    private Floor $ground;

    private Floor $first;

    private PatchPanel $panel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = $this->reader();
        $this->office = Site::factory()->create(['name' => 'Siège']);
        $this->main = Building::factory()->create(['site_id' => $this->office->id, 'name' => 'Bâtiment principal']);
        $this->ground = Floor::factory()->create(['building_id' => $this->main->id, 'level' => 0, 'name' => 'Rez-de-chaussée']);
        $this->first = Floor::factory()->create(['building_id' => $this->main->id, 'level' => 1, 'name' => '1er étage']);

        $rack = Room::factory()->technical()->create(['floor_id' => $this->ground->id, 'name' => 'Local technique']);

        $this->panel = PatchPanel::factory()->create(['room_id' => $rack->id, 'name' => 'PB-A', 'port_count' => 24]);
    }

    private function port(int $number): PatchPanelPort
    {
        return PatchPanelPort::query()->where('patch_panel_id', $this->panel->id)->where('number', $number)->sole();
    }

    /**
     * A switch of the site with its first ports.
     *
     * @return array<string, Port>
     */
    private function switchPorts(): array
    {
        $device = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-etage-1', 'type' => DeviceType::Switch]);
        $ports = [];

        foreach (PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $device->id, 'position' => 1]), 4) as $port) {
            $ports[$port->name] = $port;
        }

        return $ports;
    }

    /**
     * The labels the list shows for these filters, in its order.
     *
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function labels(array $filters = []): array
    {
        $labels = null;

        $this->actingAs($this->reader)
            ->get(route('jacks.index', $filters))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$labels): void {
                $labels = array_column($page->toArray()['props']['jacks'], 'label');
            });

        $this->assertIsArray($labels);

        return $labels;
    }

    public function test_the_page_lists_every_jack_with_its_path_and_its_addresses(): void
    {
        $ports = $this->switchPorts();
        $vlan = Vlan::factory()->create(['site_id' => $this->office->id, 'vid' => 10, 'name' => 'Bureautique']);
        $ports['1/0/1']->update(['mode' => PortMode::Access]);
        PortVlan::factory()->create(['port_id' => $ports['1/0/1']->id, 'vlan_id' => $vlan->id, 'tagged' => false]);

        // B.1: patched to an access port. B.2: cabled to a panel, not
        // patched. B.3: on its floor alone.
        $patched = Jack::factory()->create(['floor_id' => $this->first->id, 'label' => 'B.1', 'location' => 'Bureau 12', 'patch_panel_port_id' => $this->port(1)->id]);
        Jack::factory()->create(['floor_id' => $this->first->id, 'label' => 'B.2', 'patch_panel_port_id' => $this->port(2)->id]);
        Jack::factory()->create(['floor_id' => $this->first->id, 'label' => 'B.3', 'status' => Status::Planned]);
        PatchCordFactory::between($this->port(1), $ports['1/0/1']);

        $subnet = $this->subnet('192.168.10.0/24', ['site_id' => $this->office->id, 'vlan_id' => $vlan->id]);
        $second = IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.10.20', 'jack_id' => $patched->id]);
        $first = IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '192.168.10.3', 'hostname' => 'PC-COMPTA-01', 'jack_id' => $patched->id]);

        $this->actingAs($this->reader)
            ->get(route('jacks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('jacks/Index')
                ->where('total', 3)
                ->has('jacks', 3)
                ->where('jacks.0', [
                    'id' => $patched->id,
                    'label' => 'B.1',
                    'location' => 'Bureau 12',
                    'status' => 'active',
                    'status_label' => 'Active',
                    'description' => null,
                    'site' => ['id' => $this->office->id, 'name' => 'Siège'],
                    'panel_port' => ['id' => $this->port(1)->id, 'number' => 1, 'panel' => ['id' => $this->panel->id, 'name' => 'PB-A']],
                    'path' => [
                        'jack' => ['id' => $patched->id, 'label' => 'B.1', 'place' => 'Bâtiment principal › 1er étage'],
                        'plugged' => null,
                        'panel_port' => ['id' => $this->port(1)->id, 'name' => 'PB-A 1', 'panel_id' => $this->panel->id],
                        'device' => ['id' => $ports['1/0/1']->device_id, 'name' => 'sw-etage-1'],
                        'port' => ['id' => $ports['1/0/1']->id, 'name' => '1/0/1'],
                        'configuration' => 'Accès 10',
                    ],
                    // No device is plugged into it.
                    'cord' => null,
                    // By address, a number read as a number.
                    'addresses' => [
                        ['id' => $first->id, 'address' => '192.168.10.3', 'hostname' => 'PC-COMPTA-01', 'subnet_id' => $subnet->id, 'status' => 'active'],
                        ['id' => $second->id, 'address' => '192.168.10.20', 'hostname' => null, 'subnet_id' => $subnet->id, 'status' => 'active'],
                    ],
                    'deviations' => [],
                ])
                // The path stops where nothing is declared.
                ->where('jacks.1.path.panel_port.name', 'PB-A 2')
                ->where('jacks.1.path.device', null)
                ->where('jacks.1.path.configuration', null)
                ->where('jacks.2.path.panel_port', null)
                ->where('jacks.2.status_label', 'Prévue')
                ->has('statuses', 3)
                ->where('places', [[
                    'id' => $this->office->id,
                    'name' => 'Siège',
                    'buildings' => [[
                        'id' => $this->main->id,
                        'name' => 'Bâtiment principal',
                        'floors' => [
                            ['id' => $this->ground->id, 'name' => 'Rez-de-chaussée'],
                            ['id' => $this->first->id, 'name' => '1er étage'],
                        ],
                    ]],
                ]])
                ->missing('panelChoices'));
    }

    public function test_the_jacks_are_listed_by_site_building_floor_and_label_a_number_read_as_a_number(): void
    {
        $workshop = Site::factory()->create(['name' => 'Atelier']);
        $hangar = Building::factory()->create(['site_id' => $workshop->id, 'name' => 'Hangar']);
        $annex = Building::factory()->create(['site_id' => $this->office->id, 'name' => 'Annexe']);

        Jack::factory()->create(['floor_id' => Floor::factory()->create(['building_id' => $hangar->id, 'level' => 0])->id, 'label' => 'H.1']);
        Jack::factory()->create(['floor_id' => Floor::factory()->create(['building_id' => $annex->id, 'level' => 0])->id, 'label' => 'X.1']);

        foreach (['B.10', 'B.2', 'b.1'] as $label) {
            Jack::factory()->create(['floor_id' => $this->first->id, 'label' => $label]);
        }

        Jack::factory()->create(['floor_id' => $this->ground->id, 'label' => 'R.9']);

        $this->assertSame(['H.1', 'X.1', 'R.9', 'b.1', 'B.2', 'B.10'], $this->labels());
    }

    public function test_the_filters_keep_a_place_a_status_and_what_was_typed(): void
    {
        $workshop = Site::factory()->create(['name' => 'Atelier']);
        $hangar = Building::factory()->create(['site_id' => $workshop->id, 'name' => 'Hangar']);

        Jack::factory()->create(['floor_id' => Floor::factory()->create(['building_id' => $hangar->id, 'level' => 0])->id, 'label' => 'H.1', 'location' => 'Quai']);
        Jack::factory()->create(['floor_id' => $this->ground->id, 'label' => 'R.1', 'location' => 'Accueil 100%']);
        Jack::factory()->create(['floor_id' => $this->first->id, 'label' => 'B.1', 'status' => Status::Retired]);
        Jack::factory()->create(['floor_id' => $this->first->id, 'label' => 'B.2', 'location' => 'Bureau du quai']);

        $this->assertSame(['R.1', 'B.1', 'B.2'], $this->labels(['site' => $this->office->id]));
        $this->assertSame(['R.1', 'B.1', 'B.2'], $this->labels(['building' => $this->main->id]));
        $this->assertSame(['B.1', 'B.2'], $this->labels(['floor' => $this->first->id]));
        $this->assertSame(['B.1'], $this->labels(['status' => 'retired']));

        // In the label or the location, whatever the case; "%" is a
        // character like the others.
        $this->assertSame(['H.1', 'B.2'], $this->labels(['search' => 'QUAI']));
        $this->assertSame(['B.1', 'B.2'], $this->labels(['search' => 'b.']));
        $this->assertSame(['R.1'], $this->labels(['search' => '100%']));
        $this->assertSame([], $this->labels(['search' => '%%']));

        // Combined, and counted against every jack.
        $this->actingAs($this->reader)
            ->get(route('jacks.index', ['site' => $this->office->id, 'search' => 'bureau']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('jacks', 1)
                ->where('total', 4)
                ->where('filters', [
                    'site' => (string) $this->office->id, 'building' => '', 'floor' => '', 'status' => '',
                    'deviations' => '', 'search' => 'bureau',
                ]));
    }

    public function test_the_window_of_a_jack_is_offered_the_panels_of_its_site_when_it_asks(): void
    {
        Jack::factory()->create(['floor_id' => $this->first->id, 'label' => 'B.1', 'patch_panel_port_id' => $this->port(1)->id]);

        $choices = null;

        $this->actingAs($this->reader)
            ->get(route('jacks.index', ['panels' => $this->office->id]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$choices): void {
                $page->missing('panelChoices')->reloadOnly('panelChoices', function (Assert $reload) use (&$choices): void {
                    $choices = $reload->toArray()['props']['panelChoices'];
                });
            });

        $this->assertIsArray($choices);
        $this->assertSame(['PB-A'], array_column($choices, 'name'));
        $this->assertSame(range(2, 24), array_column($choices[0]['free'], 'number'));
    }

    public function test_a_filter_that_is_not_one_is_refused(): void
    {
        $this->actingAs($this->reader)->get(route('jacks.index', ['site' => 999999]))->assertSessionHasErrors('site');
        $this->actingAs($this->reader)->get(route('jacks.index', ['building' => 'principal']))->assertSessionHasErrors('building');
        $this->actingAs($this->reader)->get(route('jacks.index', ['floor' => 999999]))->assertSessionHasErrors('floor');
        $this->actingAs($this->reader)->get(route('jacks.index', ['status' => 'broken']))->assertSessionHasErrors('status');
        $this->actingAs($this->reader)->get(route('jacks.index', ['deviations' => 'oui']))->assertSessionHasErrors('deviations');
        $this->actingAs($this->reader)->get(route('jacks.index', ['search' => str_repeat('a', 101)]))->assertSessionHasErrors('search');
        $this->actingAs($this->reader)->get(route('jacks.index', ['panels' => 999999]))->assertSessionHasErrors('panels');
    }

    public function test_the_three_roles_read_the_page_and_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('jacks.index'))->assertRedirect(route('login'));

        foreach ([$this->reader, $this->editor(), $this->admin()] as $user) {
            $this->actingAs($user)
                ->get(route('jacks.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('jacks/Index')->where('jacks', [])->where('total', 0));
        }
    }
}
