<?php

// torsade/tests/Feature/Network/CablingDeviationTest.php

namespace Tests\Feature\Network;

use App\Enums\DeviceType;
use App\Enums\PortMode;
use App\Enums\Status;
use App\Http\Presenters\NetworkPresenter;
use App\Models\Building;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\Floor;
use App\Models\IpAddress;
use App\Models\Jack;
use App\Models\PatchPanel;
use App\Models\PatchPanelPort;
use App\Models\Port;
use App\Models\PortAggregate;
use App\Models\PortVlan;
use App\Models\Room;
use App\Models\Site;
use App\Models\Subnet;
use App\Models\Vlan;
use App\Services\Network\CablingDeviations;
use Database\Factories\LinkFactory;
use Database\Factories\PatchCordFactory;
use Database\Factories\PortFactory;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The deviations of the cabling, case by case: a cord between two rooms, an
 * active address on a jack that is not patched, an address whose VLAN the
 * port at the end of its jack does not carry; then where each one is
 * shown, with a linked port patched to a jack where nothing is plugged,
 * which the cabled paths tell.
 */
class CablingDeviationTest extends NetworkTestCase
{
    private Site $office;

    private Floor $ground;

    private Floor $first;

    private Room $groundRack;

    private Room $firstRack;

    private PatchPanel $panel;

    private Device $switch;

    /**
     * The ports of the switch, by name: its member 1, kept in the room of
     * the switch, and its member 2, kept in the room of the panel.
     *
     * @var array<string, Port>
     */
    private array $ports = [];

    /**
     * The VLANs of the site, by number.
     *
     * @var array<int, Vlan>
     */
    private array $vlans = [];

    private Subnet $offices;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = Site::factory()->create(['name' => 'Siège']);

        $building = Building::factory()->create(['site_id' => $this->office->id, 'name' => 'Bâtiment principal']);

        $this->ground = Floor::factory()->create(['building_id' => $building->id, 'level' => 0, 'name' => 'Rez-de-chaussée']);
        $this->first = Floor::factory()->create(['building_id' => $building->id, 'level' => 1, 'name' => '1er étage']);
        $this->groundRack = Room::factory()->technical()->create(['floor_id' => $this->ground->id, 'name' => 'Local technique']);
        $this->firstRack = Room::factory()->technical()->create(['floor_id' => $this->first->id, 'name' => 'Baie']);

        $this->panel = PatchPanel::factory()->create(['room_id' => $this->groundRack->id, 'name' => 'PB-A', 'port_count' => 24]);
        $this->switch = Device::factory()->create(['site_id' => $this->office->id, 'room_id' => $this->firstRack->id, 'name' => 'sw-etage-1', 'type' => DeviceType::Switch]);

        $members = [
            1 => DeviceMember::factory()->primary()->create(['device_id' => $this->switch->id, 'position' => 1]),
            2 => DeviceMember::factory()->create(['device_id' => $this->switch->id, 'position' => 2, 'room_id' => $this->groundRack->id]),
        ];

        foreach ($members as $member) {
            foreach (PortFactory::series($member, 8) as $port) {
                $this->ports[$port->name] = $port;
            }
        }

        foreach ([10, 20, 30, 99] as $vid) {
            $this->vlans[$vid] = Vlan::factory()->create(['site_id' => $this->office->id, 'vid' => $vid]);
        }

