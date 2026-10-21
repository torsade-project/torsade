<?php

// torsade/tests/Feature/Network/VlanPortsTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\DeviceType;
use App\Enums\PortMode;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\Port;
use App\Models\PortAggregate;
use App\Models\PortVlan;
use App\Models\Site;
use App\Models\User;
use App\Models\Vlan;
use Database\Factories\PortFactory;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A VLAN and the ports that carry it: listed on the page of the VLAN,
 * device by device, and in the way of its deletion.
 */
class VlanPortsTest extends NetworkTestCase
{
    private User $editor;

    private Site $office;

    private Vlan $offices;

    private Vlan $voice;

    private Vlan $management;

    protected function setUp(): void
    {
        parent::setUp();

        $this->editor = $this->editor();
        $this->office = Site::factory()->create(['name' => 'Siège']);
        $this->offices = Vlan::factory()->create(['site_id' => $this->office->id, 'vid' => 10, 'name' => 'Bureautique']);
        $this->voice = Vlan::factory()->create(['site_id' => $this->office->id, 'vid' => 30, 'name' => 'Téléphonie']);
        $this->management = Vlan::factory()->create(['site_id' => $this->office->id, 'vid' => 99, 'name' => 'Administration']);
    }

    /**
     * A switch of the head office with one member per number given, each
     * with so many ports.
     *
     * @param  list<int>  $positions
     * @return array<int, list<Port>> The ports of each member, by its number.
     */
    private function switch(string $name, array $positions = [1], int $ports = 4): array
    {
        $device = Device::factory()->create(['site_id' => $this->office->id, 'name' => $name, 'type' => DeviceType::Switch]);
        $members = [];

        foreach ($positions as $position) {
            $members[$position] = PortFactory::series(DeviceMember::factory()->create(['device_id' => $device->id, 'position' => $position]), $ports);
        }

        return $members;
    }

    /**
     * Give a mode to a port or to an aggregate, with the VLANs it carries.
     *
     * @param  list<Vlan>  $tagged
     */
    private function carry(Port|PortAggregate $holder, PortMode $mode, ?Vlan $untagged = null, array $tagged = []): void
    {
        $holder->update(['mode' => $mode]);

        $key = $holder instanceof Port ? 'port_id' : 'port_aggregate_id';

        if ($untagged !== null) {
            PortVlan::factory()->create([$key => $holder->id, 'vlan_id' => $untagged->id, 'tagged' => false]);
        }

        foreach ($tagged as $vlan) {
            PortVlan::factory()->create([$key => $holder->id, 'vlan_id' => $vlan->id, 'tagged' => true]);
        }
    }

    // --- The page of the VLAN -------------------------------------------------

    public function test_the_page_of_a_vlan_lists_the_ports_and_the_aggregates_that_carry_it_device_by_device(): void
    {
        // Created out of order: the devices are listed by name.
        $floor = $this->switch('sw-etage-1');
        $core = $this->switch('sw-coeur', [2, 1]);

        // On the core: an access port, a port that carries it for the
        // voice, a trunk with it as native VLAN, a trunk that tags it, and
        // an aggregate that tags it.
        $this->carry($core[1][2], PortMode::Access, $this->offices);
        $this->carry($core[1][0], PortMode::Access, $this->voice, [$this->offices]);
        $this->carry($core[2][0], PortMode::Trunk, $this->offices, [$this->voice]);
        $this->carry($core[2][3], PortMode::Trunk, $this->management, [$this->voice, $this->offices]);

        $coreDevice = Device::query()->where('name', 'sw-coeur')->sole();
        $aggregate = PortAggregate::factory()->create(['device_id' => $coreDevice->id, 'name' => 'Po1']);
        $core[1][3]->update(['port_aggregate_id' => $aggregate->id]);
        $this->carry($aggregate, PortMode::Trunk, $this->management, [$this->offices]);

        // On the floor switch: one access port.
        $this->carry($floor[1][1], PortMode::Access, $this->offices);

        // A port that carries another VLAN only is not listed.
        $this->carry($floor[1][2], PortMode::Access, $this->voice);

        $this->actingAs($this->reader())
            ->get(route('vlans.show', $this->offices))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('vlans/Show')
                ->where('carriers', [
                    [
                        'device' => ['id' => $coreDevice->id, 'name' => 'sw-coeur'],
                        // The ports in the order of the device, member by
                        // member, then its aggregates.
                        'carriers' => [
                            ['kind' => 'port', 'name' => '1/0/1', 'member' => 1, 'role' => 'voice', 'role_label' => 'Voix'],
                            ['kind' => 'port', 'name' => '1/0/3', 'member' => 1, 'role' => 'untagged', 'role_label' => 'Non étiqueté'],
                            ['kind' => 'port', 'name' => '2/0/1', 'member' => 2, 'role' => 'native', 'role_label' => 'Natif'],
                            ['kind' => 'port', 'name' => '2/0/4', 'member' => 2, 'role' => 'tagged', 'role_label' => 'Étiqueté'],
                            ['kind' => 'aggregate', 'name' => 'Po1', 'member' => null, 'role' => 'tagged', 'role_label' => 'Étiqueté'],
                        ],
                    ],
                    [
                        'device' => ['id' => $floor[1][1]->device_id, 'name' => 'sw-etage-1'],
                        'carriers' => [
                            ['kind' => 'port', 'name' => '1/0/2', 'member' => 1, 'role' => 'untagged', 'role_label' => 'Non étiqueté'],
                        ],
                    ],
                ]));

