<?php

// torsade/tests/Feature/Network/OppositeAggregateTest.php

namespace Tests\Feature\Network;

use App\Enums\AggregateProtocol;
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
use App\Services\Network\OppositeAggregates;
use App\Services\Network\PhysicalDiagram;
use Database\Factories\LinkFactory;
use Database\Factories\PortFactory;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * An aggregate declared on one side only, whose ports in front are all on
 * one device and in no aggregate: its deviation offers to create the
 * aggregate in front, on the page of each device, on the page of the links
 * and on the physical diagram; the page of the device in front opens the
 * window of a new aggregate filled in from it. It is not offered when the
 * ports in front are in aggregates, when the links lead to several
 * devices, nor to a reader.
 */
class OppositeAggregateTest extends NetworkTestCase
{
    private Site $office;

    /** @var array<string, Device> */
    private array $devices = [];

    /** @var array<string, array<string, Port>> */
    private array $ports = [];

    /** @var array<int, Vlan> */
    private array $vlans = [];

    private PortAggregate $po1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = Site::factory()->create(['name' => 'Siège']);

        foreach ([10 => 'Bureautique', 20 => 'Serveurs', 99 => 'Administration'] as $vid => $name) {
            $this->vlans[$vid] = Vlan::factory()->create(['site_id' => $this->office->id, 'vid' => $vid, 'name' => $name]);
        }

        // A stack and its aggregate, static, in trunk: native 99, tagged
        // 10 and 20; two links to two loose ports of the core switch.
        $this->device('sw-serveurs');
        $this->device('sw-coeur');
        $this->device('sw-quai');

        $this->po1 = PortAggregate::factory()->create([
            'device_id' => $this->devices['sw-serveurs']->id,
            'name' => 'Po1',
            'protocol' => AggregateProtocol::Static,
            'mode' => PortMode::Trunk,
        ]);

        PortVlan::factory()->create(['port_aggregate_id' => $this->po1->id, 'vlan_id' => $this->vlans[99]->id, 'tagged' => false]);
        PortVlan::factory()->create(['port_aggregate_id' => $this->po1->id, 'vlan_id' => $this->vlans[10]->id, 'tagged' => true]);
        PortVlan::factory()->create(['port_aggregate_id' => $this->po1->id, 'vlan_id' => $this->vlans[20]->id, 'tagged' => true]);

        foreach (['1/0/1', '1/0/2'] as $name) {
            $this->ports['sw-serveurs'][$name]->update(['port_aggregate_id' => $this->po1->id]);
        }