        $this->offices = $this->subnet('10.10.10.0/24', ['site_id' => $this->office->id, 'vlan_id' => $this->vlans[10]->id]);
    }

    private function panelPort(int $number): PatchPanelPort
    {
        return PatchPanelPort::query()->where('patch_panel_id', $this->panel->id)->where('number', $number)->sole();
    }

    /**
     * A jack of the first floor, cabled to a port of the panel or to none.
     */
    private function jack(string $label, ?int $number = null): Jack
    {
        return Jack::factory()->create(['floor_id' => $this->first->id, 'label' => $label, 'patch_panel_port_id' => $number === null ? null : $this->panelPort($number)->id]);
    }

    private function address(string $ip, Jack $jack, Status $status = Status::Active, ?Subnet $subnet = null): IpAddress
    {
        return IpAddress::factory()->create(['subnet_id' => ($subnet ?? $this->offices)->id, 'address' => $ip, 'jack_id' => $jack->id, 'status' => $status]);
    }

    /**
     * Give a port, or an aggregate, a mode and VLANs.
     *
     * @param  list<int>  $tagged
     */
    private function configure(Port|PortAggregate $holder, PortMode $mode, ?int $untagged, array $tagged = []): void
    {
        $holder->update(['mode' => $mode]);

        $owner = $holder instanceof Port ? ['port_id' => $holder->id] : ['port_aggregate_id' => $holder->id];

        if ($untagged !== null) {
            PortVlan::factory()->create([...$owner, 'vlan_id' => $this->vlans[$untagged]->id, 'tagged' => false]);
        }

        foreach ($tagged as $vid) {
            PortVlan::factory()->create([...$owner, 'vlan_id' => $this->vlans[$vid]->id, 'tagged' => true]);
        }
    }

    /**
     * A jack patched to a port of the switch, through a port of the panel,
     * with an address plugged into it; the reasons the address is told.
     *
     * @return list<string>
     */
    private function servedBy(Port $port, int $number, ?Subnet $subnet = null): array
    {
        $jack = $this->jack('J.'.$number, $number);
        PatchCordFactory::between($this->panelPort($number), $port);

        return $this->reasonsOfAddress($this->address('10.10.10.'.$number, $jack, subnet: $subnet));
    }

    /**
     * @return list<string>
     */
    private function reasonsOfAddress(IpAddress $address): array
    {
        $address = IpAddress::query()->with(['subnet.vlan', ...NetworkPresenter::ADDRESS_JACK])->findOrFail($address->id);

        return array_column((new CablingDeviations)->ofAddress($address), 'reason');
    }

    /**
     * @return list<array{kind: string, reason: string}>
     */
    private function ofJack(Jack $jack): array
    {
        return (new CablingDeviations)->ofJack(Jack::query()->with(CablingDeviations::JACK)->findOrFail($jack->id));
    }

    /**
     * @return list<array{kind: string, reason: string}>
     */
    private function ofPort(Port $port): array
    {
        $port = Port::query()->with([...CablingDeviations::PORT, 'device.room.floor.building'])->findOrFail($port->id);

        return (new CablingDeviations)->ofPort($port);
    }

    /**
     * @return list<array{kind: string, reason: string}>
     */
    private function ofPanelPort(int $number): array
    {
        $panelPort = PatchPanelPort::query()->with(CablingDeviations::PANEL_PORT)->findOrFail($this->panelPort($number)->id);
        $panelPort->setRelation('panel', PatchPanel::query()->with('room.floor.building')->findOrFail($this->panel->id));

        return (new CablingDeviations)->ofPanelPort($panelPort);
    }

    // --- 1: a cord between two rooms -------------------------------------------

    public function test_a_cord_between_a_panel_and_a_device_kept_in_two_rooms_is_a_deviation(): void
    {
        // The member 1 is kept where its switch is, in another room than
        // the panel.
        PatchCordFactory::between($this->panelPort(1), $this->ports['1/0/1']);
        // The member 2 is kept in the room of the panel, whatever the room of
        // its switch.
        PatchCordFactory::between($this->panelPort(2), $this->ports['2/0/1']);

        // Two floors of one building: the building is not repeated.
        $expected = [['kind' => 'cord_across_rooms', 'reason' => 'Panneau dans Rez-de-chaussée › Local technique, sw-etage-1 dans 1er étage › Baie']];

        $this->assertSame($expected, $this->ofPanelPort(1));
        $this->assertSame($expected, $this->ofPort($this->ports['1/0/1']));
        $this->assertSame([], $this->ofPanelPort(2));
        $this->assertSame([], $this->ofPort($this->ports['2/0/1']));

        // A device kept in no room is not compared.
        $server = Device::factory()->create(['site_id' => $this->office->id, 'room_id' => null, 'name' => 'srv-01']);
        PatchCordFactory::between($this->panelPort(3), PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $server->id]), 1)[0]);

        $this->assertSame([], $this->ofPanelPort(3));
    }

    public function test_the_two_rooms_of_a_cord_are_named_without_what_they_have_in_common(): void
    {
        // Two rooms of one floor: the rooms alone.
        $office = Room::factory()->create(['floor_id' => $this->ground->id, 'name' => 'Accueil']);
        $desk = Device::factory()->create(['site_id' => $this->office->id, 'room_id' => $office->id, 'name' => 'sw-accueil']);
        $port = PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $desk->id]), 1)[0];
        PatchCordFactory::between($this->panelPort(10), $port);

        $this->assertSame([['kind' => 'cord_across_rooms', 'reason' => 'Panneau dans Local technique, sw-accueil dans Accueil']], $this->ofPanelPort(10));

        // Two buildings: the whole paths.
        $annex = Building::factory()->create(['site_id' => $this->office->id, 'name' => 'Annexe']);
        $store = Room::factory()->create(['floor_id' => Floor::factory()->create(['building_id' => $annex->id, 'level' => 0, 'name' => 'Rez-de-chaussée'])->id, 'name' => 'Magasin']);
        $far = Device::factory()->create(['site_id' => $this->office->id, 'room_id' => $store->id, 'name' => 'sw-annexe']);
        $farPort = PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $far->id]), 1)[0];
        PatchCordFactory::between($this->panelPort(11), $farPort);

        $this->assertSame(
            [['kind' => 'cord_across_rooms', 'reason' => 'Panneau dans Bâtiment principal › Rez-de-chaussée › Local technique, sw-annexe dans Annexe › Rez-de-chaussée › Magasin']],
            $this->ofPanelPort(11),
        );

        // A member kept in another room than its device: the room of the
        // member counts, named the same way.
        $this->switch->members()->where('position', 2)->update(['room_id' => $office->id]);
        PatchCordFactory::between($this->panelPort(12), $this->ports['2/0/3']);

        $this->assertSame([['kind' => 'cord_across_rooms', 'reason' => 'Panneau dans Local technique, sw-etage-1 dans Accueil']], $this->ofPanelPort(12));
        $this->assertSame([['kind' => 'cord_across_rooms', 'reason' => 'Panneau dans Local technique, sw-etage-1 dans Accueil']], $this->ofPort($this->ports['2/0/3']));
    }

    // --- 2: an active address on a jack that is not patched --------------------

    public function test_an_active_address_on_a_jack_that_is_not_patched_is_a_deviation(): void
    {
        // No horizontal cable, or a cable and no cord.
        $alone = $this->jack('B.01');
        $cabled = $this->jack('B.02', 2);

        $first = $this->address('10.10.10.1', $alone);
        $this->address('10.10.10.2', $cabled);
        $this->address('10.10.10.3', $cabled);

        $this->assertSame(['Prise B.01 non brassée'], $this->reasonsOfAddress($first));
        $this->assertSame([['kind' => 'address_on_unpatched_jack', 'reason' => 'Prise B.01 non brassée']], $this->ofJack($alone));
        // One deviation for the jack, whatever the number of addresses.
        $this->assertSame([['kind' => 'address_on_unpatched_jack', 'reason' => 'Prise B.02 non brassée']], $this->ofJack($cabled));
        $this->assertSame([['kind' => 'address_on_unpatched_jack', 'reason' => 'Prise B.02 non brassée']], $this->ofPanelPort(2));

        // A planned address, or a jack without an address: none.
        $planned = $this->jack('B.03');
        $this->address('10.10.10.4', $planned, Status::Planned);

        $this->assertSame([], $this->ofJack($planned));
        $this->assertSame([], $this->ofJack($this->jack('B.04')));

        // Once patched, the jack serves its address.
        PatchCordFactory::between($this->panelPort(2), $this->ports['2/0/1']);

        $this->assertSame([], $this->ofJack($cabled));
    }

    // --- 3: an address whose VLAN the port does not carry ----------------------

    public function test_an_access_port_carries_its_vlan_and_its_voice_vlan(): void
    {
        $this->configure($this->ports['2/0/1'], PortMode::Access, 10);
        $this->configure($this->ports['2/0/2'], PortMode::Access, 20);
        $this->configure($this->ports['2/0/3'], PortMode::Access, 20, [10]);
        $this->configure($this->ports['2/0/4'], PortMode::Access, null);

        $this->assertSame([], $this->servedBy($this->ports['2/0/1'], 1));
        $this->assertSame(['sw-etage-1 2/0/2 en accès VLAN 20, adresse 10.10.10.2 du VLAN 10'], $this->servedBy($this->ports['2/0/2'], 2));
        // The VLAN of the address is the voice VLAN of the port.
        $this->assertSame([], $this->servedBy($this->ports['2/0/3'], 3));
        $this->assertSame(['sw-etage-1 2/0/4 en accès sans VLAN, adresse 10.10.10.4 du VLAN 10'], $this->servedBy($this->ports['2/0/4'], 4));
    }

    public function test_a_trunk_carries_its_native_vlan_and_its_tagged_vlans(): void
    {
        $this->configure($this->ports['2/0/1'], PortMode::Trunk, 10, [20]);
        $this->configure($this->ports['2/0/2'], PortMode::Trunk, 99, [10, 20]);
        $this->configure($this->ports['2/0/3'], PortMode::Trunk, 99, [20, 30]);

        $this->assertSame([], $this->servedBy($this->ports['2/0/1'], 1));
        $this->assertSame([], $this->servedBy($this->ports['2/0/2'], 2));
        $this->assertSame(["sw-etage-1 2/0/3 en trunk sans le VLAN 10, celui de l'adresse 10.10.10.3"], $this->servedBy($this->ports['2/0/3'], 3));
    }

    public function test_the_port_of_an_aggregate_carries_what_its_aggregate_carries(): void
    {
        $carrying = PortAggregate::factory()->create(['device_id' => $this->switch->id, 'name' => 'Po1']);
        $missing = PortAggregate::factory()->create(['device_id' => $this->switch->id, 'name' => 'Po2']);

        $this->configure($carrying, PortMode::Trunk, null, [10, 20]);
        $this->configure($missing, PortMode::Trunk, null, [20]);

        $this->ports['2/0/1']->update(['port_aggregate_id' => $carrying->id]);
        $this->ports['2/0/2']->update(['port_aggregate_id' => $missing->id]);

        $this->assertSame([], $this->servedBy($this->ports['2/0/1'], 1));
        $this->assertSame(["sw-etage-1 Po2 en trunk sans le VLAN 10, celui de l'adresse 10.10.10.2"], $this->servedBy($this->ports['2/0/2'], 2));
    }

    public function test_the_vlan_of_an_address_is_compared_when_it_is_active_or_planned_not_retired(): void
    {
        $this->configure($this->ports['2/0/2'], PortMode::Access, 20);
        $jack = $this->jack('B.02', 2);
        PatchCordFactory::between($this->panelPort(2), $this->ports['2/0/2']);

        $active = $this->address('10.10.10.2', $jack);
        $planned = $this->address('10.10.10.3', $jack, Status::Planned);
        $retired = $this->address('10.10.10.4', $jack, Status::Retired);

        $this->assertSame(['sw-etage-1 2/0/2 en accès VLAN 20, adresse 10.10.10.2 du VLAN 10'], $this->reasonsOfAddress($active));
        $this->assertSame(['sw-etage-1 2/0/2 en accès VLAN 20, adresse 10.10.10.3 du VLAN 10'], $this->reasonsOfAddress($planned));
        $this->assertSame([], $this->reasonsOfAddress($retired));

        // On the jack, the port and the panel: the two that are not retired.
        $reasons = fn (array $deviations): array => array_column($deviations, 'reason');
        $expected = [
            'sw-etage-1 2/0/2 en accès VLAN 20, adresse 10.10.10.2 du VLAN 10',
            'sw-etage-1 2/0/2 en accès VLAN 20, adresse 10.10.10.3 du VLAN 10',
        ];

        $this->assertEqualsCanonicalizing($expected, $reasons($this->ofJack($jack)));
        $this->assertEqualsCanonicalizing($expected, $reasons($this->ofPort($this->ports['2/0/2'])));
        $this->assertEqualsCanonicalizing($expected, $reasons($this->ofPanelPort(2)));
    }

    public function test_nothing_is_compared_without_a_mode_or_without_a_vlan(): void
    {
        // The port of a server or of a firewall has no mode.
        $this->assertSame([], $this->servedBy($this->ports['2/0/1'], 1));

        // A subnet without a VLAN.
        $this->configure($this->ports['2/0/2'], PortMode::Access, 20);

        $this->assertSame([], $this->servedBy($this->ports['2/0/2'], 2, $this->subnet('10.10.20.0/24', ['site_id' => $this->office->id])));
    }

    public function test_a_vlan_that_is_not_carried_is_told_on_the_jack_on_the_port_and_on_the_panel(): void
    {
        $this->configure($this->ports['2/0/5'], PortMode::Access, 20);
        $jack = $this->jack('B.05', 5);
        PatchCordFactory::between($this->panelPort(5), $this->ports['2/0/5']);
        $this->address('10.10.10.5', $jack);
        $this->address('10.10.10.6', $jack);

        $expected = [
            ['kind' => 'address_vlan_not_carried', 'reason' => 'sw-etage-1 2/0/5 en accès VLAN 20, adresse 10.10.10.5 du VLAN 10'],
            ['kind' => 'address_vlan_not_carried', 'reason' => 'sw-etage-1 2/0/5 en accès VLAN 20, adresse 10.10.10.6 du VLAN 10'],
        ];

        $this->assertEqualsCanonicalizing($expected, $this->ofJack($jack));
        $this->assertEqualsCanonicalizing($expected, $this->ofPort($this->ports['2/0/5']));
        $this->assertEqualsCanonicalizing($expected, $this->ofPanelPort(5));
    }

    // --- Where they are shown ----------------------------------------------------

    public function test_the_pages_show_the_deviations_of_the_cabling_where_they_concern(): void
    {
        $core = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-coeur-01']);
        $uplink = PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $core->id]), 1)[0];
        $link = LinkFactory::between($this->ports['2/0/8'], $uplink);

        // B.12: patched to a linked port, nothing plugged into it. B.01: not
        // patched, an address on it. 1/0/1: patched across two rooms. B.05:
        // a VLAN not carried.
        $linked = $this->jack('B.12', 12);
        PatchCordFactory::between($this->panelPort(12), $this->ports['2/0/8']);
        $alone = $this->jack('B.01');
        $unpatched = $this->address('10.10.10.1', $alone);
        PatchCordFactory::between($this->panelPort(1), $this->ports['1/0/1']);
        $this->configure($this->ports['2/0/5'], PortMode::Access, 20);
        $vlanJack = $this->jack('B.05', 5);
        PatchCordFactory::between($this->panelPort(5), $this->ports['2/0/5']);
        $this->address('10.10.10.5', $vlanJack, subnet: null);
        $this->jack('B.06', 6);

        $reader = $this->reader();

        // The port in front of the link can be plugged into B.12.
        $unplugged = [
            'kind' => 'linked_without_plug',
            'reason' => "sw-etage-1 2/0/8 est relié à sw-coeur-01 1/0/1 et brassé vers B.12, où rien n'est branché",
            'action' => ['type' => 'plug', 'jack' => $linked->id, 'port' => $uplink->id],
        ];

        // The page of the panel: on the port of each cord and of each jack.
        $this->actingAs($reader)
            ->get(route('patch-panels.show', $this->panel))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ports.0.deviations.0.kind', 'cord_across_rooms')
                ->where('ports.4.deviations.0.kind', 'address_vlan_not_carried')
                ->where('ports.5.deviations', [])
                ->where('ports.11.deviations', [$unplugged]));

        // The list of jacks, and only those with a deviation on demand.
        $kinds = function (array $filters) use ($reader): array {
            $found = [];

            $this->actingAs($reader)
                ->get(route('jacks.index', $filters))
                ->assertInertia(function (Assert $page) use (&$found): void {
                    foreach ($page->toArray()['props']['jacks'] as $line) {
                        $found[$line['label']] = array_column($line['deviations'], 'kind');
                    }
                });

            return $found;
        };

        $this->assertSame(['B.01' => ['address_on_unpatched_jack'], 'B.05' => ['address_vlan_not_carried'], 'B.06' => [], 'B.12' => ['linked_without_plug']], $kinds([]));
        $this->assertSame(['B.01', 'B.05', 'B.12'], array_keys($kinds(['deviations' => 1])));

        // The addresses of the subnet.
        $this->actingAs($reader)
            ->get(route('subnets.show', $this->offices))
            ->assertInertia(fn (Assert $page) => $page
                ->where('addresses.0.address', '10.10.10.1')
                ->where('addresses.0.deviations', [['kind' => 'address_on_unpatched_jack', 'reason' => 'Prise B.01 non brassée']])
                ->where('addresses.1.deviations.0.kind', 'address_vlan_not_carried'));

        // The page of the device: on the line of each port, and in the
        // list of the deviations of the page.
        $this->actingAs($reader)
            ->get(route('devices.show', $this->switch))
            ->assertInertia(function (Assert $page) use ($unplugged): void {
                $props = $page->toArray()['props'];
                $ports = array_column($props['ports'], 'deviations', 'name');

                $this->assertSame(['cord_across_rooms'], array_column($ports['1/0/1'], 'kind'));
                $this->assertSame(['address_vlan_not_carried'], array_column($ports['2/0/5'], 'kind'));
                $this->assertSame([$unplugged], $ports['2/0/8']);
                $this->assertSame([], $ports['2/0/1']);

                $items = array_values(array_filter($props['linkDeviations'], fn (array $item): bool => $item['section'] === 'ports'));

                $this->assertSame(['1/0/1', '2/0/5', '2/0/8'], array_column($items, 'label'));
                // The link does not tell it again in the list.
                $this->assertSame([], array_values(array_filter($props['linkDeviations'], fn (array $item): bool => $item['section'] === 'links')));
            });

        // The line of the link, in the list of links.
        $this->actingAs($reader)
            ->get(route('links.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('links.0.links.0.id', $link->id)
                ->where('links.0.deviations', [$unplugged]));

        $this->assertModelExists($unpatched);
    }
}