        // A VLAN no port carries.
        $this->actingAs($this->reader())
            ->get(route('vlans.show', Vlan::factory()->create(['site_id' => $this->office->id, 'vid' => 50, 'name' => 'Vidéo'])))
            ->assertInertia(fn (Assert $page) => $page->where('carriers', []));
    }

    // --- Deleting a VLAN ------------------------------------------------------

    public function test_a_vlan_is_not_deleted_while_a_port_or_an_aggregate_carries_it(): void
    {
        $core = $this->switch('sw-coeur');
        $floor = $this->switch('sw-etage-1');

        $this->carry($core[1][0], PortMode::Access, $this->offices);

        // One port: the message names its device and the port.
        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('vlans.destroy', $this->offices)),
            'vlan',
            "Un port ou un agrégat porte encore ce VLAN : sw-coeur (1/0/1). Retirez-lui ce VLAN d'abord.",
        );

        // Several, on two devices, an aggregate among them.
        $aggregate = PortAggregate::factory()->create(['device_id' => $core[1][0]->device_id, 'name' => 'Po1']);

        $this->carry($aggregate, PortMode::Trunk, null, [$this->offices]);
        $this->carry($core[1][2], PortMode::Trunk, $this->management, [$this->offices]);
        $this->carry($floor[1][3], PortMode::Access, $this->voice, [$this->offices]);

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('vlans.destroy', $this->offices)),
            'vlan',
            "4 ports ou agrégats portent encore ce VLAN : sw-coeur (1/0/1, 1/0/3, Po1), sw-etage-1 (1/0/4). Retirez-leur ce VLAN d'abord.",
        );

        $this->assertDatabaseHas('vlans', ['id' => $this->offices->id]);
        $this->assertSame(0, $this->countEntries(ActivityEvent::VlanDeleted));

        // Once nothing carries it, it is deleted.
        PortVlan::query()->where('vlan_id', $this->offices->id)->delete();

        $this->actingAs($this->editor)->delete(route('vlans.destroy', $this->offices))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('vlans', ['id' => $this->offices->id]);
        // What the ports carry of the other VLANs is still there.
        $this->assertDatabaseCount('port_vlans', 2);
    }

    public function test_the_refusal_names_ten_ports_at_most_then_counts_the_others(): void
    {
        $core = $this->switch('sw-coeur', [1], 8);
        $floor = $this->switch('sw-etage-1', [1], 8);

        foreach ([...$core[1], ...$floor[1]] as $port) {
            $this->carry($port, PortMode::Access, $this->offices);
        }

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('vlans.destroy', $this->offices)),
            'vlan',
            "16 ports ou agrégats portent encore ce VLAN : sw-coeur (1/0/1, 1/0/2, 1/0/3, 1/0/4, 1/0/5, 1/0/6, 1/0/7, 1/0/8), sw-etage-1 (1/0/1, 1/0/2) et 6 de plus. Retirez-leur ce VLAN d'abord.",
        );

        // Eleven on one device: the second device is counted, not named.
        $large = $this->switch('sw-accueil', [1], 11);

        foreach ($large[1] as $port) {
            $this->carry($port, PortMode::Trunk, null, [$this->voice]);
        }

        // An access port that already carries its own VLAN, with the
        // voice VLAN as well.
        PortVlan::factory()->create(['port_id' => $core[1][0]->id, 'vlan_id' => $this->voice->id, 'tagged' => true]);

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('vlans.destroy', $this->voice)),
            'vlan',
            "12 ports ou agrégats portent encore ce VLAN : sw-accueil (1/0/1, 1/0/2, 1/0/3, 1/0/4, 1/0/5, 1/0/6, 1/0/7, 1/0/8, 1/0/9, 1/0/10) et 2 de plus. Retirez-leur ce VLAN d'abord.",
        );
    }

    public function test_the_subnet_of_a_vlan_is_told_before_its_ports(): void
    {
        $core = $this->switch('sw-coeur');

        $this->carry($core[1][0], PortMode::Access, $this->offices);
        $this->subnet('10.10.10.0/24', ['vlan_id' => $this->offices->id, 'site_id' => $this->office->id]);

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('vlans.destroy', $this->offices)),
            'vlan',
            "Le sous-réseau 10.10.10.0/24 est rattaché à ce VLAN : supprimez-le ou détachez-le d'abord.",
        );
    }

    // --- Moving a VLAN --------------------------------------------------------

    public function test_a_vlan_that_ports_carry_changes_site_and_the_ports_keep_it(): void
    {
        $workshop = Site::factory()->create(['name' => 'Atelier', 'code' => 'ATL']);
        $core = $this->switch('sw-coeur');

        $this->carry($core[1][0], PortMode::Access, $this->offices);
        $this->carry($core[1][1], PortMode::Trunk, $this->management, [$this->offices, $this->voice]);

        $payload = ['site_id' => $workshop->id, 'vid' => 10, 'name' => 'Bureautique', 'status' => 'active'];

        $this->actingAs($this->editor)->put(route('vlans.update', $this->offices), $payload)->assertSessionHasNoErrors();

        $this->assertSame($workshop->id, $this->offices->refresh()->site_id);

        // The ports still carry it, now a VLAN of another site.
        $this->assertSame(2, PortVlan::query()->where('vlan_id', $this->offices->id)->count());

        $this->actingAs($this->reader())
            ->get(route('devices.show', Device::query()->where('name', 'sw-coeur')->sole()))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ports.0.summary', 'Accès ATL 10')
                ->where('ports.1.summary', 'Trunk, natif 99 : 30 · ATL 10'));
    }
}
