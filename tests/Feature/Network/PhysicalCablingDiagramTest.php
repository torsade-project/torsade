<?php

// torsade/tests/Feature/Network/PhysicalCablingDiagramTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\DeviceType;
use App\Enums\DiagramView;
use App\Enums\Status;
use App\Models\Building;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\DiagramPosition;
use App\Models\Floor;
use App\Models\IpAddress;
use App\Models\Jack;
use App\Models\PatchPanel;
use App\Models\PatchPanelPort;
use App\Models\Port;
use App\Models\PortAggregate;
use App\Models\Room;
use App\Models\Site;
use App\Models\Subnet;
use App\Services\Network\PhysicalDiagram;
use Database\Factories\JackCordFactory;
use Database\Factories\LinkFactory;
use Database\Factories\PatchCordFactory;
use Database\Factories\PortFactory;
use Database\Seeders\DiagramVolumeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The cabling on the physical diagram of a site: a connection that has a
 * cabled path is drawn in two segments through the port of its panel, the
 * jack naming the second one; the panels it goes through are drawn with
 * the ports used; the devices a cabled path joins to a device drawn are
 * drawn, step by step; the end devices are told apart; the panels are
 * placed by hand as the devices are.
 */
class PhysicalCablingDiagramTest extends NetworkTestCase
{
    private Site $office;

    private Floor $floor;

    private Room $rack;

    private Room $offices;

    private PatchPanel $panel;

    private Device $switch;

    /**
     * The ports of each device, by the name of the device, then of the port.
     *
     * @var array<string, array<string, Port>>
     */
    private array $ports = [];

    /**
     * The jacks B.01 to B.12, on the ports 1 to 12 of the panel.
     *
     * @var array<string, Jack>
     */
    private array $jacks = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = Site::factory()->create(['name' => 'Siège']);

        $building = Building::factory()->create(['site_id' => $this->office->id, 'name' => 'Bâtiment principal']);
        $this->floor = Floor::factory()->create(['building_id' => $building->id, 'level' => 1, 'name' => '1er étage']);
        $this->rack = Room::factory()->technical()->create(['floor_id' => $this->floor->id, 'name' => 'Placard technique']);
        $this->offices = Room::factory()->create(['floor_id' => $this->floor->id, 'name' => 'Bureaux']);
        $this->panel = PatchPanel::factory()->create(['room_id' => $this->rack->id, 'name' => 'PB-B', 'port_count' => 24]);

        foreach (range(1, 12) as $number) {
            $label = sprintf('B.%02d', $number);
            $this->jacks[$label] = Jack::factory()->create(['floor_id' => $this->floor->id, 'label' => $label, 'patch_panel_port_id' => $this->panelPort($number)->id]);
        }

