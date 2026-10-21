<?php

// torsade/tests/Feature/Network/PortVlanTest.php

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
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * The mode of a port and the VLANs it carries: one untagged VLAN and a
 * voice VLAN at most in access mode, a native VLAN at most and tagged
 * VLANs in trunk mode, set on one port or on several at once.
 */
class PortVlanTest extends NetworkTestCase
{
    private User $editor;

    private Site $office;

    private Device $switch;

    private DeviceMember $member;

    /** @var list<Port> */
    private array $ports;

    /** @var array<int, Vlan> The VLANs of the head office, by number. */
    private array $vlans = [];

    private Vlan $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->editor = $this->editor();
        $this->office = Site::factory()->create(['name' => 'Siège']);
        $this->switch = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-coeur', 'type' => DeviceType::Switch]);
        $this->member = DeviceMember::factory()->primary()->create(['device_id' => $this->switch->id, 'position' => 1]);
        $this->ports = PortFactory::series($this->member, 6);

        foreach ([10 => 'Bureautique', 20 => 'Serveurs', 30 => 'Téléphonie', 99 => 'Administration'] as $vid => $name) {
            $this->vlans[$vid] = Vlan::factory()->create(['site_id' => $this->office->id, 'vid' => $vid, 'name' => $name]);
        }

