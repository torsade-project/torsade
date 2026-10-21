<?php

// torsade/tests/Feature/Network/PhysicalDiagramTest.php

namespace Tests\Feature\Network;

use App\Enums\DeviceType;
use App\Enums\LinkMedium;
use App\Enums\PortMode;
use App\Enums\Status;
use App\Models\Building;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\DiagramPosition;
use App\Models\Floor;
use App\Models\IpAddress;
use App\Models\Link;
use App\Models\Port;
use App\Models\PortAggregate;
use App\Models\PortVlan;
use App\Models\Room;
use App\Models\Site;
use App\Models\User;
use App\Models\Vlan;
use App\Services\Network\PhysicalDiagram;
use Database\Factories\LinkFactory;
use Database\Factories\PortFactory;
use Database\Seeders\DiagramVolumeSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The physical diagram of a site, worked out case by case: which devices
 * are drawn and where, which ports, which links and in which direction,
 * in a stable order, with the deviations of links and of devices.
 */
class PhysicalDiagramTest extends NetworkTestCase
{
    private Site $office;

    private Site $workshop;

    private Room $rack;

    private Room $cupboard;

    /**
     * The ports of each device, by the name of the device, then of the port.
     *
     * @var array<string, array<string, Port>>
     */
    private array $ports = [];

    /** @var array<string, Device> */
    private array $devices = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = Site::factory()->create(['name' => 'Siège', 'code' => 'SIE']);
        $this->workshop = Site::factory()->create(['name' => 'Atelier', 'code' => 'ATE']);

        $building = Building::factory()->create(['site_id' => $this->office->id, 'name' => 'Bâtiment principal']);
        $ground = Floor::factory()->create(['building_id' => $building->id, 'level' => 0, 'name' => 'Rez-de-chaussée']);
        $first = Floor::factory()->create(['building_id' => $building->id, 'level' => 1, 'name' => '1er étage']);