        $this->switch = $this->device('sw-etage-1', DeviceType::Switch, $this->rack, ports: 12);
    }

    private function panelPort(int $number, ?PatchPanel $panel = null): PatchPanelPort
    {
        return PatchPanelPort::query()->where('patch_panel_id', ($panel ?? $this->panel)->id)->where('number', $number)->sole();
    }

    private function device(string $name, DeviceType $type, ?Room $room = null, ?Site $site = null, int $ports = 2, Status $status = Status::Active): Device
    {
        $device = Device::factory()->create([
            'site_id' => ($site ?? $this->office)->id,
            'room_id' => $room?->id,
            'name' => $name,
            'type' => $type,
            'status' => $status,
        ]);

        foreach (PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $device->id]), $ports) as $port) {
            $this->ports[$name][$port->name] = $port;
        }

        return $device;
    }

    private function port(string $device, string $name = '1/0/1'): Port
    {
        return $this->ports[$device][$name];
    }

    /**
     * A port patched to a jack, through its port of the panel.
     */
    private function patchTo(string $device, string $port, string $jack): void
    {
        PatchCordFactory::between($this->jacks[$jack]->panelPort()->sole(), $this->port($device, $port));
    }

    private function plug(string $jack, string $device, string $port = '1/0/1'): void
    {
        JackCordFactory::between($this->jacks[$jack], $this->port($device, $port));
    }

    private function address(string $jack, Device $device, string $address): void
    {
        $subnet = Subnet::query()->first() ?? Subnet::factory()->cidr('10.10.10.0/24')->create(['site_id' => $this->office->id]);

        IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => $address, 'jack_id' => $this->jacks[$jack]->id, 'device_id' => $device->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function diagram(): array
    {
        return app(PhysicalDiagram::class)->of($this->office);
    }

    /**
     * The lines drawn, by key.
     *
     * @param  array<string, mixed>  $diagram
     * @return array<string, array<string, mixed>>
     */
    private function lines(array $diagram): array
    {
        return collect($diagram['links'])->keyBy('key')->all();
    }

    /**
     * The devices drawn, by name.
     *
     * @param  array<string, mixed>  $diagram
     * @return array<string, array<string, mixed>>
     */
    private function devices(array $diagram): array
    {
        return collect($diagram['devices'])->keyBy('name')->all();
    }

    // --- A link and its cabling ----------------------------------------------------

    public function test_a_link_whose_cabling_is_known_is_drawn_in_two_segments_through_the_port_of_its_panel(): void
    {
        $this->device('ap-salle', DeviceType::AccessPoint, $this->offices);
        $this->patchTo('sw-etage-1', '1/0/1', 'B.01');
        $this->plug('B.01', 'ap-salle');
        $link = LinkFactory::between($this->port('sw-etage-1'), $this->port('ap-salle'));

        $diagram = $this->diagram();
        $lines = $this->lines($diagram);
        $panelPort = $this->panelPort(1);
        $key = 'link-'.$link->id;

        // No direct line: the switch to the port of the panel, then the port
        // of the panel to the access point, named by the jack.
        $this->assertSame([$key.'-office', $key.'-rack'], array_keys($lines));
        $this->assertSame(
            [
                'connection' => $key, 'segment' => 'rack',
                'source' => 'device-'.$this->switch->id, 'source_port' => 'port-'.$this->port('sw-etage-1')->id,
                'target' => 'patch_panel-'.$this->panel->id, 'target_port' => 'panel-port-'.$panelPort->id,
                'ends' => ['sw-etage-1 › 1/0/1', 'ap-salle › 1/0/1'], 'jack' => null, 'through' => 'PB-B 1 › B.01', 'links_count' => 1,
            ],
            collect($lines[$key.'-rack'])->only(['connection', 'segment', 'source', 'source_port', 'target', 'target_port', 'ends', 'jack', 'through', 'links_count'])->all(),
        );
        $this->assertSame(
            ['source' => 'patch_panel-'.$this->panel->id, 'source_port' => 'panel-port-'.$panelPort->id, 'target' => 'device-'.$this->port('ap-salle')->device_id, 'target_port' => 'port-'.$this->port('ap-salle')->id, 'jack' => 'B.01'],
            collect($lines[$key.'-office'])->only(['source', 'source_port', 'target', 'target_port', 'jack'])->all(),
        );

        // The panel, in the frame of its room, with the port used alone.
        $this->assertSame(
            [['key' => 'patch_panel-'.$this->panel->id, 'id' => $this->panel->id, 'name' => 'PB-B', 'planned' => false, 'place' => 'room-'.$this->rack->id, 'ports' => [['key' => 'panel-port-'.$panelPort->id, 'name' => '1']]]],
            $diagram['panels'],
        );
        $this->assertSame(['room-'.$this->offices->id, 'room-'.$this->rack->id], array_column($diagram['places'][0]['children'][0]['children'], 'key'));

        // One link, nothing that differs.
        $this->assertSame(['devices' => 2, 'end_devices' => 0, 'undrawn' => 0, 'links' => 1, 'deviations' => 0], $diagram['summary']);
    }

    public function test_a_cabling_without_a_link_draws_the_device_plugged_in_with_its_deviation(): void
    {
        $workstation = $this->device('pc-compta', DeviceType::Workstation, $this->offices, ports: 1);
        $this->patchTo('sw-etage-1', '1/0/2', 'B.02');
        $this->plug('B.02', 'pc-compta');

        $diagram = $this->diagram();
        $lines = $this->lines($diagram);
        $key = 'cabling-'.$this->jacks['B.02']->id;

        // The workstation has no link: drawn because it is cabled to the
        // switch, as an end device.
        $this->assertTrue($this->devices($diagram)['pc-compta']['end_device']);
        $this->assertFalse($this->devices($diagram)['sw-etage-1']['end_device']);

        $this->assertSame([
            [
                'kind' => 'cabling_without_link',
                'reason' => 'sw-etage-1 1/0/2 et pc-compta 1/0/1 sont câblés par PB-B 2 › B.02, sans liaison',
                'action' => ['type' => 'create_link', 'device' => $this->switch->id, 'port' => $this->port('sw-etage-1', '1/0/2')->id, 'opposite_device' => $workstation->id, 'opposite_port' => $this->port('pc-compta')->id],
            ],
        ], $lines[$key.'-rack']['deviations']);
        $this->assertSame([], $lines[$key.'-office']['deviations']);
        $this->assertSame('B.02', $lines[$key.'-office']['jack']);
        $this->assertSame(0, $lines[$key.'-rack']['links_count']);

        $this->assertSame(['devices' => 1, 'end_devices' => 1, 'undrawn' => 0, 'links' => 0, 'deviations' => 1], $diagram['summary']);
    }

    public function test_the_cabling_of_a_retired_link_is_drawn_with_its_deviation_and_the_link_is_not(): void
    {
        $this->device('ap-salle', DeviceType::AccessPoint, $this->offices);
        $this->patchTo('sw-etage-1', '1/0/3', 'B.03');
        $this->plug('B.03', 'ap-salle');
        $link = LinkFactory::between($this->port('sw-etage-1', '1/0/3'), $this->port('ap-salle'), ['status' => Status::Retired]);

        $lines = $this->lines($this->diagram());
        $key = 'cabling-'.$this->jacks['B.03']->id;

        $this->assertArrayNotHasKey('link-'.$link->id, $lines);
        $this->assertSame([$key.'-office', $key.'-rack'], array_keys($lines));
        $this->assertSame(['cabling_of_retired_link'], array_column($lines[$key.'-rack']['deviations'], 'kind'));
    }

    public function test_a_link_the_cabling_contradicts_keeps_its_direct_line_its_cabling_beside_it_counted_once(): void
    {
        $this->device('ap-1', DeviceType::AccessPoint, $this->offices);
        $this->device('ap-2', DeviceType::AccessPoint, $this->offices);
        $this->patchTo('sw-etage-1', '1/0/4', 'B.04');
        $this->plug('B.04', 'ap-1');
        $link = LinkFactory::between($this->port('sw-etage-1', '1/0/4'), $this->port('ap-2'));

        $diagram = $this->diagram();
        $lines = $this->lines($diagram);
        $cabling = 'cabling-'.$this->jacks['B.04']->id;
        $reason = 'Le câblage de sw-etage-1 1/0/4 mène à ap-1 1/0/1 ; la liaison, à ap-2 1/0/1';

        $this->assertSame('direct', $lines['link-'.$link->id]['segment']);
        $this->assertContains(['kind' => 'link_contradicted_by_cabling', 'reason' => $reason], $lines['link-'.$link->id]['deviations']);
        $this->assertSame([['kind' => 'link_contradicted_by_cabling', 'reason' => $reason]], $lines[$cabling.'-rack']['deviations']);

        // The same deviation, drawn twice, counted once.
        $this->assertSame(count($lines['link-'.$link->id]['deviations']), $diagram['summary']['deviations']);
    }

    // --- Devices cabled by their address -----------------------------------------------

    public function test_the_devices_cabled_by_their_address_are_drawn_and_one_of_another_site_at_the_edge(): void
    {
        $workshop = Site::factory()->create(['name' => 'Atelier']);
        $printer = $this->device('imp-compta', DeviceType::Printer, ports: 0);
        $laptop = $this->device('pc-portable', DeviceType::Laptop, site: $workshop, ports: 0);

        $this->patchTo('sw-etage-1', '1/0/5', 'B.05');
        $this->address('B.05', $printer, '10.10.10.21');
        $this->address('B.05', $laptop, '10.10.10.22');

        $diagram = $this->diagram();
        $lines = $this->lines($diagram);
        $devices = $this->devices($diagram);
        $key = 'addressed-'.$this->jacks['B.05']->id;

        $this->assertSame('unplaced', $devices['imp-compta']['place']);
        $this->assertNull($devices['pc-portable']['place']);
        $this->assertSame('Atelier', $devices['pc-portable']['site']['name'] ?? null);
        $this->assertSame([], $devices['imp-compta']['ports']);

        // One segment to the panel, one from the panel to the edge of each
        // device: no port.
        $this->assertSame([$key.'-office-'.$printer->id, $key.'-office-'.$laptop->id, $key.'-rack'], array_keys($lines));
        $this->assertSame(['device-'.$printer->id, null, 'B.05'], [$lines[$key.'-office-'.$printer->id]['target'], $lines[$key.'-office-'.$printer->id]['target_port'], $lines[$key.'-office-'.$printer->id]['jack']]);
        $this->assertSame(['sw-etage-1 › 1/0/5', 'imp-compta, pc-portable'], $lines[$key.'-rack']['ends']);
        $this->assertSame(['devices' => 1, 'end_devices' => 2, 'undrawn' => 0, 'links' => 0, 'deviations' => 0], $diagram['summary']);
    }

    // --- An aggregated link ---------------------------------------------------------

    public function test_an_aggregated_link_stays_one_line_that_tells_the_cabled_path_of_each_link_and_its_panel_is_not_drawn(): void
    {
        $other = $this->device('sw-salle', DeviceType::Switch, $this->offices);
        $near = PortAggregate::factory()->create(['device_id' => $this->switch->id, 'name' => 'Po1']);
        $far = PortAggregate::factory()->create(['device_id' => $other->id, 'name' => 'Po1']);

        foreach ([['1/0/6', 'B.06', '1/0/1'], ['1/0/7', 'B.07', '1/0/2']] as [$port, $jack, $farPort]) {
            $this->port('sw-etage-1', $port)->update(['port_aggregate_id' => $near->id]);
            $this->port('sw-salle', $farPort)->update(['port_aggregate_id' => $far->id]);
            $this->patchTo('sw-etage-1', $port, $jack);
            $this->plug($jack, 'sw-salle', $farPort);
            LinkFactory::between($this->port('sw-etage-1', $port), $this->port('sw-salle', $farPort));
        }

        $diagram = $this->diagram();
        $lines = array_values($diagram['links']);

        $this->assertCount(1, $lines);
        $this->assertSame('direct', $lines[0]['segment']);
        $this->assertSame(['1/0/6 ↔ 1/0/1 par PB-B 6 › B.06', '1/0/7 ↔ 1/0/2 par PB-B 7 › B.07'], $lines[0]['paths']);
        $this->assertSame([], $diagram['panels']);
    }

    // --- What is drawn ---------------------------------------------------------------

    public function test_the_devices_a_cabled_path_joins_to_a_device_drawn_are_drawn_step_by_step(): void
    {
        // A server cabled to the switch, a workstation cabled to the server;
        // two workstations cabled to each other, joined to nothing drawn.
        $this->device('srv-compta', DeviceType::Server, $this->rack);
        $this->device('pc-1', DeviceType::Workstation, $this->offices, ports: 1);
        $this->device('pc-2', DeviceType::Workstation, $this->offices, ports: 1);
        $this->device('pc-3', DeviceType::Workstation, $this->offices, ports: 1);

        $this->patchTo('sw-etage-1', '1/0/8', 'B.08');
        $this->plug('B.08', 'srv-compta');
        $this->patchTo('srv-compta', '1/0/2', 'B.09');
        $this->plug('B.09', 'pc-1');
        $this->patchTo('pc-2', '1/0/1', 'B.10');
        $this->plug('B.10', 'pc-3');

        $diagram = $this->diagram();

        $this->assertSame(['pc-1', 'srv-compta', 'sw-etage-1'], array_keys($this->devices($diagram)));
        $this->assertSame(2, $diagram['summary']['undrawn']);
        $this->assertSame(['8', '9'], array_column($diagram['panels'][0]['ports'], 'name'));
    }

    public function test_a_planned_panel_is_told_so_and_a_retired_one_cuts_the_path(): void
    {
        $this->device('pc-1', DeviceType::Workstation, $this->offices, ports: 1);
        $this->patchTo('sw-etage-1', '1/0/1', 'B.01');
        $this->plug('B.01', 'pc-1');

        $this->panel->update(['status' => Status::Planned]);

        $this->assertTrue($this->diagram()['panels'][0]['planned']);

        $this->panel->update(['status' => Status::Retired]);

        $diagram = $this->diagram();

        $this->assertSame([], $diagram['panels']);
        $this->assertSame([], $diagram['links']);
        $this->assertSame(['sw-etage-1'], array_keys($this->devices($diagram)));
    }

    // --- Placed by hand -----------------------------------------------------------------

    public function test_a_panel_is_placed_by_hand_as_a_device_and_counted_apart_in_the_log(): void
    {
        $this->device('ap-salle', DeviceType::AccessPoint, $this->offices);
        $this->patchTo('sw-etage-1', '1/0/1', 'B.01');
        $this->plug('B.01', 'ap-salle');

        // A panel of the site that nothing drawn goes through.
        $idle = PatchPanel::factory()->create(['room_id' => $this->rack->id, 'name' => 'PB-X', 'port_count' => 4]);
        $panelKey = 'patch_panel-'.$this->panel->id;
        $switchKey = 'device-'.$this->switch->id;

        $layout = null;

        $this->actingAs($this->editor())
            ->get(route('diagrams.physical', ['site' => $this->office->id]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$layout): void {
                $layout = $page->toArray()['props']['layout'];
            });

        $this->assertIsArray($layout);

        $put = fn (array $positions, string $version) => $this->actingAs($this->editor())->put(route('sites.diagrams.physical.layout.update', $this->office), [
            'version' => $version,
            'positions' => $positions,
        ]);

        $this->assertRefused(
            $put([['node' => 'patch_panel-'.$idle->id, 'x' => 0, 'y' => 0]], $layout['version']),
            'positions',
            'Certains de ces éléments ne sont plus dessinés sur ce schéma : rechargez la page.',
        );

        $put([['node' => $switchKey, 'x' => 0, 'y' => 0], ['node' => $panelKey, 'x' => 0, 'y' => 160]], $layout['version'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Disposition enregistrée : 2 éléments placés.');

        $this->assertSame(
            [['patch_panel', $this->panel->id, 0, 160], ['device', $this->switch->id, 0, 0]],
            DiagramPosition::query()->orderBy('node', 'desc')->get()->map(fn (DiagramPosition $position): array => [$position->node->value, $position->device_id ?? $position->patch_panel_id, $position->x, $position->y])->all(),
        );
        $this->assertSame(
            ['diagram' => 'physical', 'nodes_count' => 2, 'devices_count' => 1, 'panels_count' => 1],
            $this->lastEntry(ActivityEvent::SiteDiagramSaved)->attribute_changes?->toArray()['attributes'],
        );

        // The page reads the position of the panel with the others.
        $this->actingAs($this->reader())
            ->get(route('diagrams.physical', ['site' => $this->office->id]))
            ->assertInertia(fn (Assert $page) => $page->where('layout.positions', [
                ['node' => $switchKey, 'x' => 0, 'y' => 0],
                ['node' => $panelKey, 'x' => 0, 'y' => 160],
            ]));

        // A panel has one place per diagram, on the physical one only.
        foreach ([
            ['view' => 'physical', 'node' => 'patch_panel', 'patch_panel_id' => $this->panel->id],
            ['view' => 'logical', 'node' => 'patch_panel', 'patch_panel_id' => $idle->id],
            ['view' => 'physical', 'node' => 'patch_panel', 'patch_panel_id' => null],
            ['view' => 'physical', 'node' => 'device', 'device_id' => $this->switch->id, 'patch_panel_id' => $idle->id],
        ] as $row) {
            try {
                DB::transaction(fn () => DB::table('diagram_positions')->insert([
                    'site_id' => $this->office->id, 'x' => 8, 'y' => 8, 'created_at' => now(), 'updated_at' => now(), ...$row,
                ]));

                $this->fail('A position was written: '.json_encode($row));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(2, DiagramPosition::query()->where('view', DiagramView::Physical->value)->count());
    }

    // --- Volume ---------------------------------------------------------------------------

    public function test_the_seeder_of_the_browser_test_of_volume_cables_its_end_devices_then_takes_them_away(): void
    {
        $before = DB::table('devices')->count();

        foreach (['cabled', 'remove'] as $mode) {
            putenv('DIAGRAM_VOLUME='.$mode);

            try {
                $this->seed(DiagramVolumeSeeder::class);
            } finally {
                putenv('DIAGRAM_VOLUME');
            }

            if ($mode === 'cabled') {
                $diagram = app(PhysicalDiagram::class)->of(Site::query()->where('name', DiagramVolumeSeeder::CABLED_SITE)->sole());

                // Every end device drawn, through four panels.
                $this->assertSame(['devices' => 25, 'end_devices' => 300, 'undrawn' => 0, 'links' => 0, 'deviations' => 150], $diagram['summary']);
                $this->assertCount(4, $diagram['panels']);
                $this->assertCount(600, $diagram['links']);
            }
        }

        $this->assertNull(Site::query()->where('name', DiagramVolumeSeeder::CABLED_SITE)->first());
        $this->assertSame($before, DB::table('devices')->count());
        $this->assertSame([0, 0, 0], [DB::table('jacks')->where('label', 'like', 'V.%')->count(), DB::table('jack_cords')->count(), DB::table('patch_panels')->whereIn('name', ['PB-1', 'PB-2', 'PB-3', 'PB-4'])->count()]);
    }

    public function test_the_page_costs_the_same_with_three_hundred_end_devices_cabled(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->actingAs($this->reader())->get(route('diagrams.physical', ['site' => $this->office->id]))->assertOk();

            $queries = count(DB::getQueryLog());

            DB::disableQueryLog();

            return $queries;
        };

        // One of each: a workstation plugged in, a printer by its address.
        $this->device('pc-0', DeviceType::Workstation, $this->offices, ports: 1);
        $this->patchTo('sw-etage-1', '1/0/1', 'B.01');
        $this->plug('B.01', 'pc-0');
        $this->patchTo('sw-etage-1', '1/0/2', 'B.02');
        $this->address('B.02', $this->device('imp-0', DeviceType::Printer, ports: 0), '10.10.10.2');

        $this->actingAs($this->reader());
        $small = $count();

        // Then 300 of them, half plugged into a jack, half by an address,
        // on 25 switches and 4 panels more.
        $panels = [];

        foreach (range(1, 4) as $index) {
            $panels[] = PatchPanel::factory()->create(['room_id' => $this->rack->id, 'name' => 'PB-V'.$index, 'port_count' => 96]);
        }

        $subnet = Subnet::factory()->cidr('10.20.0.0/16')->create(['site_id' => $this->office->id]);

        foreach (range(1, 300) as $number) {
            $jack = Jack::factory()->create(['floor_id' => $this->floor->id, 'label' => 'V.'.$number, 'patch_panel_port_id' => $this->panelPort(($number - 1) % 75 + 1, $panels[intdiv($number - 1, 75)])->id]);
            $switch = 'sw-v'.intdiv($number - 1, 12);

            if (! isset($this->ports[$switch])) {
                $this->device($switch, DeviceType::Switch, $this->rack, ports: 12);
            }

            PatchCordFactory::between($jack->panelPort()->sole(), $this->port($switch, '1/0/'.(($number - 1) % 12 + 1)));

            if ($number % 2 === 0) {
                $this->device('pc-v'.$number, DeviceType::Workstation, $this->offices, ports: 1);
                JackCordFactory::between($jack, $this->port('pc-v'.$number));
            } else {
                IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.20.'.intdiv($number, 250).'.'.($number % 250 + 1), 'jack_id' => $jack->id, 'device_id' => $this->device('imp-v'.$number, DeviceType::Printer, ports: 0)->id]);
            }
        }

        $this->assertSame(302, $this->diagram()['summary']['end_devices']);
        $this->assertSame($small, $count());
    }
}