        LinkFactory::between($this->ports['sw-serveurs']['1/0/1'], $this->ports['sw-coeur']['1/0/4']);
        LinkFactory::between($this->ports['sw-serveurs']['1/0/2'], $this->ports['sw-coeur']['1/0/3']);
    }

    private function device(string $name): Device
    {
        $device = Device::factory()->create(['site_id' => $this->office->id, 'name' => $name, 'type' => DeviceType::Switch]);

        foreach (PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $device->id, 'position' => 1]), 6) as $port) {
            $this->ports[$name][$port->name] = $port;
        }

        return $this->devices[$name] = $device;
    }

    /**
     * The deviations "aggregate on one side only" of the page of a device.
     *
     * @return list<array<string, mixed>>
     */
    private function oneSidedOn(string $name, ?User $user = null): array
    {
        $found = [];

        $this->actingAs($user ?? $this->editor())
            ->get(route('devices.show', $this->devices[$name]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$found): void {
                foreach ($page->toArray()['props']['linkDeviations'] as $item) {
                    foreach ($item['deviations'] as $deviation) {
                        if ($deviation['kind'] === 'aggregate_one_sided') {
                            $found[] = $deviation;
                        }
                    }
                }
            });

        return $found;
    }

    /**
     * The window filled in on the page of a device, from the aggregate in
     * front.
     *
     * @return array<string, mixed>|null
     */
    private function draftOn(string $name, int $origin, ?User $user = null): ?array
    {
        $draft = null;

        $this->actingAs($user ?? $this->editor())
            ->get(route('devices.show', [$this->devices[$name], 'opposite_of' => $origin]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$draft): void {
                $draft = $page->toArray()['props']['aggregateDraft'];
            });

        return $draft;
    }

    private function action(): array
    {
        return ['type' => 'create_opposite_aggregate', 'aggregate' => $this->po1->id, 'device' => $this->devices['sw-coeur']->id];
    }

    public function test_the_deviation_offers_the_aggregate_in_front_on_both_pages_on_the_links_and_on_the_diagram(): void
    {
        // On the page of each of the two devices.
        foreach (['sw-serveurs', 'sw-coeur'] as $name) {
            $deviations = $this->oneSidedOn($name);

            $this->assertNotEmpty($deviations, $name);

            foreach ($deviations as $deviation) {
                $this->assertSame($this->action(), $deviation['action'] ?? null, $name);
            }
        }

        // On the page of the links.
        $this->actingAs($this->editor())
            ->get(route('links.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $actions = [];

                foreach ($page->toArray()['props']['links'] as $line) {
                    foreach ([...($line['deviations'] ?? []), ...array_merge(...array_map(fn (array $link): array => $link['deviations'] ?? [], $line['links'] ?? []))] as $deviation) {
                        if (isset($deviation['action'])) {
                            $actions[] = $deviation['action'];
                        }
                    }
                }

                $this->assertContains($this->action(), $actions);
            });

        // On the physical diagram, in the list of its deviations.
        $diagram = app(PhysicalDiagram::class)->of($this->office);
        $actions = array_column(array_merge(...array_column($diagram['links'], 'deviations')), 'action');

        $this->assertContains($this->action(), $actions);
    }

    public function test_the_page_of_the_device_in_front_opens_the_window_filled_in_from_the_aggregate(): void
    {
        $this->assertSame([
            'origin' => 'sw-serveurs › Po1',
            'name' => 'Po1',
            'protocol' => 'static',
            'mode' => 'trunk',
            'untagged_vlan_id' => $this->vlans[99]->id,
            'tagged_vlan_ids' => [$this->vlans[10]->id, $this->vlans[20]->id],
            // In the order of the device.
            'port_ids' => [$this->ports['sw-coeur']['1/0/3']->id, $this->ports['sw-coeur']['1/0/4']->id],
        ], $this->draftOn('sw-coeur', $this->po1->id));

        // The name follows the series of the device.
        foreach (['LAG1', 'LAG2'] as $name) {
            PortAggregate::factory()->create(['device_id' => $this->devices['sw-coeur']->id, 'name' => $name]);
        }

        $this->assertSame('LAG3', $this->draftOn('sw-coeur', $this->po1->id)['name'] ?? null);

        // Nothing without an aggregate in the address, nor for the device
        // of the aggregate itself, nor for an aggregate that is gone.
        $this->assertNull($this->draftOn('sw-serveurs', $this->po1->id));
        $this->assertNull($this->draftOn('sw-quai', $this->po1->id));
        $this->assertNull($this->draftOn('sw-coeur', 999999999));
    }

    public function test_once_saved_the_deviation_is_gone_and_the_links_are_one_aggregated_link(): void
    {
        $draft = $this->draftOn('sw-coeur', $this->po1->id);

        $this->assertIsArray($draft);

        $this->actingAs($this->editor())
            ->post(route('port-aggregates.store', $this->devices['sw-coeur']), [
                'name' => $draft['name'],
                'protocol' => $draft['protocol'],
                'description' => '',
                'port_ids' => $draft['port_ids'],
                'mode' => $draft['mode'],
                'untagged_vlan_id' => $draft['untagged_vlan_id'],
                'tagged_vlan_ids' => $draft['tagged_vlan_ids'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame([], $this->oneSidedOn('sw-serveurs'));
        $this->assertSame([], $this->oneSidedOn('sw-coeur'));
        $this->assertNull($this->draftOn('sw-coeur', $this->po1->id));

        // One link drawn between the two aggregates.
        $diagram = app(PhysicalDiagram::class)->of($this->office);

        $this->assertSame(['Po1 ↔ Po1 · 2 liens'], array_values(array_filter(array_column($diagram['links'], 'label'))));
    }

    public function test_it_is_not_offered_when_a_port_in_front_is_in_an_aggregate(): void
    {
        $po9 = PortAggregate::factory()->create(['device_id' => $this->devices['sw-coeur']->id, 'name' => 'Po9']);

        $this->ports['sw-coeur']['1/0/3']->update(['port_aggregate_id' => $po9->id]);

        foreach (['sw-serveurs', 'sw-coeur'] as $name) {
            foreach ($this->oneSidedOn($name) as $deviation) {
                $this->assertArrayNotHasKey('action', $deviation, $name);
            }
        }

        $this->assertNull($this->draftOn('sw-coeur', $this->po1->id));
    }

    public function test_it_is_not_offered_when_the_links_lead_to_several_devices(): void
    {
        // The second link now leads to the switch of the quay.
        $this->ports['sw-serveurs']['1/0/2']->linkEnd?->link->delete();

        LinkFactory::between($this->ports['sw-serveurs']['1/0/2'], $this->ports['sw-quai']['1/0/1']);

        // Neither on the page of the aggregate, nor on the page of a device
        // in front, which shows only one of its links.
        foreach (['sw-serveurs', 'sw-coeur', 'sw-quai'] as $name) {
            $deviations = $this->oneSidedOn($name);

            $this->assertNotEmpty($deviations, $name);

            foreach ($deviations as $deviation) {
                $this->assertArrayNotHasKey('action', $deviation, $name);
            }
        }

        $this->assertNull($this->draftOn('sw-coeur', $this->po1->id));
        $this->assertNull($this->draftOn('sw-quai', $this->po1->id));
    }

    public function test_a_reader_is_not_given_the_window(): void
    {
        $this->assertNull($this->draftOn('sw-coeur', $this->po1->id, $this->reader()));

        // The deviation tells what lifts it; the page offers the action to
        // whoever may change the network only, and the server refuses a
        // reader the aggregate anyway.
        $this->actingAs($this->reader())
            ->post(route('port-aggregates.store', $this->devices['sw-coeur']), ['name' => 'Po1', 'protocol' => 'lacp', 'port_ids' => []])
            ->assertForbidden();
    }

    public function test_the_next_name_follows_the_series_of_the_device(): void
    {
        foreach ([
            [[], 'Po1'],
            [['LAG1', 'LAG2'], 'LAG3'],
            [['lag 1'], 'lag 2'],
            [['Po1', 'Po3'], 'Po4'],
            [['Trk1', 'Po2'], 'Po3'],
            [['uplink'], 'Po1'],
            // The series of the last aggregate that ends with a number,
            // as it is written; a name taken, whatever its case, skipped.
            [['uplink', 'po1'], 'po2'],
            [['lag3', 'LAG2'], 'LAG4'],
        ] as [$names, $expected]) {
            $this->assertSame($expected, OppositeAggregates::nextName($names), implode(', ', $names));
        }
    }
}