        $this->foreign = Vlan::factory()->create(['site_id' => Site::factory()->create(['name' => 'Atelier'])->id, 'vid' => 10, 'name' => 'Bureautique']);
    }

    /**
     * What the window of a port sends, with a mode and VLANs given by
     * their numbers.
     *
     * @param  list<int>  $tagged
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Port $port, ?string $mode, ?int $untagged = null, array $tagged = [], array $overrides = []): array
    {
        return [
            'name' => $port->name,
            'position' => $port->position,
            'connector' => 'rj45',
            'max_speed' => '1g',
            'poe_type' => 'none',
            'poe_label' => '',
            'enabled' => true,
            'description' => '',
            'mode' => $mode,
            'untagged_vlan_id' => $untagged === null ? null : $this->vlans[$untagged]->id,
            'tagged_vlan_ids' => array_map(fn (int $vid): int => $this->vlans[$vid]->id, $tagged),
            ...$overrides,
        ];
    }

    /**
     * @param  list<int>  $tagged
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function configure(Port $port, ?string $mode, ?int $untagged = null, array $tagged = [], array $overrides = []): TestResponse
    {
        return $this->actingAs($this->editor)->put(route('ports.update', $port), $this->payload($port, $mode, $untagged, $tagged, $overrides));
    }

    /**
     * @param  list<Port>  $ports
     * @param  list<int>  $tagged
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function bulk(array $ports, ?string $mode, ?int $untagged = null, array $tagged = [], array $overrides = []): TestResponse
    {
        return $this->actingAs($this->editor)->patch(route('ports.update-many', $this->switch), [
            'ports' => array_map(fn (Port $port): int => $port->id, $ports),
            'action' => 'mode',
            'mode' => $mode,
            'untagged_vlan_id' => $untagged === null ? null : $this->vlans[$untagged]->id,
            'tagged_vlan_ids' => array_map(fn (int $vid): int => $this->vlans[$vid]->id, $tagged),
            ...$overrides,
        ]);
    }

    /**
     * @param  list<Port>  $ports
     * @return TestResponse<Response>
     */
    private function clear(array $ports): TestResponse
    {
        return $this->actingAs($this->editor)->patch(route('ports.update-many', $this->switch), [
            'ports' => array_map(fn (Port $port): int => $port->id, $ports),
            'action' => 'clear_mode',
        ]);
    }

    /**
     * What a port carries, as the activity log writes it, with its mode.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function carried(Port $port): array
    {
        $port = Port::query()->with('portVlans.vlan')->findOrFail($port->id);

        return [$port->mode?->value, $port->vlanAuditValue()];
    }

    private function summary(Port $port): ?string
    {
        return Port::query()->with('portVlans.vlan')->findOrFail($port->id)->vlanSummary();
    }

    // --- One port -------------------------------------------------------------

    public function test_an_access_port_carries_one_untagged_vlan(): void
    {
        $this->configure($this->ports[0], 'access', 10)
            ->assertRedirect(route('devices.show', $this->switch))
            ->assertSessionHasNoErrors();

        $this->assertSame(['access', 'U 10'], $this->carried($this->ports[0]));
        $this->assertSame('Accès 10', $this->summary($this->ports[0]));
        $this->assertSame(
            [[$this->ports[0]->id, null, $this->vlans[10]->id, false]],
            PortVlan::query()->get()->map(fn (PortVlan $line): array => [$line->port_id, $line->port_aggregate_id, $line->vlan_id, $line->tagged])->all(),
        );
    }

    public function test_an_access_port_carries_a_voice_vlan_as_well(): void
    {
        $this->configure($this->ports[0], 'access', 10, [30])->assertSessionHasNoErrors();

        $this->assertSame(['access', 'U 10 ; T 30'], $this->carried($this->ports[0]));
        $this->assertSame('Accès 10, voix 30', $this->summary($this->ports[0]));

        // The voice VLAN is taken away by saving without it.
        $this->configure($this->ports[0], 'access', 10)->assertSessionHasNoErrors();

        $this->assertSame(['access', 'U 10'], $this->carried($this->ports[0]));
        $this->assertDatabaseCount('port_vlans', 1);
    }

    public function test_a_trunk_carries_tagged_vlans_and_a_native_vlan_at_most(): void
    {
        $this->configure($this->ports[0], 'trunk', 99, [30, 10, 20])->assertSessionHasNoErrors();
        $this->configure($this->ports[1], 'trunk', null, [20, 10])->assertSessionHasNoErrors();
        $this->configure($this->ports[2], 'trunk', 99)->assertSessionHasNoErrors();
        $this->configure($this->ports[3], 'trunk')->assertSessionHasNoErrors();

        // The tagged VLANs are read by number, whatever the order they
        // were ticked in.
        $this->assertSame(['trunk', 'U 99 ; T 10, 20, 30'], $this->carried($this->ports[0]));
        $this->assertSame('Trunk, natif 99 : 10, 20, 30', $this->summary($this->ports[0]));

        $this->assertSame(['trunk', 'T 10, 20'], $this->carried($this->ports[1]));
        $this->assertSame('Trunk : 10, 20', $this->summary($this->ports[1]));

        $this->assertSame(['trunk', 'U 99'], $this->carried($this->ports[2]));
        $this->assertSame('Trunk, natif 99', $this->summary($this->ports[2]));

        $this->assertSame(['trunk', null], $this->carried($this->ports[3]));
        $this->assertSame('Trunk', $this->summary($this->ports[3]));

        $this->assertNull($this->summary($this->ports[4]));
    }

    public function test_the_rules_of_each_mode_are_enforced(): void
    {
        $port = $this->ports[0];

        // Access: exactly one untagged VLAN, and one tagged VLAN at most.
        $this->assertRefused($this->configure($port, 'access'), 'untagged_vlan_id', 'Le mode accès porte un VLAN non étiqueté : choisissez-le.');
        $this->assertRefused($this->configure($port, 'access', null, [30]), 'untagged_vlan_id');
        $this->assertRefused(
            $this->configure($port, 'access', 10, [20, 30]),
            'tagged_vlan_ids',
            'Le mode accès porte au plus un VLAN étiqueté, pour la voix.',
        );
        $this->assertRefused(
            $this->configure($port, 'access', 10, [10]),
            'tagged_vlan_ids',
            'Le VLAN voix est un autre VLAN que le VLAN non étiqueté.',
        );

        // Trunk: the native VLAN is not tagged as well.
        $this->assertRefused(
            $this->configure($port, 'trunk', 99, [10, 99]),
            'tagged_vlan_ids',
            'Le VLAN natif ne peut pas être aussi étiqueté.',
        );

        // Without a mode: no VLAN.
        $this->assertRefused($this->configure($port, null, 10), 'mode', "Sans mode, aucun VLAN n'est porté : choisissez un mode.");
        $this->assertRefused($this->configure($port, null, null, [10]), 'mode');

        $this->assertSame([null, null], $this->carried($port));
        $this->assertDatabaseCount('port_vlans', 0);
        $this->assertSame(0, $this->countEntries(ActivityEvent::PortUpdated));
    }

    public function test_a_port_carries_vlans_of_another_site_but_never_two_vlans_of_one_number(): void
    {
        $port = $this->ports[0];

        // A VLAN of another site, untagged, for the voice, or tagged.
        $this->configure($port, 'access', null, [], ['untagged_vlan_id' => $this->foreign->id])->assertSessionHasNoErrors();
        $this->assertSame(['access', 'U Atelier 10'], $this->carried($port));

        $this->configure($port, 'access', 20, [], ['tagged_vlan_ids' => [$this->foreign->id]])->assertSessionHasNoErrors();
        $this->assertSame(['access', 'U 20 ; T Atelier 10'], $this->carried($port));

        $this->configure($port, 'trunk', 99, [], ['tagged_vlan_ids' => [$this->vlans[20]->id, $this->foreign->id]])->assertSessionHasNoErrors();
        $this->assertSame(['trunk', 'U 99 ; T 20 · Atelier 10'], $this->carried($port));

        // Two VLANs of the number 10: the one of the site of the device is
        // named first, wherever each one is.
        $message = 'Le VLAN 10 du site Siège et le VLAN 10 du site Atelier ne peuvent pas passer sur le même port.';

        $this->assertRefused($this->configure($port, 'access', 10, [], ['tagged_vlan_ids' => [$this->foreign->id]]), 'tagged_vlan_ids', $message);
        $this->assertRefused($this->configure($port, 'trunk', null, [], ['untagged_vlan_id' => $this->foreign->id, 'tagged_vlan_ids' => [$this->vlans[10]->id]]), 'tagged_vlan_ids', $message);
        $this->assertRefused($this->configure($port, 'trunk', 99, [10, 20], ['tagged_vlan_ids' => [$this->foreign->id, $this->vlans[10]->id, $this->vlans[20]->id]]), 'tagged_vlan_ids', $message);

        // What the port carried stays.
        $this->assertSame(['trunk', 'U 99 ; T 20 · Atelier 10'], $this->carried($port));

        // A VLAN that is gone.
        $this->assertRefused($this->configure($port, 'access', null, [], ['untagged_vlan_id' => 999999]), 'untagged_vlan_id', "Ce VLAN n'existe plus.");
        $this->assertRefused($this->configure($port, 'trunk', null, [], ['tagged_vlan_ids' => [999999]]), 'tagged_vlan_ids', "Ce VLAN n'existe plus.");

        $this->assertDatabaseCount('port_vlans', 3);
    }

    public function test_the_shape_of_a_mode_and_of_its_vlans_is_checked(): void
    {
        $port = $this->ports[0];

        $this->assertRefused($this->configure($port, 'hybrid', 10), 'mode');
        $this->assertRefused($this->configure($port, 'access', null, [], ['untagged_vlan_id' => 'dix']), 'untagged_vlan_id');
        $this->assertRefused($this->configure($port, 'trunk', null, [], ['tagged_vlan_ids' => 'tous']), 'tagged_vlan_ids');
        $this->assertRefused($this->configure($port, 'trunk', null, [], ['tagged_vlan_ids' => ['dix']]), 'tagged_vlan_ids.0');
        $this->assertRefused($this->configure($port, 'trunk', null, [], ['tagged_vlan_ids' => [$this->vlans[10]->id, $this->vlans[10]->id]]), 'tagged_vlan_ids.0');

        $this->assertDatabaseCount('port_vlans', 0);
    }

    public function test_another_mode_replaces_what_the_port_carried_and_no_mode_removes_it(): void
    {
        $port = $this->ports[0];

        $this->configure($port, 'access', 10, [30]);
        $this->configure($port, 'trunk', 99, [10, 20])->assertSessionHasNoErrors();

        $this->assertSame(['trunk', 'U 99 ; T 10, 20'], $this->carried($port));
        $this->assertDatabaseCount('port_vlans', 3);

        $this->configure($port, null)->assertSessionHasNoErrors();

        $this->assertSame([null, null], $this->carried($port));
        $this->assertDatabaseCount('port_vlans', 0);

        // A request that says nothing of the mode asks for none.
        $this->configure($port, 'access', 10);

        $this->actingAs($this->editor)->put(route('ports.update', $port), [
            'name' => $port->name, 'position' => 1, 'connector' => 'rj45', 'max_speed' => '1g', 'poe_type' => 'none', 'enabled' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame([null, null], $this->carried($port));
    }

    public function test_the_other_fields_of_a_port_are_saved_with_its_mode(): void
    {
        $port = $this->ports[0];

        $this->configure($port, 'access', 10, [30], ['name' => 'Gi1/0/1', 'description' => 'Poste accueil', 'enabled' => false])->assertSessionHasNoErrors();

        $port->refresh();

        $this->assertSame(['Gi1/0/1', 'Poste accueil', false, PortMode::Access], [$port->name, $port->description, $port->enabled, $port->mode]);

        // A refused mode saves nothing of the port.
        $this->assertRefused($this->configure($port, 'access', null, [], ['name' => 'Renommé']), 'untagged_vlan_id');

        $this->assertSame('Gi1/0/1', $port->refresh()->name);
        $this->assertSame(['access', 'U 10 ; T 30'], $this->carried($port));
    }

    public function test_a_port_of_an_aggregate_has_no_mode_nor_vlans_of_its_own(): void
    {
        $aggregate = PortAggregate::factory()->create(['device_id' => $this->switch->id, 'name' => 'Po1']);
        $port = $this->ports[0];
        $port->update(['port_aggregate_id' => $aggregate->id]);

        $this->assertRefused(
            $this->configure($port, 'access', 10),
            'mode',
            "Ce port fait partie de l'agrégat Po1 : son mode et ses VLAN sont ceux de l'agrégat.",
        );
        $this->assertRefused($this->configure($port, 'trunk'), 'mode');

        $this->assertSame([null, null], $this->carried($port));

        // Its other fields are still its own.
        $this->configure($port, null, null, [], ['description' => 'Vers sw-etage-1'])->assertSessionHasNoErrors();

        $this->assertSame('Vers sw-etage-1', $port->refresh()->description);
        $this->assertSame($aggregate->id, $port->port_aggregate_id);
    }

    public function test_deleting_a_port_deletes_what_it_carried(): void
    {
        $this->configure($this->ports[0], 'trunk', 99, [10, 20]);
        $this->configure($this->ports[1], 'access', 10);

        $this->actingAs($this->editor)->delete(route('ports.destroy', $this->ports[0]))->assertSessionHasNoErrors();

        $this->assertSame([$this->ports[1]->id], PortVlan::query()->pluck('port_id')->unique()->values()->all());
        $this->assertDatabaseCount('vlans', 5);

        // Deleted with its last values.
        $this->assertSame('U 99 ; T 10, 20', ($this->lastEntry(ActivityEvent::PortDeleted)->attribute_changes?->toArray() ?? [])['old']['vlans']);
    }

    // --- Several ports at once ------------------------------------------------

    public function test_the_ports_ticked_get_the_same_mode_and_the_same_vlans(): void
    {
        $this->configure($this->ports[0], 'access', 10, [30]);
        $this->configure($this->ports[1], 'trunk', 99, [10]);

        $changes = $this->countEntries(ActivityEvent::PortUpdated);

        // Four ports: one already has what is asked, one had something
        // else, two had nothing.
        $this->bulk(array_slice($this->ports, 0, 4), 'access', 10, [30])
            ->assertRedirect(route('devices.show', $this->switch))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '3 ports modifiés.');

        foreach (array_slice($this->ports, 0, 4) as $port) {
            $this->assertSame(['access', 'U 10 ; T 30'], $this->carried($port));
        }

        $this->assertSame([null, null], $this->carried($this->ports[4]));
        $this->assertDatabaseCount('port_vlans', 8);

        // One entry of the activity log per port that changed.
        $this->assertSame($changes + 3, $this->countEntries(ActivityEvent::PortUpdated));

        $this->bulk(array_slice($this->ports, 0, 4), 'access', 10, [30])->assertInertiaFlash('toast.message', 'Ces ports avaient déjà ce mode et ces VLAN.');

        $this->assertSame($changes + 3, $this->countEntries(ActivityEvent::PortUpdated));

        // A trunk for all the ports of the member.
        $this->bulk($this->ports, 'trunk', 99, [10, 20, 30])->assertInertiaFlash('toast.message', '6 ports modifiés.');
        $this->bulk([$this->ports[5]], 'trunk')->assertInertiaFlash('toast.message', '1 port modifié.');

        $this->assertSame(['trunk', 'U 99 ; T 10, 20, 30'], $this->carried($this->ports[0]));
        $this->assertSame(['trunk', null], $this->carried($this->ports[5]));
        $this->assertDatabaseCount('port_vlans', 20);
    }

    public function test_a_mode_for_several_ports_follows_the_rules_of_one_port(): void
    {
        $ports = array_slice($this->ports, 0, 3);

        // Giving a mode needs one.
        $this->assertRefused($this->bulk($ports, null), 'mode');
        $this->assertRefused($this->bulk($ports, 'hybrid', 10), 'mode');

        $this->assertRefused($this->bulk($ports, 'access'), 'untagged_vlan_id', 'Le mode accès porte un VLAN non étiqueté : choisissez-le.');
        $this->assertRefused($this->bulk($ports, 'access', 10, [20, 30]), 'tagged_vlan_ids');
        $this->assertRefused($this->bulk($ports, 'trunk', 99, [99]), 'tagged_vlan_ids', 'Le VLAN natif ne peut pas être aussi étiqueté.');
        $this->assertRefused(
            $this->bulk($ports, 'access', null, [], ['untagged_vlan_id' => $this->foreign->id, 'tagged_vlan_ids' => [$this->vlans[10]->id]]),
            'tagged_vlan_ids',
            'Le VLAN 10 du site Siège et le VLAN 10 du site Atelier ne peuvent pas passer sur le même port.',
        );

        $this->assertDatabaseCount('port_vlans', 0);
        $this->assertSame(0, Port::query()->whereNotNull('mode')->count());
    }

    public function test_a_mode_is_given_to_all_the_ports_ticked_or_to_none_and_the_refusal_names_the_ports_in_the_way(): void
    {
        $aggregate = PortAggregate::factory()->create(['device_id' => $this->switch->id, 'name' => 'Po1']);

        $this->ports[1]->update(['port_aggregate_id' => $aggregate->id]);

        $this->assertRefused(
            $this->bulk($this->ports, 'access', 10),
            'ports',
            "Le port 1/0/2 fait partie d'un agrégat : son mode et ses VLAN sont ceux de l'agrégat.",
        );

        $this->ports[4]->update(['port_aggregate_id' => $aggregate->id]);

        $this->assertRefused(
            $this->bulk($this->ports, 'access', 10),
            'ports',
            "Les ports 1/0/2, 1/0/5 font partie d'un agrégat : leur mode et leurs VLAN sont ceux de l'agrégat.",
        );

        // Nothing was done to the ports that could have got the mode.
        $this->assertSame(0, Port::query()->whereNotNull('mode')->count());
        $this->assertDatabaseCount('port_vlans', 0);
        $this->assertSame(0, $this->countEntries(ActivityEvent::PortUpdated));

        // Ten names at most, in the order of the device, then how many
        // more.
        $many = PortFactory::series($this->member, 12, 1);

        Port::query()->whereKey(array_map(fn (Port $port): int => $port->id, $many))->update(['port_aggregate_id' => $aggregate->id]);

        $this->assertRefused(
            $this->bulk([...$many, $this->ports[0]], 'trunk'),
            'ports',
            "Les ports 1/1/1, 1/1/2, 1/1/3, 1/1/4, 1/1/5, 1/1/6, 1/1/7, 1/1/8, 1/1/9, 1/1/10 et 2 de plus font partie d'un agrégat : leur mode et leurs VLAN sont ceux de l'agrégat.",
        );

        // Without them, the others get it.
        $this->bulk([$this->ports[0], $this->ports[2], $this->ports[3], $this->ports[5]], 'access', 10)->assertSessionHasNoErrors();

        $this->assertSame(4, Port::query()->where('mode', 'access')->count());
    }

    public function test_the_mode_of_the_ports_ticked_is_removed_together(): void
    {
        $this->configure($this->ports[0], 'access', 10, [30]);
        $this->configure($this->ports[1], 'trunk', 99, [10, 20]);
        $this->configure($this->ports[2], 'trunk');

        // A port of an aggregate has none: it is left alone, and stands in
        // no one's way.
        $this->ports[4]->update(['port_aggregate_id' => PortAggregate::factory()->create(['device_id' => $this->switch->id])->id]);

        $changes = $this->countEntries(ActivityEvent::PortUpdated);

        $this->clear([$this->ports[0], $this->ports[1], $this->ports[3], $this->ports[4]])
            ->assertRedirect(route('devices.show', $this->switch))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Le mode de 2 ports a été retiré.');

        $this->assertSame([null, null], $this->carried($this->ports[0]));
        $this->assertSame([null, null], $this->carried($this->ports[1]));
        $this->assertSame(['trunk', null], $this->carried($this->ports[2]));
        $this->assertDatabaseCount('port_vlans', 0);
        $this->assertSame($changes + 2, $this->countEntries(ActivityEvent::PortUpdated));

        $this->clear([$this->ports[2]])->assertInertiaFlash('toast.message', 'Le mode de 1 port a été retiré.');
        $this->clear($this->ports)->assertInertiaFlash('toast.message', "Ces ports n'avaient pas de mode.");

        $this->assertSame($changes + 3, $this->countEntries(ActivityEvent::PortUpdated));
    }

    // --- The device and its site ----------------------------------------------

    public function test_a_device_whose_ports_carry_vlans_changes_site_and_its_ports_keep_them(): void
    {
        $workshop = $this->foreign->site;
        $payload = ['site_id' => $workshop->id, 'room_id' => null, 'name' => 'sw-coeur', 'type' => 'switch', 'status' => 'active'];

        $this->configure($this->ports[0], 'access', 20, [30]);
        $this->configure($this->ports[1], 'trunk', 99, [10, 20]);

        $this->actingAs($this->editor)->put(route('devices.update', $this->switch), $payload)->assertSessionHasNoErrors();

        $this->assertSame($workshop->id, $this->switch->refresh()->site_id);

        // The VLANs of the head office are now those of another site.
        $this->assertSame(['access', 'U Siège 20 ; T Siège 30'], $this->carried($this->ports[0]));
        $this->assertSame('Accès Siège 20, voix Siège 30', $this->summary($this->ports[0]));
        $this->assertSame('Trunk, natif Siège 99 : Siège 10, 20', $this->summary($this->ports[1]));

        // There, its ports carry the VLANs of its new site, and those of
        // any other.
        $this->configure($this->ports[0], 'access', null, [], ['untagged_vlan_id' => $this->foreign->id])->assertSessionHasNoErrors();
        $this->configure($this->ports[1], 'access', 10)->assertSessionHasNoErrors();
    }

    public function test_the_page_of_a_device_shows_what_each_port_carries_and_offers_the_vlans_of_every_site(): void
    {
        $this->configure($this->ports[0], 'access', 10, [30]);
        $this->configure($this->ports[1], 'trunk', 99, [20, 10]);

        $this->actingAs($this->reader())
            ->get(route('devices.show', $this->switch))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ports.0.mode', 'access')
                ->where('ports.0.summary', 'Accès 10, voix 30')
                ->where('ports.0.untagged_vlan_id', $this->vlans[10]->id)
                ->where('ports.0.tagged_vlan_ids', [$this->vlans[30]->id])
                ->where('ports.1.mode', 'trunk')
                ->where('ports.1.summary', 'Trunk, natif 99 : 10, 20')
                ->where('ports.1.untagged_vlan_id', $this->vlans[99]->id)
                // By number.
                ->where('ports.1.tagged_vlan_ids', [$this->vlans[10]->id, $this->vlans[20]->id])
                ->where('ports.2.mode', null)
                ->where('ports.2.summary', null)
                ->where('ports.2.untagged_vlan_id', null)
                ->where('ports.2.tagged_vlan_ids', [])
                // The VLANs of the site of the device first, then those of
                // every other site that has some, by name; each by number.
                ->where('vlans', [
                    [
                        'site' => ['id' => $this->office->id, 'name' => 'Siège'],
                        'vlans' => [
                            ['id' => $this->vlans[10]->id, 'vid' => 10, 'name' => 'Bureautique'],
                            ['id' => $this->vlans[20]->id, 'vid' => 20, 'name' => 'Serveurs'],
                            ['id' => $this->vlans[30]->id, 'vid' => 30, 'name' => 'Téléphonie'],
                            ['id' => $this->vlans[99]->id, 'vid' => 99, 'name' => 'Administration'],
                        ],
                    ],
                    [
                        'site' => ['id' => $this->foreign->site_id, 'name' => 'Atelier'],
                        'vlans' => [
                            ['id' => $this->foreign->id, 'vid' => 10, 'name' => 'Bureautique'],
                        ],
                    ],
                ]));
    }

    // --- Rights and activity log ----------------------------------------------

    public function test_a_reader_cannot_give_a_mode_to_ports(): void
    {
        $reader = $this->reader();

        $this->actingAs($reader)->put(route('ports.update', $this->ports[0]), $this->payload($this->ports[0], 'access', 10))->assertForbidden();
        $this->actingAs($reader)->patch(route('ports.update-many', $this->switch), [
            'ports' => [$this->ports[0]->id], 'action' => 'mode', 'mode' => 'access', 'untagged_vlan_id' => $this->vlans[10]->id,
        ])->assertForbidden();
        $this->actingAs($reader)->patch(route('ports.update-many', $this->switch), ['ports' => [$this->ports[0]->id], 'action' => 'clear_mode'])->assertForbidden();

        $this->assertDatabaseCount('port_vlans', 0);
    }

    public function test_a_change_of_mode_or_of_vlans_is_logged_on_the_port(): void
    {
        $port = $this->ports[0];

        $this->configure($port, 'access', 10, [30]);

        $entry = $this->lastEntry(ActivityEvent::PortUpdated);

        $this->assertTrue($this->editor->is($entry->causer));
        $this->assertTrue($port->is($entry->subject));
        $this->assertSame('sw-coeur (Siège) › 1/0/1', $entry->getProperty('label'));
        $this->assertSame(
            ['old' => ['mode' => null, 'vlans' => null], 'attributes' => ['mode' => 'access', 'vlans' => 'U 10 ; T 30']],
            $entry->attribute_changes?->toArray(),
        );

        // The VLANs alone.
        $this->configure($port, 'access', 20, [30]);

        $this->assertSame(
            ['old' => ['vlans' => 'U 10 ; T 30'], 'attributes' => ['vlans' => 'U 20 ; T 30']],
            $this->lastEntry(ActivityEvent::PortUpdated)->attribute_changes?->toArray(),
        );

        // Saved again as it is: nothing is written.
        $this->configure($port, 'access', 20, [30]);

        $this->assertSame(2, $this->countEntries(ActivityEvent::PortUpdated));

        $this->clear([$port]);

        $this->assertSame(
            ['old' => ['mode' => 'access', 'vlans' => 'U 20 ; T 30'], 'attributes' => ['mode' => null, 'vlans' => null]],
            $this->lastEntry(ActivityEvent::PortUpdated)->attribute_changes?->toArray(),
        );
    }

    public function test_the_log_page_reads_the_mode_and_the_vlans_of_a_port_in_french(): void
    {
        $this->bulk([$this->ports[0]], 'trunk', 99, [10, 20]);

        $this->actingAs($this->admin())
            ->get(route('activity-log.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.event_label', 'Port modifié')
                ->where('entries.data.0.subject.title', 'sw-coeur (Siège) › 1/0/1')
                ->where('entries.data.0.changes', [
                    ['field' => 'Mode', 'old' => '—', 'new' => 'Trunk'],
                    ['field' => 'VLAN', 'old' => '—', 'new' => 'U 99 ; T 10, 20'],
                ]));
    }
}
