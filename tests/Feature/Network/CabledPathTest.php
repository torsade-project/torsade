<?php

// torsade/tests/Feature/Network/CabledPathTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\DeviceType;
use App\Enums\Status;
use App\Models\Building;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\Floor;
use App\Models\IpAddress;
use App\Models\Jack;
use App\Models\Link;
use App\Models\PatchPanel;
use App\Models\PatchPanelPort;
use App\Models\Port;
use App\Models\PortAggregate;
use App\Models\Room;
use App\Models\Site;
use App\Services\Network\CabledPaths;
use App\Services\Network\CablingMap;
use Database\Factories\JackCordFactory;
use Database\Factories\LinkFactory;
use Database\Factories\PatchCordFactory;
use Database\Factories\PortFactory;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The cabled paths: a port patched to a port of a panel whose jack has a
 * device plugged into it is cabled to the port of that device. Each case of
 * the model, looked at from both ports and with the link drawn both ways;
 * then where the pages show them.
 */
class CabledPathTest extends NetworkTestCase
{
    private Site $office;

    private Floor $floor;

    private PatchPanel $panel;

    private Device $switch;

    /**
     * The ports of the switch, by name.
     *
     * @var array<string, Port>
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

        $rack = Room::factory()->technical()->create(['floor_id' => $this->floor->id, 'name' => 'Placard technique']);
        $this->panel = PatchPanel::factory()->create(['room_id' => $rack->id, 'name' => 'PB-B', 'port_count' => 24]);

        foreach (range(1, 12) as $number) {
            $label = sprintf('B.%02d', $number);
            $this->jacks[$label] = Jack::factory()->create(['floor_id' => $this->floor->id, 'label' => $label, 'patch_panel_port_id' => $this->panelPort($number)->id]);
        }

        $this->switch = Device::factory()->create(['site_id' => $this->office->id, 'room_id' => $rack->id, 'name' => 'sw-etage-1', 'type' => DeviceType::Switch]);

        foreach (PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $this->switch->id]), 8) as $port) {
            $this->ports[$port->name] = $port;
        }
    }

    private function panelPort(int $number, ?PatchPanel $panel = null): PatchPanelPort
    {
        return PatchPanelPort::query()->where('patch_panel_id', ($panel ?? $this->panel)->id)->where('number', $number)->sole();
    }

    /**
     * A device of the site with one port.
     */
    private function device(string $name, DeviceType $type = DeviceType::AccessPoint, ?Site $site = null): Port
    {
        $device = Device::factory()->create(['site_id' => ($site ?? $this->office)->id, 'name' => $name, 'type' => $type]);

        return PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $device->id]), 1)[0];
    }

    /**
     * A port of the switch patched to a jack, through its port of the panel.
     */
    private function patchTo(string $port, string $jack): void
    {
        PatchCordFactory::between($this->jacks[$jack]->panelPort()->sole(), $this->ports[$port]);
    }

    private function map(): CablingMap
    {
        return app(CabledPaths::class)->of([$this->office->id]);
    }

    private function link(Link $link): Link
    {
        return Link::query()->with('ends.port.device')->findOrFail($link->id);
    }

    private function port(Port $port): Port
    {
        return Port::query()->with('device')->findOrFail($port->id);
    }

    private function jack(string $label): Jack
    {
        return $this->jacks[$label];
    }

    // --- A link the cabling confirms ---------------------------------------------

    public function test_the_cabling_of_a_link_is_its_physical_path_whichever_end_it_starts_from(): void
    {
        $ap = $this->device('ap-salle-reunion');
        $this->patchTo('1/0/1', 'B.01');
        JackCordFactory::between($this->jack('B.01'), $ap);

        // The link from the switch, then from the access point.
        foreach ([[$this->ports['1/0/1'], $ap], [$ap, $this->ports['1/0/1']]] as [$first, $second]) {
            Link::query()->delete();
            $link = LinkFactory::between($first, $second);

            $map = $this->map();

            $this->assertSame(
                [
                    'port_id' => $this->ports['1/0/1']->id,
                    'panel_port' => ['id' => $this->panelPort(1)->id, 'name' => 'PB-B 1', 'panel_id' => $this->panel->id, 'place' => 'Bâtiment principal › 1er étage › Placard technique'],
                    'jack' => ['id' => $this->jack('B.01')->id, 'label' => 'B.01', 'floor_id' => $this->floor->id, 'place' => 'Bâtiment principal › 1er étage'],
                ],
                $map->pathOf($this->link($link)),
            );
            $this->assertSame([$link->id], array_keys($map->paths()));

            // Nothing differs, from the link, either port or the jack.
            $this->assertSame([], $map->ofLink($this->link($link)));
            $this->assertSame([], $map->ofPort($this->port($this->ports['1/0/1'])));
            $this->assertSame([], $map->ofPort($this->port($ap)));
            $this->assertSame([], $map->ofJack($this->jack('B.01')));
        }

        // A planned link has its path too.
        Link::query()->update(['status' => Status::Planned]);

        $this->assertCount(1, $this->map()->paths());
    }

    // --- A cabling without a link ------------------------------------------------

    public function test_two_ports_cabled_without_a_link_are_a_deviation_with_the_link_to_create(): void
    {
        $ap = $this->device('ap-salle-reunion');
        $this->patchTo('1/0/2', 'B.02');
        JackCordFactory::between($this->jack('B.02'), $ap);

        $expected = [[
            'kind' => 'cabling_without_link',
            'reason' => 'sw-etage-1 1/0/2 et ap-salle-reunion 1/0/1 sont câblés par PB-B 2 › B.02, sans liaison',
            'action' => ['type' => 'create_link', 'device' => $this->switch->id, 'port' => $this->ports['1/0/2']->id, 'opposite_device' => $ap->device_id, 'opposite_port' => $ap->id],
        ]];

        $map = $this->map();

        // On both devices, and on the jack.
        $this->assertSame($expected, $map->ofPort($this->port($this->ports['1/0/2'])));
        $this->assertSame($expected, $map->ofPort($this->port($ap)));
        $this->assertSame($expected, $map->ofJack($this->jack('B.02')));
        $this->assertSame([], $map->paths());
    }

    // --- A link the cabling contradicts ------------------------------------------

    public function test_a_link_one_of_whose_ends_is_cabled_elsewhere_is_contradicted_on_either_side(): void
    {
        $ap = $this->device('ap-etage-2');
        $switch = $this->device('sw-salle-reunion', DeviceType::Switch);

        // The patched port has a link to another port than the one cabled.
        $this->patchTo('1/0/3', 'B.03');
        JackCordFactory::between($this->jack('B.03'), $ap);
        $fromPatched = LinkFactory::between($this->ports['1/0/3'], $switch);

        $this->assertSame(
            [['kind' => 'link_contradicted_by_cabling', 'reason' => 'Le câblage de sw-etage-1 1/0/3 mène à ap-etage-2 1/0/1 ; la liaison, à sw-salle-reunion 1/0/1']],
            $this->map()->ofLink($this->link($fromPatched)),
        );
        $this->assertSame([], $this->map()->paths());

        // The plugged port has a link to another port than the one cabled,
        // drawn from its other end.
        $fromPlugged = LinkFactory::between($this->ports['1/0/4'], $ap);

        $map = $this->map();

        $this->assertSame(
            [['kind' => 'link_contradicted_by_cabling', 'reason' => 'Le câblage de ap-etage-2 1/0/1 mène à sw-etage-1 1/0/3 ; la liaison, à sw-etage-1 1/0/4']],
            $map->ofLink($this->link($fromPlugged)),
        );
        // Neither port nor the jack is told a cabling without a link.
        $this->assertSame([], $map->ofPort($this->port($this->ports['1/0/3'])));
        $this->assertSame([], $map->ofJack($this->jack('B.03')));

        // A retired link counts as well: a port has one link at most.
        $fromPatched->update(['status' => Status::Retired]);

        $this->assertSame('link_contradicted_by_cabling', $this->map()->ofLink($this->link($fromPatched))[0]['kind'] ?? null);
    }

    // --- The cabling of a retired link -------------------------------------------

    public function test_a_retired_link_whose_cords_are_still_there_is_a_deviation(): void
    {
        $ap = $this->device('ap-atelier-2');
        $this->patchTo('1/0/5', 'B.05');
        JackCordFactory::between($this->jack('B.05'), $ap);

        foreach ([[$ap, $this->ports['1/0/5']], [$this->ports['1/0/5'], $ap]] as [$first, $second]) {
            Link::query()->delete();
            $link = LinkFactory::between($first, $second, ['status' => Status::Retired]);

            $map = $this->map();

            $this->assertSame(
                [['kind' => 'cabling_of_retired_link', 'reason' => 'sw-etage-1 1/0/5 et ap-atelier-2 1/0/1 sont encore câblés par PB-B 5 › B.05 ; leur liaison est retirée']],
                $map->ofLink($this->link($link)),
            );
            $this->assertNull($map->pathOf($this->link($link)));
            $this->assertSame([], $map->ofPort($this->port($ap)));
        }
    }

    // --- A port linked without being plugged -------------------------------------

    public function test_a_linked_port_patched_to_a_jack_where_nothing_is_plugged_offers_to_plug_the_port_in_front(): void
    {
        $ap = $this->device('ap-salle-reunion');
        $this->patchTo('1/0/6', 'B.06');
        $link = LinkFactory::between($ap, $this->ports['1/0/6']);

        $expected = [[
            'kind' => 'linked_without_plug',
            'reason' => "sw-etage-1 1/0/6 est relié à ap-salle-reunion 1/0/1 et brassé vers B.06, où rien n'est branché",
            'action' => ['type' => 'plug', 'jack' => $this->jack('B.06')->id, 'port' => $ap->id],
        ]];

        $map = $this->map();

        // On the patched port, the jack and the link; not on the port in
        // front, which is plugged nowhere.
        $this->assertSame($expected, $map->ofPort($this->port($this->ports['1/0/6'])));
        $this->assertSame($expected, $map->ofJack($this->jack('B.06')));
        $this->assertSame($expected, $map->ofLink($this->link($link)));
        $this->assertSame([], $map->ofPort($this->port($ap)));

        // The addresses plugged into the jack belong to what is behind the
        // port in front: they are not cabled to the patched port.
        IpAddress::factory()->create(['address' => '10.10.10.20', 'subnet_id' => $this->subnet('10.10.10.0/24')->id, 'jack_id' => $this->jack('B.06')->id, 'device_id' => Device::factory()->create(['site_id' => $this->office->id, 'type' => DeviceType::Workstation])->id]);

        $this->assertSame([], $this->map()->addressed());

        // A retired link: nothing is expected of the jack.
        $link->update(['status' => Status::Retired]);

        $this->assertSame([], $this->map()->ofPort($this->port($this->ports['1/0/6'])));
    }

    public function test_the_port_in_front_is_offered_to_be_plugged_only_when_it_can(): void
    {
        $action = fn (): ?array => $this->map()->ofPort($this->port($this->ports['1/0/7']))[0]['action'] ?? null;

        $this->patchTo('1/0/7', 'B.07');

        // The port in front is patched to a panel: it has one connector.
        $patched = $this->device('ap-patched');
        $link = LinkFactory::between($this->ports['1/0/7'], $patched);
        PatchCordFactory::between($this->panelPort(20), $patched);

        $this->assertNull($action());

        // Plugged into another jack.
        $plugged = $this->device('ap-plugged');
        $link->delete();
        $link = LinkFactory::between($this->ports['1/0/7'], $plugged);
        JackCordFactory::between($this->jack('B.12'), $plugged);

        $this->assertNull($action());

        // On another site.
        $faraway = $this->device('ap-ailleurs', site: Site::factory()->create(['name' => 'Atelier']));
        $link->delete();
        $link = LinkFactory::between($this->ports['1/0/7'], $faraway);

        $this->assertNull($action());

        // Retired.
        $retired = $this->device('ap-retiree');
        $retired->device->update(['status' => Status::Retired]);
        $link->delete();
        LinkFactory::between($this->ports['1/0/7'], $retired);

        $this->assertNull($action());
        // The deviation stays.
        $this->assertSame('linked_without_plug', $this->map()->ofPort($this->port($this->ports['1/0/7']))[0]['kind'] ?? null);
    }

    // --- A workstation plugged in by its address ----------------------------------

    public function test_a_device_plugged_in_by_its_address_is_cabled_to_the_port_without_a_port_of_its_own(): void
    {
        $subnet = $this->subnet('10.10.10.0/24', ['site_id' => $this->office->id]);
        $printer = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'imp-compta', 'type' => DeviceType::Printer]);
        $workstation = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'pc-compta-01', 'type' => DeviceType::Workstation]);
        $retired = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'pc-ancien', 'type' => DeviceType::Workstation, 'status' => Status::Retired]);

        $this->patchTo('1/0/8', 'B.08');

        foreach ([
            ['10.10.10.21', $printer, Status::Active],
            ['10.10.10.22', $workstation, Status::Retired],
            ['10.10.10.23', $retired, Status::Active],
            ['10.10.10.24', null, Status::Active],
        ] as [$address, $device, $status]) {
            IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => $address, 'jack_id' => $this->jack('B.08')->id, 'device_id' => $device?->id, 'status' => $status]);
        }

        $addressed = $this->map()->addressed();

        // Only the device of an address that is not retired, itself not
        // retired; no link is expected.
        $this->assertCount(1, $addressed);
        $this->assertSame([$this->ports['1/0/8']->id, 'B.08', ['imp-compta']], [$addressed[0]['port']->id, $addressed[0]['jack']->label, array_map(fn (Device $device): string => $device->name, $addressed[0]['devices'])]);
        $this->assertSame([], $this->map()->ofPort($this->port($this->ports['1/0/8'])));

        // Behind a device plugged into the jack, as a workstation behind a
        // telephone, the address is not cabled to the port: the device is.
        $phone = $this->device('tel-compta-1', DeviceType::IpPhone);
        JackCordFactory::between($this->jack('B.08'), $phone);

        $map = $this->map();

        $this->assertSame([], $map->addressed());
        $this->assertSame([[$this->ports['1/0/8']->id, $phone->id]], array_map(fn (array $pair): array => [$pair['a']->id, $pair['b']->id], $map->pairs()));
    }

    // --- What is retired cuts the path -------------------------------------------

    public function test_a_retired_device_jack_or_panel_cuts_the_path(): void
    {
        $ap = $this->device('ap-salle-reunion');
        $this->patchTo('1/0/1', 'B.01');
        JackCordFactory::between($this->jack('B.01'), $ap);
        LinkFactory::between($this->ports['1/0/1'], $ap);

        $this->assertCount(1, $this->map()->pairs());

        foreach ([
            fn () => $this->switch->update(['status' => Status::Retired]),
            fn () => $ap->device->update(['status' => Status::Retired]),
            fn () => $this->jack('B.01')->update(['status' => Status::Retired]),
            fn () => $this->panel->update(['status' => Status::Retired]),
        ] as $retire) {
            DB::transaction(function () use ($retire): void {
                $retire();

                $this->assertSame([], $this->map()->pairs());
                $this->assertSame([], $this->map()->paths());

                DB::rollBack();
                DB::beginTransaction();
            });
        }

        // Planned, they are in the path.
        $ap->device->update(['status' => Status::Planned]);
        $this->jack('B.01')->update(['status' => Status::Planned]);
        $this->panel->update(['status' => Status::Planned]);

        $this->assertCount(1, $this->map()->paths());
    }

    public function test_a_riser_panel_without_a_jack_makes_no_path(): void
    {
        $riser = PatchPanel::factory()->create(['room_id' => $this->panel->room_id, 'name' => 'PB-FO', 'port_count' => 4]);
        $uplink = $this->device('sw-coeur-01', DeviceType::Switch);
        PatchCordFactory::between($this->panelPort(1, $riser), $this->ports['1/0/1']);
        LinkFactory::between($this->ports['1/0/1'], $uplink);

        $map = $this->map();

        $this->assertSame([[], [], []], [$map->pairs(), $map->unplugged(), $map->addressed()]);
    }

    // --- An aggregated link ------------------------------------------------------

    public function test_each_link_of_an_aggregated_link_has_its_own_path(): void
    {
        $other = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-salle-reunion', 'type' => DeviceType::Switch]);
        $ports = PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $other->id]), 2);
        $near = PortAggregate::factory()->create(['device_id' => $this->switch->id, 'name' => 'Po1']);
        $far = PortAggregate::factory()->create(['device_id' => $other->id, 'name' => 'Po1']);

        $links = [];

        foreach ([['1/0/1', 'B.01', 0], ['1/0/2', 'B.02', 1]] as [$name, $label, $index]) {
            $this->ports[$name]->update(['port_aggregate_id' => $near->id]);
            $ports[$index]->update(['port_aggregate_id' => $far->id]);
            $this->patchTo($name, $label);
            JackCordFactory::between($this->jack($label), $ports[$index]);
            $links[] = LinkFactory::between($this->ports[$name], $ports[$index]);
        }

        $paths = $this->map()->paths();

        $this->assertSame(['PB-B 1', 'PB-B 2'], [$paths[$links[0]->id]['panel_port']['name'], $paths[$links[1]->id]['panel_port']['name']]);
        $this->assertSame(['B.01', 'B.02'], [$paths[$links[0]->id]['jack']['label'], $paths[$links[1]->id]['jack']['label']]);
    }

    // --- Volume ------------------------------------------------------------------

    public function test_the_number_of_queries_does_not_depend_on_the_number_of_jacks_nor_of_devices(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $map = $this->map();
            $map->paths();

            $queries = count(DB::getQueryLog());

            DB::disableQueryLog();

            return $queries;
        };

        // One of each case: a link the cabling confirms, a port linked
        // without a plug, a device plugged in by its address.
        $ap = $this->device('ap-1');
        $this->patchTo('1/0/1', 'B.01');
        JackCordFactory::between($this->jack('B.01'), $ap);
        LinkFactory::between($this->ports['1/0/1'], $ap);

        $this->patchTo('1/0/2', 'B.02');
        LinkFactory::between($this->ports['1/0/2'], $this->device('ap-2'));

        $this->patchTo('1/0/3', 'B.03');
        IpAddress::factory()->create(['subnet_id' => $this->subnet('10.10.10.0/24')->id, 'address' => '10.10.10.10', 'jack_id' => $this->jack('B.03')->id, 'device_id' => Device::factory()->create(['site_id' => $this->office->id, 'type' => DeviceType::Printer])->id]);

        $small = $count();

        // 300 jacks and 50 devices, each case many times over.
        $floor = Floor::factory()->create(['building_id' => $this->floor->building_id, 'level' => 2, 'name' => '2e étage']);
        $panels = [];

        foreach (range(1, 4) as $index) {
            $panels[] = PatchPanel::factory()->create(['room_id' => $this->panel->room_id, 'name' => 'PB-V'.$index, 'port_count' => 96]);
        }

        $subnet = $this->subnet('10.20.0.0/16', ['site_id' => $this->office->id]);
        $jacks = [];

        foreach (range(1, 300) as $number) {
            $jacks[] = Jack::factory()->create([
                'floor_id' => $floor->id,
                'label' => 'V.'.$number,
                'patch_panel_port_id' => $this->panelPort(($number - 1) % 75 + 1, $panels[intdiv($number - 1, 75)])->id,
            ]);
        }

        foreach (range(1, 50) as $index) {
            $device = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-v'.$index, 'type' => DeviceType::Switch]);
            $devicePorts = PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $device->id]), 6);

            // Three ports patched: one cabled to a device plugged into its
            // jack, one linked without a plug, one with an address behind.
            $base = ($index - 1) * 6;
            PatchCordFactory::between($jacks[$base]->panelPort()->sole(), $devicePorts[0]);
            JackCordFactory::between($jacks[$base], $devicePorts[3]);
            LinkFactory::between($devicePorts[0], $devicePorts[3]);

            PatchCordFactory::between($jacks[$base + 1]->panelPort()->sole(), $devicePorts[1]);
            LinkFactory::between($devicePorts[1], $devicePorts[4]);

            PatchCordFactory::between($jacks[$base + 2]->panelPort()->sole(), $devicePorts[2]);
            IpAddress::factory()->create(['subnet_id' => $subnet->id, 'address' => '10.20.'.$index.'.10', 'jack_id' => $jacks[$base + 2]->id, 'device_id' => $device->id === $devicePorts[2]->device_id ? Device::factory()->create(['site_id' => $this->office->id, 'type' => DeviceType::Printer])->id : null]);
        }

        $map = $this->map();

        $this->assertCount(51, $map->paths());
        $this->assertCount(51, $map->unplugged());
        $this->assertCount(51, $map->addressed());
        $this->assertSame($small, $count());
    }

    // --- The pages ---------------------------------------------------------------

    public function test_the_pages_show_the_path_of_a_link_and_the_deviations_of_the_cabling(): void
    {
        $ap = $this->device('ap-salle-reunion');
        $this->patchTo('1/0/1', 'B.01');
        JackCordFactory::between($this->jack('B.01'), $ap);
        $confirmed = LinkFactory::between($this->ports['1/0/1'], $ap);

        $unlinked = $this->device('ap-etage-1');
        $this->patchTo('1/0/2', 'B.02');
        JackCordFactory::between($this->jack('B.02'), $unlinked);

        $reader = $this->reader();

        // The list of links: "through PB-B 1 › B.01".
        $this->actingAs($reader)
            ->get(route('links.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('links.0.links.0.id', $confirmed->id)
                ->where('links.0.links.0.path.panel_port.name', 'PB-B 1')
                ->where('links.0.links.0.path.jack.label', 'B.01')
                ->where('links.0.links.0.path.port_id', $this->ports['1/0/1']->id)
                ->where('links.0.deviations', []));

        // The page of each device: the path on its link, the cabling
        // without a link on its port and in its list of deviations.
        foreach ([$this->switch, $unlinked->device] as $device) {
            $this->actingAs($reader)
                ->get(route('devices.show', $device))
                ->assertOk()
                ->assertInertia(function (Assert $page) use ($device): void {
                    $props = $page->toArray()['props'];

                    $this->assertContains('cabling_without_link', array_column(array_merge(...array_column($props['ports'], 'deviations')), 'kind'));
                    $this->assertContains('cabling_without_link', array_column(array_merge(...array_column($props['linkDeviations'], 'deviations')), 'kind'));

                    if ($device->is($this->switch)) {
                        $this->assertSame('B.01', $props['links'][0]['links'][0]['path']['jack']['label']);
                    }
                });
        }

        // The list of jacks and the page of the panel.
        $this->actingAs($reader)
            ->get(route('jacks.index'))
            ->assertInertia(function (Assert $page): void {
                $kinds = array_column(array_column($page->toArray()['props']['jacks'], 'deviations', 'label')['B.02'], 'kind');

                $this->assertSame(['cabling_without_link'], $kinds);
            });

        $this->actingAs($reader)
            ->get(route('patch-panels.show', $this->panel))
            ->assertInertia(fn (Assert $page) => $page->where('ports.1.deviations.0.kind', 'cabling_without_link'));
    }

    public function test_create_the_link_opens_the_window_of_the_port_with_the_port_in_front_chosen(): void
    {
        $ap = $this->device('ap-etage-1');
        $this->patchTo('1/0/2', 'B.02');
        JackCordFactory::between($this->jack('B.02'), $ap);

        $query = ['device' => $this->switch, 'link_port' => $this->ports['1/0/2']->id, 'link_to' => $ap->id];

        $this->actingAs($this->editor())
            ->get(route('devices.show', $query))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('linkDraft', [
                'port_id' => $this->ports['1/0/2']->id,
                'opposite' => ['device' => ['id' => $ap->device_id, 'name' => 'ap-etage-1', 'site' => 'Siège'], 'port_id' => $ap->id],
            ]));

        // A reader is offered nothing; nor is anyone once a port is linked.
        $this->actingAs($this->reader())->get(route('devices.show', $query))->assertInertia(fn (Assert $page) => $page->where('linkDraft', null));

        // The link created there is logged like any link, and the deviation
        // is gone.
        $editor = $this->editor();

        $this->actingAs($editor)->post(route('links.store', $this->switch), [
            'port_ids' => [$this->ports['1/0/2']->id],
            'opposite_device_id' => $ap->device_id,
            'opposite_port_ids' => [$ap->id],
            'status' => 'active',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->countEntries(ActivityEvent::LinkCreated));
        $this->assertSame([], $this->map()->ofPort($this->port($ap)));

        $this->actingAs($editor)->get(route('devices.show', $query))->assertInertia(fn (Assert $page) => $page->where('linkDraft', null));
    }

    public function test_plug_from_the_deviation_plugs_the_port_in_front_and_the_deviation_is_gone(): void
    {
        $ap = $this->device('ap-salle-reunion');
        $this->patchTo('1/0/6', 'B.06');
        $link = LinkFactory::between($this->ports['1/0/6'], $ap);

        $action = $this->map()->ofLink($this->link($link))[0]['action'] ?? [];

        $this->actingAs($this->editor())
            ->from(route('links.index'))
            ->post(route('jack-cords.store'), ['jack_id' => $action['jack'] ?? null, 'port_id' => $action['port'] ?? null])
            ->assertRedirect(route('links.index'))
            ->assertSessionHasNoErrors();

        $map = $this->map();

        $this->assertSame([], $map->ofLink($this->link($link)));
        $this->assertSame('B.06', $map->pathOf($this->link($link))['jack']['label'] ?? null);
    }
}