        $this->rack = Room::factory()->technical()->create(['floor_id' => $ground->id, 'name' => 'Local technique']);
        $this->cupboard = Room::factory()->create(['floor_id' => $first->id, 'name' => 'Placard']);
    }

    /**
     * A device with so many members of so many ports.
     */
    private function device(string $name, DeviceType $type, ?Room $room = null, ?Site $site = null, Status $status = Status::Active, int $members = 1, int $ports = 6): Device
    {
        $device = Device::factory()->create([
            'site_id' => ($site ?? $this->office)->id,
            'room_id' => $room?->id,
            'name' => $name,
            'type' => $type,
            'status' => $status,
        ]);

        for ($position = 1; $position <= $members; $position++) {
            $member = DeviceMember::factory()->create(['device_id' => $device->id, 'position' => $position]);

            foreach (PortFactory::series($member, $ports) as $port) {
                $this->ports[$name][$port->name] = $port;
            }
        }

        return $this->devices[$name] = $device;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function link(string $device, string $port, string $other, string $otherPort, array $attributes = []): Link
    {
        return LinkFactory::between($this->ports[$device][$port], $this->ports[$other][$otherPort], $attributes);
    }

    /**
     * @return array<string, mixed>
     */
    private function diagram(?Site $site = null): array
    {
        return app(PhysicalDiagram::class)->of($site ?? $this->office);
    }

    /**
     * The devices drawn, by name.
     *
     * @param  array<string, mixed>  $diagram
     * @return array<string, array<string, mixed>>
     */
    private function devicesOf(array $diagram): array
    {
        return collect($diagram['devices'])->keyBy('name')->all();
    }

    /**
     * Each link drawn as "device › port → device › port".
     *
     * @param  array<string, mixed>  $diagram
     * @return list<string>
     */
    private function arrows(array $diagram): array
    {
        return array_map(fn (array $link): string => $link['ends'][0].' → '.$link['ends'][1], $diagram['links']);
    }

    // --- Which devices --------------------------------------------------------

    public function test_the_devices_that_make_the_network_are_drawn_and_any_other_one_that_has_a_link(): void
    {
        foreach ([['fw-siege', DeviceType::Firewall], ['rt-opérateur', DeviceType::Router], ['sw-coeur', DeviceType::Switch], ['ap-hall', DeviceType::AccessPoint]] as [$name, $type]) {
            $this->device($name, $type, $this->rack);
        }

        // A server linked to the core switch, and one without a link.
        $this->device('srv-fichiers', DeviceType::Server, $this->rack);
        $this->device('srv-sauvegarde', DeviceType::Server, $this->rack);
        $this->link('sw-coeur', '1/0/1', 'srv-fichiers', '1/0/1');

        // Retired: never drawn, nor its link. Planned: drawn, and said so.
        $this->device('sw-ancien', DeviceType::Switch, $this->rack, status: Status::Retired);
        $this->link('sw-coeur', '1/0/2', 'sw-ancien', '1/0/1');
        $this->device('sw-futur', DeviceType::Switch, $this->rack, status: Status::Planned);

        $diagram = $this->diagram();
        $devices = $this->devicesOf($diagram);

        $this->assertSame(['ap-hall', 'fw-siege', 'rt-opérateur', 'srv-fichiers', 'sw-coeur', 'sw-futur'], array_keys($devices));
        $this->assertSame(['Switch', false], [$devices['sw-coeur']['type_label'], $devices['sw-coeur']['planned']]);
        $this->assertTrue($devices['sw-futur']['planned']);
        $this->assertSame(['sw-coeur › 1/0/1 → srv-fichiers › 1/0/1'], $this->arrows($diagram));

        // Only the ports linked are drawn.
        $this->assertSame([['key' => 'port-'.$this->ports['sw-coeur']['1/0/1']->id, 'name' => '1/0/1']], $devices['sw-coeur']['ports']);
        $this->assertSame([], $devices['fw-siege']['ports']);

        // The summary: the device of the site that is not drawn, the retired
        // one left out.
        $this->assertSame(['devices' => 6, 'end_devices' => 0, 'undrawn' => 1, 'links' => 1, 'deviations' => 0], $diagram['summary']);
    }

    public function test_a_device_of_another_site_is_drawn_at_the_edge_with_its_site(): void
    {
        $this->device('sw-coeur', DeviceType::Switch, $this->rack);
        $this->device('sw-atelier', DeviceType::Switch, Room::factory()->inSite($this->workshop)->create(), $this->workshop);
        $this->device('fw-atelier', DeviceType::Firewall, null, $this->workshop);
        $this->link('sw-atelier', '1/0/1', 'sw-coeur', '1/0/6', ['medium' => LinkMedium::FiberSinglemode, 'status' => Status::Planned]);

        $diagram = $this->diagram();
        $devices = $this->devicesOf($diagram);

        // Its firewall, not linked to the site, is not drawn.
        $this->assertSame(['sw-atelier', 'sw-coeur'], array_keys($devices));
        $this->assertSame(['id' => $this->workshop->id, 'name' => 'Atelier'], $devices['sw-atelier']['site']);
        $this->assertNull($devices['sw-atelier']['place']);
        $this->assertNull($devices['sw-coeur']['site']);

        // A fibre that is planned, between two switches of one link each:
        // by name, whatever their site.
        $this->assertSame(['sw-atelier › 1/0/1 → sw-coeur › 1/0/6'], $this->arrows($diagram));
        $this->assertSame(
            ['fiber_singlemode', 'Fibre monomode', true, true],
            [$diagram['links'][0]['medium'], $diagram['links'][0]['medium_label'], $diagram['links'][0]['fibre'], $diagram['links'][0]['planned']],
        );

        // Seen from the workshop, the head office is at the edge.
        $this->assertSame(['id' => $this->office->id, 'name' => 'Siège'], $this->devicesOf($this->diagram($this->workshop))['sw-coeur']['site']);
        $this->assertSame(['sw-atelier › 1/0/1 → sw-coeur › 1/0/6'], $this->arrows($this->diagram($this->workshop)));
    }

    public function test_retired_links_and_links_to_retired_devices_are_left_out(): void
    {
        $this->device('sw-coeur', DeviceType::Switch, $this->rack);
        $this->device('sw-etage', DeviceType::Switch, $this->cupboard);
        $this->device('srv-ancien', DeviceType::Server, $this->rack, status: Status::Retired);
        $this->link('sw-coeur', '1/0/1', 'sw-etage', '1/0/1', ['status' => Status::Retired]);
        $this->link('sw-coeur', '1/0/2', 'srv-ancien', '1/0/1');
        $this->link('sw-coeur', '1/0/3', 'sw-etage', '1/0/2');

        $diagram = $this->diagram();

        $this->assertSame(['sw-coeur › 1/0/3 → sw-etage › 1/0/2'], $this->arrows($diagram));
        $this->assertSame(['sw-coeur', 'sw-etage'], array_keys($this->devicesOf($diagram)));
        $this->assertSame(0, $diagram['summary']['undrawn']);
    }

    public function test_a_stack_says_how_many_members_it_has(): void
    {
        $this->device('sw-serveurs', DeviceType::Switch, $this->rack, members: 2);
        $this->device('sw-coeur', DeviceType::Switch, $this->rack);

        $devices = $this->devicesOf($this->diagram());

        $this->assertSame([2, 1], [$devices['sw-serveurs']['members'], $devices['sw-coeur']['members']]);
    }

    // --- Which ports and which links ------------------------------------------

    public function test_an_aggregated_link_is_one_link_drawn_from_a_port_named_by_each_aggregate(): void
    {
        $this->device('sw-serveurs', DeviceType::Switch, $this->rack, members: 2);
        $this->device('sw-coeur', DeviceType::Switch, $this->rack);

        $stack = PortAggregate::factory()->create(['device_id' => $this->devices['sw-serveurs']->id, 'name' => 'Po1']);
        $core = PortAggregate::factory()->create(['device_id' => $this->devices['sw-coeur']->id, 'name' => 'Po2']);

        foreach ([$this->ports['sw-serveurs']['1/0/6'], $this->ports['sw-serveurs']['2/0/6']] as $port) {
            $port->update(['port_aggregate_id' => $stack->id]);
        }

        foreach ([$this->ports['sw-coeur']['1/0/5'], $this->ports['sw-coeur']['1/0/6']] as $port) {
            $port->update(['port_aggregate_id' => $core->id]);
        }

        // Its two links, from either end; and a third link of its own.
        $this->link('sw-serveurs', '1/0/6', 'sw-coeur', '1/0/5', ['medium' => LinkMedium::DirectAttach]);
        $this->link('sw-coeur', '1/0/6', 'sw-serveurs', '2/0/6', ['medium' => LinkMedium::DirectAttach]);
        $this->link('sw-serveurs', '1/0/1', 'sw-coeur', '1/0/1');

        $diagram = $this->diagram();
        $devices = $this->devicesOf($diagram);

        $this->assertCount(2, $diagram['links']);

        $aggregated = collect($diagram['links'])->firstWhere('links_count', 2);

        $this->assertIsArray($aggregated);
        $this->assertSame('aggregate-'.$stack->id, $aggregated['source_port'] === 'aggregate-'.$stack->id ? $aggregated['source_port'] : $aggregated['target_port']);
        $this->assertSame(['1/0/1', 'Po1'], array_column($devices['sw-serveurs']['ports'], 'name'));
        $this->assertSame(['1/0/1', 'Po2'], array_column($devices['sw-coeur']['ports'], 'name'));
        $this->assertSame(['direct_attach', false], [$aggregated['medium'], $aggregated['fibre']]);

        // Read from the one that has the more links: both have two.
        $this->assertSame('sw-coeur › Po2 → sw-serveurs › Po1', $aggregated['ends'][0].' → '.$aggregated['ends'][1]);
        $this->assertSame('Po2 ↔ Po1 · 2 liens', $aggregated['label']);
        $this->assertSame(['links' => 2], ['links' => $diagram['summary']['links']]);
    }

    // --- Which way ------------------------------------------------------------

    public function test_links_are_read_from_top_to_bottom(): void
    {
        $this->device('fw-siege', DeviceType::Firewall, $this->rack);
        $this->device('sw-coeur-01', DeviceType::Switch, $this->rack);
        $this->device('sw-coeur-02', DeviceType::Switch, $this->rack);
        $this->device('sw-etage', DeviceType::Switch, $this->cupboard);
        $this->device('ap-couloir', DeviceType::AccessPoint, $this->cupboard);
        $this->device('srv-fichiers', DeviceType::Server, $this->rack);
        $this->device('fw-atelier', DeviceType::Firewall, null, $this->workshop);

        // Each one given from the end that would be read last.
        $this->link('sw-coeur-01', '1/0/1', 'fw-siege', '1/0/1');
        $this->link('sw-coeur-02', '1/0/1', 'fw-siege', '1/0/2');
        $this->link('ap-couloir', '1/0/1', 'sw-etage', '1/0/1');
        $this->link('srv-fichiers', '1/0/1', 'fw-siege', '1/0/3');
        // Between two switches: the one with the more links first; equal,
        // by name.
        $this->link('sw-etage', '1/0/2', 'sw-coeur-02', '1/0/2');
        $this->link('sw-coeur-02', '1/0/3', 'sw-coeur-01', '1/0/2');
        // A firewall of another site, above the switch of the site.
        $this->link('sw-coeur-01', '1/0/3', 'fw-atelier', '1/0/1');

        // In the order of their ends, by name.
        $this->assertSame([
            'fw-atelier › 1/0/1 → sw-coeur-01 › 1/0/3',
            'fw-siege › 1/0/1 → sw-coeur-01 › 1/0/1',
            'fw-siege › 1/0/2 → sw-coeur-02 › 1/0/1',
            'fw-siege › 1/0/3 → srv-fichiers › 1/0/1',
            'sw-coeur-01 › 1/0/2 → sw-coeur-02 › 1/0/3',
            'sw-coeur-02 › 1/0/2 → sw-etage › 1/0/2',
            'sw-etage › 1/0/1 → ap-couloir › 1/0/1',
        ], $this->arrows($this->diagram()));

        // One more link on sw-coeur-02: it now has the more links of the two.
        $this->device('sw-quai', DeviceType::Switch, $this->rack);
        $this->link('sw-coeur-02', '1/0/4', 'sw-quai', '1/0/1');

        $this->assertContains('sw-coeur-02 › 1/0/3 → sw-coeur-01 › 1/0/2', $this->arrows($this->diagram()));
    }

    public function test_a_firewall_of_another_site_linked_to_a_switch_of_the_site_stands_at_the_top(): void
    {
        $this->device('fw-siege', DeviceType::Firewall, $this->rack);
        $this->device('sw-coeur-01', DeviceType::Switch, $this->rack);
        $this->device('fw-atelier', DeviceType::Firewall, null, $this->workshop);

        // Each one given from the switch of the site.
        $this->link('sw-coeur-01', '1/0/1', 'fw-siege', '1/0/1');
        $this->link('sw-coeur-01', '1/0/2', 'fw-atelier', '1/0/1');

        $diagram = $this->diagram();
        $key = $this->devicesOf($diagram)['fw-atelier']['key'];

        // Of the highest rank, as the firewall of the site: only links that
        // leave it, none that reaches it.
        $this->assertSame([
            'fw-atelier › 1/0/1 → sw-coeur-01 › 1/0/2',
            'fw-siege › 1/0/1 → sw-coeur-01 › 1/0/1',
        ], $this->arrows($diagram));
        $this->assertNotContains($key, array_column($diagram['links'], 'target'));

        // The same on the diagram of its own site, the switch at the edge.
        $this->assertSame(['fw-atelier › 1/0/1 → sw-coeur-01 › 1/0/2'], $this->arrows($this->diagram($this->workshop)));
    }

    public function test_between_two_devices_of_one_rank_every_link_counts_even_out_of_the_diagram(): void
    {
        $this->device('fw-siege', DeviceType::Firewall, $this->rack);
        $this->device('sw-coeur-01', DeviceType::Switch, $this->rack);
        $this->device('sw-quai', DeviceType::Switch, null, $this->workshop);
        $this->device('ap-quai-1', DeviceType::AccessPoint, null, $this->workshop);
        $this->device('ap-quai-2', DeviceType::AccessPoint, null, $this->workshop);

        $this->link('fw-siege', '1/0/1', 'sw-coeur-01', '1/0/1');
        $this->link('sw-coeur-01', '1/0/2', 'sw-quai', '1/0/1');
        // Two links of the switch of the workshop on its own site, out of
        // the diagram of the head office: three links against two.
        $this->link('sw-quai', '1/0/2', 'ap-quai-1', '1/0/1');
        $out = $this->link('sw-quai', '1/0/3', 'ap-quai-2', '1/0/1');

        $this->assertContains('sw-quai › 1/0/1 → sw-coeur-01 › 1/0/2', $this->arrows($this->diagram()));
        $this->assertCount(3, $this->diagram()['devices']);

        // A retired link no longer counts: two against two, then by name.
        $out->update(['status' => Status::Retired]);

        $this->assertContains('sw-coeur-01 › 1/0/2 → sw-quai › 1/0/1', $this->arrows($this->diagram()));
    }

    // --- Where ----------------------------------------------------------------

    public function test_each_device_is_in_its_room_its_floor_and_its_building_or_without_a_room(): void
    {
        $annex = Building::factory()->create(['site_id' => $this->office->id, 'name' => 'Annexe']);
        $annexFloor = Floor::factory()->create(['building_id' => $annex->id, 'level' => 0, 'name' => 'Rez-de-chaussée']);
        $annexRoom = Room::factory()->create(['floor_id' => $annexFloor->id, 'name' => 'Bureau']);
        // A room without any device drawn is left out.
        Room::factory()->create(['floor_id' => $annexFloor->id, 'name' => 'Vide']);

        $this->device('sw-etage', DeviceType::Switch, $this->cupboard);
        $this->device('sw-coeur', DeviceType::Switch, $this->rack, members: 2);
        $this->device('ap-annexe', DeviceType::AccessPoint, $annexRoom);
        $this->device('sw-volant', DeviceType::Switch);

        // A member kept elsewhere: the device stays in its own room.
        DeviceMember::query()->where('device_id', $this->devices['sw-coeur']->id)->where('position', 2)->update(['room_id' => $this->cupboard->id]);

        $diagram = $this->diagram();
        $devices = $this->devicesOf($diagram);

        $this->assertSame('room-'.$this->rack->id, $devices['sw-coeur']['place']);
        $this->assertSame('room-'.$this->cupboard->id, $devices['sw-etage']['place']);
        $this->assertSame('unplaced', $devices['sw-volant']['place']);

        $shape = fn (array $place): array => [
            $place['name'],
            ...($place['kind'] === 'room' ? [$place['technical']] : []),
            ...array_map(fn (array $child): array => [$child['name'], ...array_map(fn (array $room): array => [$room['name'], $room['technical']], $child['children'])], $place['children']),
        ];

        $this->assertSame(
            [
                ['Annexe', ['Rez-de-chaussée', ['Bureau', false]]],
                ['Bâtiment principal', ['Rez-de-chaussée', ['Local technique', true]], ['1er étage', ['Placard', false]]],
                ['Sans local'],
            ],
            array_map($shape, $diagram['places']),
        );
        $this->assertSame(['building', 'floor', 'room'], [$diagram['places'][1]['kind'], $diagram['places'][1]['children'][0]['kind'], $diagram['places'][1]['children'][0]['children'][0]['kind']]);
        $this->assertSame('unplaced', $diagram['places'][2]['key']);
    }

    // --- Order ----------------------------------------------------------------

    public function test_the_same_data_always_gives_the_same_diagram_in_the_order_of_the_names(): void
    {
        // Created out of order.
        $this->device('sw-10', DeviceType::Switch, $this->rack, ports: 12);
        $this->device('sw-2', DeviceType::Switch, $this->rack, ports: 12);
        $this->device('SW-1', DeviceType::Switch, $this->rack, ports: 12);
        $this->link('sw-2', '1/0/10', 'sw-10', '1/0/1');
        $this->link('sw-2', '1/0/2', 'SW-1', '1/0/1');
        $this->link('sw-10', '1/0/9', 'SW-1', '1/0/11');

        $diagram = $this->diagram();

        $this->assertSame(['SW-1', 'sw-2', 'sw-10'], array_column($diagram['devices'], 'name'));
        $this->assertSame(['1/0/2', '1/0/10'], array_column($this->devicesOf($diagram)['sw-2']['ports'], 'name'));
        $this->assertSame([
            'SW-1 › 1/0/1 → sw-2 › 1/0/2',
            'SW-1 › 1/0/11 → sw-10 › 1/0/9',
            'sw-2 › 1/0/10 → sw-10 › 1/0/1',
        ], $this->arrows($diagram));
        $this->assertSame($diagram, $this->diagram());
    }

    // --- Deviations -----------------------------------------------------------

    public function test_the_deviations_of_a_link_are_on_the_link_and_those_of_a_device_on_the_device(): void
    {
        $this->device('sw-coeur', DeviceType::Switch, $this->rack);
        $this->device('sw-etage', DeviceType::Switch, $this->cupboard);
        $this->device('fw-siege', DeviceType::Firewall, $this->rack);

        $vlans = [];

        foreach ([[$this->office, 10], [$this->office, 20], [$this->workshop, 10], [$this->workshop, 60]] as [$site, $vid]) {
            $vlans[$site->name][$vid] = Vlan::factory()->create(['site_id' => $site->id, 'vid' => $vid]);
        }

        $carry = function (Port $port, PortMode $mode, array $tagged): void {
            $port->update(['mode' => $mode]);

            foreach ($tagged as $vlan) {
                PortVlan::factory()->create(['port_id' => $port->id, 'vlan_id' => $vlan->id, 'tagged' => true]);
            }
        };

        // A link whose two ends do not tag the same VLANs.
        $carry($this->ports['sw-coeur']['1/0/1'], PortMode::Trunk, [$vlans['Siège'][10], $vlans['Siège'][20]]);
        $carry($this->ports['sw-etage']['1/0/1'], PortMode::Trunk, [$vlans['Siège'][10]]);
        $this->link('sw-coeur', '1/0/1', 'sw-etage', '1/0/1');

        // A switch that merges the VLAN 10 of the two sites.
        $carry($this->ports['sw-etage']['1/0/2'], PortMode::Trunk, [$vlans['Atelier'][10]]);

        // An address of the firewall on a network of the workshop.
        $subnet = $this->subnet('10.20.60.0/24', ['site_id' => $this->workshop->id, 'vlan_id' => $vlans['Atelier'][60]->id]);
        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.20.60.1', 'device_id' => $this->devices['fw-siege']->id]);

        $diagram = $this->diagram();
        $devices = $this->devicesOf($diagram);

        $this->assertSame([['kind' => 'link_tagged_vlans', 'reason' => 'VLAN 20 seulement sur sw-coeur']], $diagram['links'][0]['deviations']);
        $this->assertSame([['kind' => 'switch_merges_vlans', 'reason' => 'VLAN 10 des sites Siège et Atelier : sw-etage les fusionne']], $devices['sw-etage']['deviations']);
        $this->assertSame([['kind' => 'address_on_device_of_another_site', 'reason' => '10.20.60.1 : Sous-réseau du site Atelier, équipement du site Siège']], $devices['fw-siege']['deviations']);
        $this->assertSame([], $devices['sw-coeur']['deviations']);
        $this->assertSame(3, $diagram['summary']['deviations']);

        // The firewall that carries the VLAN of the subnet is plugged into it.
        $carry($this->ports['fw-siege']['1/0/6'], PortMode::Trunk, [$vlans['Atelier'][60]]);

        $this->assertSame([], $this->devicesOf($this->diagram())['fw-siege']['deviations']);
    }

    // --- The page -------------------------------------------------------------

    public function test_the_page_opens_on_the_first_site_by_name_whose_number_is_in_its_address(): void
    {
        $reader = $this->reader();

        $this->actingAs($reader)
            ->get(route('diagrams.physical'))
            ->assertRedirect(route('diagrams.physical', ['site' => $this->workshop->id]));

        $this->device('sw-coeur', DeviceType::Switch, $this->rack);

        $this->actingAs($reader)
            ->get(route('diagrams.physical', ['site' => $this->office->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('diagrams/Physical')
                ->where('sites', [
                    ['id' => $this->workshop->id, 'name' => 'Atelier'],
                    ['id' => $this->office->id, 'name' => 'Siège'],
                ])
                ->where('site', ['id' => $this->office->id, 'name' => 'Siège', 'code' => 'SIE'])
                ->where('diagram.devices.0.name', 'sw-coeur')
                ->where('diagram.summary', ['devices' => 1, 'end_devices' => 0, 'undrawn' => 0, 'links' => 0, 'deviations' => 0])
                // A reader is not offered to create a device.
                ->where('canCreateDevice', false));

        $this->actingAs($this->editor())
            ->get(route('diagrams.physical', ['site' => $this->office->id]))
            ->assertInertia(fn (Assert $page) => $page->where('canCreateDevice', true));

        // A site that is gone: a page not found, its link may have been
        // shared.
        $this->actingAs($reader)->get(route('diagrams.physical', ['site' => 999999]))->assertNotFound();
    }

    public function test_without_any_site_the_page_says_so(): void
    {
        Room::query()->delete();
        Floor::query()->delete();
        Building::query()->delete();
        Site::query()->delete();

        $this->actingAs($this->reader())
            ->get(route('diagrams.physical'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('sites', [])->where('site', null)->where('diagram', null));
    }

    public function test_the_diagram_is_read_by_every_role_and_by_nobody_else(): void
    {
        foreach ([$this->reader(), $this->editor(), $this->admin()] as $user) {
            $this->actingAs($user)->get(route('diagrams.physical', ['site' => $this->office->id]))->assertOk();
        }

        // An account that is disabled, then nobody.
        $this->actingAs(User::factory()->reader()->inactive()->create())
            ->get(route('diagrams.physical', ['site' => $this->office->id]))
            ->assertRedirect(route('login'));

        auth()->logout();

        $this->get(route('diagrams.physical', ['site' => $this->office->id]))->assertRedirect(route('login'));
    }

    public function test_the_seeder_of_the_browser_test_of_volume_adds_its_site_then_takes_it_away(): void
    {
        $this->seed(DiagramVolumeSeeder::class);

        $site = Site::query()->where('name', DiagramVolumeSeeder::SITE)->sole();

        $this->assertSame(['devices' => 150, 'end_devices' => 0, 'undrawn' => 0, 'links' => 300, 'deviations' => 0], $this->diagram($site)['summary']);

        putenv('DIAGRAM_VOLUME=remove');

        try {
            $this->seed(DiagramVolumeSeeder::class);
        } finally {
            putenv('DIAGRAM_VOLUME');
        }

        $this->assertNull(Site::query()->where('name', DiagramVolumeSeeder::SITE)->first());
        $this->assertSame(0, Device::query()->count());
        $this->assertSame(0, Link::query()->count());
    }

    // --- Volume ---------------------------------------------------------------

    public function test_the_page_of_the_diagram_costs_the_same_whatever_the_number_of_devices_and_links(): void
    {
        $reader = $this->reader();
        $queries = 0;

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries++;
        });

        // The page, as a reader opens it.
        $cost = function () use (&$queries, $reader): int {
            $queries = 0;
            $this->actingAs($reader)->get(route('diagrams.physical', ['site' => $this->office->id]))->assertOk();

            return $queries;
        };

        // A little of everything the diagram reads: a stack in a room and a
        // switch in none, an aggregated link, a device of another site, a
        // VLAN carried and an address.
        $this->device('fw-siege', DeviceType::Firewall, $this->rack, ports: 4);
        $this->device('sw-pile', DeviceType::Switch, $this->rack, members: 2, ports: 4);
        $this->device('sw-volant', DeviceType::Switch, ports: 4);
        $this->device('sw-atelier', DeviceType::Switch, null, $this->workshop, ports: 4);

        $pile = PortAggregate::factory()->create(['device_id' => $this->devices['sw-pile']->id, 'name' => 'Po1']);
        $free = PortAggregate::factory()->create(['device_id' => $this->devices['sw-volant']->id, 'name' => 'Po1']);
        $this->ports['sw-pile']['1/0/4']->update(['port_aggregate_id' => $pile->id, 'mode' => PortMode::Trunk]);
        $this->ports['sw-volant']['1/0/4']->update(['port_aggregate_id' => $free->id]);
        $this->link('sw-pile', '1/0/4', 'sw-volant', '1/0/4');
        $this->link('fw-siege', '1/0/1', 'sw-pile', '2/0/1');
        $this->link('sw-volant', '1/0/1', 'sw-atelier', '1/0/1');

        $vlan = Vlan::factory()->create(['site_id' => $this->office->id, 'vid' => 10]);
        PortVlan::factory()->create(['port_id' => $this->ports['sw-pile']['1/0/1']->id, 'vlan_id' => $vlan->id, 'tagged' => false]);
        PortVlan::factory()->create(['port_aggregate_id' => $pile->id, 'vlan_id' => $vlan->id, 'tagged' => true]);
        IpAddress::factory()->create(['subnet_id' => $this->subnet('10.10.10.0/24', ['site_id' => $this->office->id, 'vlan_id' => $vlan->id])->id, 'address' => '10.10.10.1', 'device_id' => $this->devices['fw-siege']->id]);

        $few = $cost();

        // Placed by hand, one device of them: the layout is read with the
        // page.
        $place = fn (Device $device, int $index): array => [
            'site_id' => $this->office->id, 'view' => 'physical', 'device_id' => $device->id,
            'x' => 8 * $index, 'y' => 0, 'created_at' => now(), 'updated_at' => now(),
        ];

        DiagramPosition::query()->insert([$place($this->devices['fw-siege'], 0)]);

        $fewPlaced = $cost();

        DiagramPosition::query()->delete();

        // Then a hundred and fifty devices, joined by three hundred links.
        foreach (range(1, 146) as $number) {
            $this->device('sw-'.$number, DeviceType::Switch, $number % 2 === 0 ? $this->rack : $this->cupboard, ports: 6);
        }

        $names = array_map(fn (int $number): string => 'sw-'.$number, range(1, 146));

        foreach ($names as $index => $name) {
            $this->link($name, '1/0/1', $names[($index + 1) % 146], '1/0/2');
            $this->link($name, '1/0/3', $names[($index + 2) % 146], '1/0/4');

            if ($index < 5) {
                $this->link($name, '1/0/5', $names[$index + 3], '1/0/6');
            }
        }

        $diagram = $this->diagram();

        $this->assertSame(150, $diagram['summary']['devices']);
        $this->assertSame(300, $diagram['summary']['links']);
        $this->assertSame(300, Link::query()->count());

        $this->assertSame($few, $cost());

        // All of them placed by hand: as many queries as with one.
        DiagramPosition::query()->insert(array_map($place, array_values($this->devices), array_keys(array_values($this->devices))));

        $this->assertSame(150, DiagramPosition::query()->count());
        $this->assertSame($fewPlaced, $cost());
    }
}
