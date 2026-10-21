<?php

// torsade/tests/Feature/Network/PortAggregateTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\AggregateProtocol;
use App\Enums\DeviceType;
use App\Enums\PortMode;
use App\Models\Activity;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\Port;
use App\Models\PortAggregate;
use App\Models\PortVlan;
use App\Models\Site;
use App\Models\User;
use App\Models\Vlan;
use Database\Factories\PortFactory;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aggregates of ports: several ports of one device used as one link, on one
 * or several of its members, with a mode and VLANs of their own.
 */
class PortAggregateTest extends NetworkTestCase
{
    private User $editor;

    private Site $office;

    private Device $stack;

    /** @var list<Port> The ports of the member 1. */
    private array $first;

    /** @var list<Port> The ports of the member 2. */
    private array $second;

    /** @var array<int, Vlan> The VLANs of the head office, by number. */
    private array $vlans = [];

    private Vlan $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->editor = $this->editor();
        $this->office = Site::factory()->create(['name' => 'Siège']);
        $this->stack = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-coeur', 'type' => DeviceType::Switch]);
        $this->first = PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $this->stack->id, 'position' => 1]), 4);
        $this->second = PortFactory::series(DeviceMember::factory()->secondary()->create(['device_id' => $this->stack->id, 'position' => 2]), 4);

        foreach ([10 => 'Bureautique', 20 => 'Serveurs', 30 => 'Téléphonie', 99 => 'Administration'] as $vid => $name) {
            $this->vlans[$vid] = Vlan::factory()->create(['site_id' => $this->office->id, 'vid' => $vid, 'name' => $name]);
        }

        $this->foreign = Vlan::factory()->create(['site_id' => Site::factory()->create(['name' => 'Atelier'])->id, 'vid' => 10, 'name' => 'Bureautique']);
    }

    /**
     * What the window of an aggregate sends, with VLANs given by their
     * numbers.
     *
     * @param  list<Port>  $ports
     * @param  list<int>  $tagged
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $ports = [], ?string $mode = null, ?int $untagged = null, array $tagged = [], array $overrides = []): array
    {
        return [
            'name' => 'Po1',
            'protocol' => 'lacp',
            'description' => '',
            'port_ids' => array_map(fn (Port $port): int => $port->id, $ports),
            'mode' => $mode,
            'untagged_vlan_id' => $untagged === null ? null : $this->vlans[$untagged]->id,
            'tagged_vlan_ids' => array_map(fn (int $vid): int => $this->vlans[$vid]->id, $tagged),
            ...$overrides,
        ];
    }

    /**
     * @param  list<Port>  $ports
     * @param  list<int>  $tagged
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function create(array $ports = [], ?string $mode = null, ?int $untagged = null, array $tagged = [], array $overrides = [], ?Device $device = null): TestResponse
    {
        return $this->actingAs($this->editor)->post(route('port-aggregates.store', $device ?? $this->stack), $this->payload($ports, $mode, $untagged, $tagged, $overrides));
    }

    /**
     * @param  list<Port>  $ports
     * @param  list<int>  $tagged
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function update(PortAggregate $aggregate, array $ports = [], ?string $mode = null, ?int $untagged = null, array $tagged = [], array $overrides = []): TestResponse
    {
        return $this->actingAs($this->editor)->put(route('port-aggregates.update', $aggregate), $this->payload($ports, $mode, $untagged, $tagged, $overrides));
    }

    /**
     * The names of the ports of an aggregate, in the order of the device.
     *
     * @return list<string>
     */
    private function portsOf(PortAggregate $aggregate): array
    {
        return array_values(Port::query()->where('port_aggregate_id', $aggregate->id)->orderBy('name')->pluck('name')->all());
    }

    /**
     * The mode of an aggregate or of a port, and what it carries as the
     * activity log writes it.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function carried(Port|PortAggregate $holder): array
    {
        $holder = $holder->newQuery()->with('portVlans.vlan')->findOrFail($holder->id);

        return [$holder->mode?->value, $holder->vlanAuditValue()];
    }

    // --- Creation -------------------------------------------------------------

    public function test_an_editor_creates_an_aggregate_of_ports_of_several_members(): void
    {
        $this->create([$this->first[2], $this->second[2], $this->first[3]], 'trunk', 99, [10, 20], ['description' => 'Vers le cœur de réseau'])
            ->assertRedirect(route('devices.show', $this->stack))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Agrégat créé.');

        $aggregate = PortAggregate::query()->sole();

        $this->assertSame(
            [$this->stack->id, 'Po1', AggregateProtocol::Lacp, PortMode::Trunk, 'Vers le cœur de réseau'],
            [$aggregate->device_id, $aggregate->name, $aggregate->protocol, $aggregate->mode, $aggregate->description],
        );
        $this->assertSame(['1/0/3', '1/0/4', '2/0/3'], $this->portsOf($aggregate));

        // The mode and the VLANs are those of the aggregate: its ports have
        // none of their own.
        $this->assertSame(['trunk', 'U 99 ; T 10, 20'], $this->carried($aggregate));
        $this->assertSame([null, null], $this->carried($this->first[2]));
        $this->assertSame(0, PortVlan::query()->whereNotNull('port_id')->count());
        $this->assertSame(3, PortVlan::query()->where('port_aggregate_id', $aggregate->id)->count());
    }

    public function test_only_the_name_and_the_protocol_are_required_and_an_aggregate_may_have_no_port(): void
    {
        $this->actingAs($this->editor)
            ->post(route('port-aggregates.store', $this->stack), ['name' => 'lag 1', 'protocol' => 'static'])
            ->assertSessionHasNoErrors();

        $aggregate = PortAggregate::query()->sole();

        $this->assertSame(['lag 1', AggregateProtocol::Static, null, null], [$aggregate->name, $aggregate->protocol, $aggregate->mode, $aggregate->description]);
        $this->assertSame([], $this->portsOf($aggregate));
        $this->assertDatabaseCount('port_vlans', 0);

        $this->actingAs($this->editor)
            ->post(route('port-aggregates.store', $this->stack), [])
            ->assertSessionHasErrors(['name', 'protocol']);

        $this->assertDatabaseCount('port_aggregates', 1);
    }

    public function test_the_shape_of_an_aggregate_is_checked(): void
    {
        $this->assertRefused($this->create([], null, null, [], ['name' => str_repeat('a', 33)]), 'name');
        $this->assertRefused($this->create([], null, null, [], ['protocol' => 'pagp']), 'protocol');
        $this->assertRefused($this->create([], null, null, [], ['description' => str_repeat('a', 1001)]), 'description');
        $this->assertRefused($this->create([], null, null, [], ['port_ids' => 'tous']), 'port_ids');
        $this->assertRefused($this->create([], null, null, [], ['port_ids' => ['un']]), 'port_ids.0');
        $this->assertRefused($this->create([], null, null, [], ['port_ids' => [$this->first[0]->id, $this->first[0]->id]]), 'port_ids.0');
        $this->assertRefused($this->create([], 'hybrid'), 'mode');
        $this->assertRefused($this->create([], 'trunk', null, [], ['tagged_vlan_ids' => 'tous']), 'tagged_vlan_ids');

        $this->assertDatabaseCount('port_aggregates', 0);

        // At the limits, an aggregate is accepted.
        $this->create([], null, null, [], ['name' => str_repeat('a', 32), 'description' => str_repeat('b', 1000)])->assertSessionHasNoErrors();
    }

    public function test_the_name_of_an_aggregate_is_unique_within_its_device_whatever_its_case(): void
    {
        $this->create()->assertSessionHasNoErrors();

        $this->assertRefused($this->create([], null, null, [], ['name' => 'PO1']), 'name', "L'équipement a déjà un agrégat nommé Po1.");

        // Another device has its own names.
        $other = Device::factory()->create(['site_id' => $this->office->id]);

        $this->create([], null, null, [], ['name' => 'PO1'], $other)->assertSessionHasNoErrors();

        // An aggregate keeps its own name, and may change its case.
        $aggregate = PortAggregate::query()->where('device_id', $this->stack->id)->sole();
        $second = PortAggregate::factory()->create(['device_id' => $this->stack->id, 'name' => 'Po2']);

        $this->update($aggregate, [], null, null, [], ['name' => 'po1'])->assertSessionHasNoErrors();
        $this->assertRefused($this->update($second, [], null, null, [], ['name' => 'PO1']), 'name', "L'équipement a déjà un agrégat nommé po1.");

        $this->assertSame('Po2', $second->refresh()->name);
    }

    public function test_the_ports_of_an_aggregate_belong_to_its_device_and_to_no_other_aggregate(): void
    {
        $elsewhere = Port::factory()->create(['device_member_id' => DeviceMember::factory()->create(['position' => 1])->id, 'name' => 'eth0', 'position' => 1]);

        // A port of another device, or a port that is gone.
        $this->assertRefused(
            $this->create([$this->first[0], $elsewhere]),
            'port_ids',
            "Certains de ces ports n'existent plus : rechargez la page.",
        );
        $this->assertRefused($this->create([], null, null, [], ['port_ids' => [$this->first[0]->id, 999999]]), 'port_ids');

        $this->assertDatabaseCount('port_aggregates', 0);

        // A port is part of one aggregate at most: the refusal names the
        // ports, in the order of the device.
        $this->create([$this->second[0], $this->first[1], $this->first[0]])->assertSessionHasNoErrors();

        $this->assertRefused(
            $this->create([$this->first[0], $this->first[2]], null, null, [], ['name' => 'Po2']),
            'port_ids',
            "Le port 1/0/1 fait déjà partie d'un autre agrégat : retirez-le d'abord de celui-ci.",
        );
        $this->assertRefused(
            $this->create([$this->second[0], $this->first[2], $this->first[1], $this->first[0]], null, null, [], ['name' => 'Po2']),
            'port_ids',
            "Les ports 1/0/1, 1/0/2, 2/0/1 font déjà partie d'un autre agrégat : retirez-les d'abord de celui-ci.",
        );

        $this->assertDatabaseCount('port_aggregates', 1);
        $this->assertNull($this->first[2]->refresh()->port_aggregate_id);
    }

    public function test_a_port_that_joins_an_aggregate_loses_its_own_mode_and_its_own_vlans(): void
    {
        $configure = fn (Port $port, array $mode): TestResponse => $this->actingAs($this->editor)->put(route('ports.update', $port), [
            'name' => $port->name, 'position' => $port->position, 'connector' => 'rj45', 'max_speed' => '1g', 'poe_type' => 'none', 'enabled' => true, ...$mode,
        ]);

        $configure($this->first[0], ['mode' => 'access', 'untagged_vlan_id' => $this->vlans[10]->id, 'tagged_vlan_ids' => [$this->vlans[30]->id]])->assertSessionHasNoErrors();
        $configure($this->second[0], ['mode' => 'trunk'])->assertSessionHasNoErrors();
        $configure($this->first[1], ['mode' => 'access', 'untagged_vlan_id' => $this->vlans[20]->id])->assertSessionHasNoErrors();

        $changes = $this->countEntries(ActivityEvent::PortUpdated);

        $this->create([$this->first[0], $this->second[0], $this->second[1]], 'trunk', 99, [10])->assertSessionHasNoErrors();

        $aggregate = PortAggregate::query()->sole();

        $this->assertSame([null, null], $this->carried($this->first[0]));
        $this->assertSame([null, null], $this->carried($this->second[0]));
        $this->assertSame(['trunk', 'U 99 ; T 10'], $this->carried($aggregate));

        // The port that did not join keeps what it carries.
        $this->assertSame(['access', 'U 20'], $this->carried($this->first[1]));
        $this->assertSame([$this->first[1]->id], PortVlan::query()->whereNotNull('port_id')->pluck('port_id')->all());

        // Each port that joined has its entry: what it lost, and its
        // aggregate.
        $this->assertSame($changes + 3, $this->countEntries(ActivityEvent::PortUpdated));

        $entries = Activity::query()->where('event', ActivityEvent::PortUpdated->value)->orderByDesc('id')->limit(3)->get()
            ->mapWithKeys(fn (Activity $entry): array => [(string) $entry->getProperty('label') => $entry->attribute_changes?->toArray()])
            ->all();

        $this->assertSame(
            ['old' => ['aggregate' => null, 'mode' => 'access', 'vlans' => 'U 10 ; T 30'], 'attributes' => ['aggregate' => 'Po1', 'mode' => null, 'vlans' => null]],
            $entries['sw-coeur (Siège) › 1/0/1'],
        );
        $this->assertSame(
            ['old' => ['aggregate' => null, 'mode' => 'trunk'], 'attributes' => ['aggregate' => 'Po1', 'mode' => null]],
            $entries['sw-coeur (Siège) › 2/0/1'],
        );
        $this->assertSame(
            ['old' => ['aggregate' => null], 'attributes' => ['aggregate' => 'Po1']],
            $entries['sw-coeur (Siège) › 2/0/2'],
        );
    }

    public function test_the_mode_and_the_vlans_of_an_aggregate_follow_the_rules_of_a_port(): void
    {
        $ports = [$this->first[0], $this->second[0]];

        $this->assertRefused($this->create($ports, 'access'), 'untagged_vlan_id', 'Le mode accès porte un VLAN non étiqueté : choisissez-le.');
        $this->assertRefused($this->create($ports, 'access', 10, [20, 30]), 'tagged_vlan_ids');
        $this->assertRefused($this->create($ports, 'trunk', 99, [99]), 'tagged_vlan_ids', 'Le VLAN natif ne peut pas être aussi étiqueté.');
        $this->assertRefused($this->create($ports, null, 10), 'mode', "Sans mode, aucun VLAN n'est porté : choisissez un mode.");
        // A VLAN of another site, but not two VLANs of one number.
        $this->assertRefused(
            $this->create($ports, 'trunk', null, [], ['tagged_vlan_ids' => [$this->foreign->id, $this->vlans[10]->id]]),
            'tagged_vlan_ids',
            'Le VLAN 10 du site Siège et le VLAN 10 du site Atelier ne peuvent pas passer sur le même port.',
        );

        // Nothing was created, and no port was touched.
        $this->assertDatabaseCount('port_aggregates', 0);
        $this->assertDatabaseCount('port_vlans', 0);
        $this->assertSame(0, Port::query()->whereNotNull('port_aggregate_id')->count());

        // An access aggregate, with its voice VLAN.
        $this->create($ports, 'access', 10, [30])->assertSessionHasNoErrors();

        $this->assertSame(['access', 'U 10 ; T 30'], $this->carried(PortAggregate::query()->sole()));
    }

    // --- Update and deletion --------------------------------------------------

    public function test_an_editor_updates_an_aggregate_its_ports_and_what_it_carries(): void
    {
        $this->create([$this->first[0], $this->second[0]], 'trunk', 99, [10, 20]);

        $aggregate = PortAggregate::query()->sole();

        // One port leaves, one stays, one joins; another name, another
        // protocol, other VLANs.
        $this->update($aggregate, [$this->second[0], $this->second[1]], 'trunk', null, [20, 30], ['name' => 'Trk1', 'protocol' => 'static', 'description' => 'Vers la baie B'])
            ->assertRedirect(route('devices.show', $this->stack))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Agrégat modifié.');

        $aggregate->refresh();

        $this->assertSame(['Trk1', AggregateProtocol::Static, 'Vers la baie B'], [$aggregate->name, $aggregate->protocol, $aggregate->description]);
        $this->assertSame(['2/0/1', '2/0/2'], $this->portsOf($aggregate));
        $this->assertSame(['trunk', 'T 20, 30'], $this->carried($aggregate));

        // The port that left is free, without a mode nor a VLAN.
        $left = $this->first[0]->refresh();

        $this->assertNull($left->port_aggregate_id);
        $this->assertSame([null, null], $this->carried($left));

        // Without a mode: the aggregate carries nothing any more.
        $this->update($aggregate, [$this->second[0], $this->second[1]], null, null, [], ['name' => 'Trk1'])->assertSessionHasNoErrors();

        $this->assertSame([null, null], $this->carried($aggregate));
        $this->assertDatabaseCount('port_vlans', 0);

        // Without a port: it stays declared.
        $this->update($aggregate, [], null, null, [], ['name' => 'Trk1'])->assertSessionHasNoErrors();

        $this->assertSame([], $this->portsOf($aggregate));
        $this->assertDatabaseHas('port_aggregates', ['id' => $aggregate->id]);
    }

    public function test_a_refused_update_changes_nothing(): void
    {
        $this->create([$this->first[0], $this->second[0]], 'trunk', 99, [10]);
        $this->create([$this->first[3]], null, null, [], ['name' => 'Po2']);

        $aggregate = PortAggregate::query()->where('name', 'Po1')->sole();

        // A port of the other aggregate among the new ones.
        $this->assertRefused(
            $this->update($aggregate, [$this->first[1], $this->first[3]], 'access', 10, [], ['name' => 'Renommé']),
            'port_ids',
            "Le port 1/0/4 fait déjà partie d'un autre agrégat : retirez-le d'abord de celui-ci.",
        );
        // A mode that breaks a rule.
        $this->assertRefused($this->update($aggregate, [$this->first[1]], 'access', null, [], ['name' => 'Renommé']), 'untagged_vlan_id');

        $this->assertSame('Po1', $aggregate->refresh()->name);
        $this->assertSame(['1/0/1', '2/0/1'], $this->portsOf($aggregate));
        $this->assertSame(['trunk', 'U 99 ; T 10'], $this->carried($aggregate));
        $this->assertSame(0, $this->countEntries(ActivityEvent::PortAggregateUpdated));
    }

    public function test_deleting_an_aggregate_frees_its_ports_without_a_mode_nor_a_vlan(): void
    {
        $this->create([$this->first[0], $this->second[0]], 'trunk', 99, [10, 20]);
        $this->create([$this->first[3]], 'access', 30, [], ['name' => 'Po2']);

        $aggregate = PortAggregate::query()->where('name', 'Po1')->sole();
        $kept = PortAggregate::query()->where('name', 'Po2')->sole();

        $this->actingAs($this->editor)
            ->delete(route('port-aggregates.destroy', $aggregate))
            ->assertRedirect(route('devices.show', $this->stack))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Agrégat supprimé.');

        $this->assertSame(['Po2'], PortAggregate::query()->pluck('name')->all());
        $this->assertDatabaseCount('ports', 8);

        foreach ([$this->first[0], $this->second[0]] as $port) {
            $this->assertNull($port->refresh()->port_aggregate_id);
            $this->assertSame([null, null], $this->carried($port));
        }

        // What the other aggregate carries is still there.
        $this->assertSame([$kept->id], PortVlan::query()->pluck('port_aggregate_id')->all());
        $this->assertSame($kept->id, $this->first[3]->refresh()->port_aggregate_id);
    }

    // --- The device -----------------------------------------------------------

    public function test_the_page_of_a_device_lists_its_aggregates_and_names_the_aggregate_of_each_port(): void
    {
        $this->create([$this->first[0], $this->second[0]], 'trunk', 99, [20, 10], ['name' => 'Po2', 'description' => 'Vers le cœur']);
        $this->create([], null, null, [], ['name' => 'Po1', 'protocol' => 'static']);

        // The aggregate of another device is not listed.
        PortAggregate::factory()->create();

        $first = PortAggregate::query()->where('name', 'Po1')->sole();
        $second = PortAggregate::query()->where('name', 'Po2')->sole();

        $this->actingAs($this->reader())
            ->get(route('devices.show', $this->stack))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // By name.
                ->where('aggregates', [
                    [
                        'id' => $first->id, 'name' => 'Po1', 'protocol' => 'static', 'protocol_label' => 'Statique',
                        'mode' => null, 'summary' => null, 'untagged_vlan_id' => null, 'tagged_vlan_ids' => [], 'description' => null, 'deviations' => [],
                    ],
                    [
                        'id' => $second->id, 'name' => 'Po2', 'protocol' => 'lacp', 'protocol_label' => 'LACP',
                        'mode' => 'trunk', 'summary' => 'Trunk, natif 99 : 10, 20', 'untagged_vlan_id' => $this->vlans[99]->id,
                        'tagged_vlan_ids' => [$this->vlans[10]->id, $this->vlans[20]->id], 'description' => 'Vers le cœur', 'deviations' => [],
                    ],
                ])
                ->where('aggregateProtocols', [
                    ['value' => 'lacp', 'label' => 'LACP'],
                    ['value' => 'static', 'label' => 'Statique'],
                ])
                // A port of an aggregate names it, and has no summary of
                // its own.
                ->where('ports.0.name', '1/0/1')
                ->where('ports.0.aggregate', ['id' => $second->id, 'name' => 'Po2'])
                ->where('ports.0.mode', null)
                ->where('ports.0.summary', null)
                ->where('ports.1.name', '2/0/1')
                ->where('ports.1.aggregate', ['id' => $second->id, 'name' => 'Po2'])
                ->where('ports.2.aggregate', null));

        // A device without an aggregate has none to list.
        $this->actingAs($this->reader())
            ->get(route('devices.show', Device::factory()->create(['site_id' => $this->office->id])))
            ->assertInertia(fn (Assert $page) => $page->where('aggregates', []));
    }

    public function test_an_aggregate_outlives_its_ports_and_is_deleted_with_its_device(): void
    {
        $this->create([$this->first[0], $this->second[0]], 'trunk', 99, [10]);

        $aggregate = PortAggregate::query()->sole();

        // The ports of a member deleted with it, and a port deleted alone.
        $this->actingAs($this->editor)->delete(route('device-members.destroy', $this->second[0]->device_member_id))->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->delete(route('ports.destroy', $this->first[0]))->assertSessionHasNoErrors();

        $this->assertSame([], $this->portsOf($aggregate));
        $this->assertSame(['trunk', 'U 99 ; T 10'], $this->carried($aggregate));

        // The device goes with its aggregates, and with what they carry.
        $this->update($aggregate, [$this->first[1]], 'trunk', 99, [10])->assertSessionHasNoErrors();

        $this->actingAs($this->editor)->delete(route('devices.destroy', $this->stack))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('port_aggregates', 0);
        $this->assertDatabaseCount('port_vlans', 0);
        $this->assertDatabaseCount('ports', 0);
        $this->assertDatabaseCount('vlans', 5);

        // Told in the one entry of the deletion of the device, with its
        // member and the ports it still had.
        $old = ($this->lastEntry(ActivityEvent::DeviceDeleted)->attribute_changes?->toArray() ?? [])['old'];

        $this->assertSame(['1', 'Po1', 3, '1/0/2, 1/0/3, 1/0/4'], [$old['members'], $old['aggregates'], $old['ports_count'], $old['ports']]);
        $this->assertSame(0, $this->countEntries(ActivityEvent::PortAggregateDeleted));
    }

    public function test_a_device_whose_aggregates_carry_vlans_changes_site_and_its_aggregates_keep_them(): void
    {
        $this->create([], 'trunk', null, [10]);

        $payload = ['site_id' => $this->foreign->site_id, 'room_id' => null, 'name' => 'sw-coeur', 'type' => 'switch', 'status' => 'active'];

        $this->actingAs($this->editor)->put(route('devices.update', $this->stack), $payload)->assertSessionHasNoErrors();

        $this->assertSame($this->foreign->site_id, $this->stack->refresh()->site_id);
        $this->assertSame(['trunk', 'T Siège 10'], $this->carried(PortAggregate::query()->sole()));
    }

    // --- Rights ---------------------------------------------------------------

    public function test_a_reader_cannot_write_aggregates(): void
    {
        $reader = $this->reader();
        $aggregate = PortAggregate::factory()->create(['device_id' => $this->stack->id, 'name' => 'Po1']);
        $this->first[0]->update(['port_aggregate_id' => $aggregate->id]);

        $this->actingAs($reader)->post(route('port-aggregates.store', $this->stack), $this->payload([$this->first[1]], null, null, [], ['name' => 'Po2']))->assertForbidden();
        $this->actingAs($reader)->put(route('port-aggregates.update', $aggregate), $this->payload([], null, null, [], ['name' => 'Renommé']))->assertForbidden();
        $this->actingAs($reader)->delete(route('port-aggregates.destroy', $aggregate))->assertForbidden();

        $this->assertSame(['Po1'], PortAggregate::query()->pluck('name')->all());
        $this->assertSame(['1/0/1'], $this->portsOf($aggregate));
    }

    public function test_an_administrator_writes_aggregates_like_an_editor_and_a_guest_is_sent_to_the_login_page(): void
    {
        $this->post(route('port-aggregates.store', $this->stack), $this->payload())->assertRedirect(route('login'));

        $this->assertDatabaseCount('port_aggregates', 0);

        $this->actingAs($this->admin())->post(route('port-aggregates.store', $this->stack), $this->payload())->assertSessionHasNoErrors();

        $this->assertDatabaseCount('port_aggregates', 1);

        $this->actingAs($this->editor)->put(route('port-aggregates.update', 999999), $this->payload())->assertNotFound();
        $this->actingAs($this->editor)->post(route('port-aggregates.store', 999999), $this->payload())->assertNotFound();
    }

    // --- Activity log ---------------------------------------------------------

    public function test_the_life_of_an_aggregate_is_logged_with_its_ports_and_its_vlans(): void
    {
        // Ticked in any order: the ports are written in the order of the
        // device.
        $this->create([$this->second[0], $this->first[1], $this->first[0]], 'trunk', 99, [20, 10], ['description' => 'Vers le cœur']);

        $aggregate = PortAggregate::query()->sole();
        $created = $this->lastEntry(ActivityEvent::PortAggregateCreated);

        $this->assertTrue($this->editor->is($created->causer));
        $this->assertTrue($aggregate->is($created->subject));
        $this->assertSame('sw-coeur (Siège) › Po1', $created->getProperty('label'));
        $this->assertSame([
            'device' => 'sw-coeur (Siège)',
            'name' => 'Po1',
            'protocol' => 'lacp',
            'ports' => '1/0/1, 1/0/2, 2/0/1',
            'mode' => 'trunk',
            'vlans' => 'U 99 ; T 10, 20',
            'description' => 'Vers le cœur',
        ], ($created->attribute_changes?->toArray() ?? [])['attributes']);

        // Only what changed: here, the ports and the VLANs.
        $this->update($aggregate, [$this->first[0], $this->second[0], $this->second[1]], 'trunk', 99, [10], ['description' => 'Vers le cœur']);

        $this->assertSame(
            ['old' => ['ports' => '1/0/1, 1/0/2, 2/0/1', 'vlans' => 'U 99 ; T 10, 20'], 'attributes' => ['ports' => '1/0/1, 2/0/1, 2/0/2', 'vlans' => 'U 99 ; T 10']],
            $this->lastEntry(ActivityEvent::PortAggregateUpdated)->attribute_changes?->toArray(),
        );

        // An update that changes nothing writes nothing, on the aggregate
        // nor on its ports.
        $ports = $this->countEntries(ActivityEvent::PortUpdated);

        $this->update($aggregate, [$this->first[0], $this->second[0], $this->second[1]], 'trunk', 99, [10], ['description' => 'Vers le cœur']);

        $this->assertSame(1, $this->countEntries(ActivityEvent::PortAggregateUpdated));
        $this->assertSame($ports, $this->countEntries(ActivityEvent::PortUpdated));

        // Deleted with its last values; each port it frees has its entry.
        $this->actingAs($this->editor)->delete(route('port-aggregates.destroy', $aggregate));

        $deleted = $this->lastEntry(ActivityEvent::PortAggregateDeleted);

        $this->assertNull($deleted->subject);
        $this->assertSame('sw-coeur (Siège) › Po1', $deleted->getProperty('label'));
        $this->assertSame('1/0/1, 2/0/1, 2/0/2', ($deleted->attribute_changes?->toArray() ?? [])['old']['ports']);
        $this->assertSame($ports + 3, $this->countEntries(ActivityEvent::PortUpdated));
        $this->assertSame(
            ['old' => ['aggregate' => 'Po1'], 'attributes' => ['aggregate' => null]],
            $this->lastEntry(ActivityEvent::PortUpdated)->attribute_changes?->toArray(),
        );
    }

    public function test_the_log_page_reads_an_aggregate_in_french(): void
    {
        $this->create([], 'access', 10, [30], ['protocol' => 'static']);

        $this->actingAs($this->admin())
            ->get(route('activity-log.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.event_label', 'Agrégat créé')
                ->where('entries.data.0.subject.title', 'sw-coeur (Siège) › Po1')
                ->where('entries.data.0.changes', [
                    ['field' => 'Équipement', 'old' => null, 'new' => 'sw-coeur (Siège)'],
                    ['field' => 'Nom', 'old' => null, 'new' => 'Po1'],
                    ['field' => 'Protocole', 'old' => null, 'new' => 'Statique'],
                    ['field' => 'Mode', 'old' => null, 'new' => 'Accès'],
                    ['field' => 'VLAN', 'old' => null, 'new' => 'U 10 ; T 30'],
                ]));
    }
}
