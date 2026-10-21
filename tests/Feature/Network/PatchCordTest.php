<?php

// torsade/tests/Feature/Network/PatchCordTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\DeviceType;
use App\Enums\PortMode;
use App\Models\Activity;
use App\Models\Building;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\Floor;
use App\Models\Jack;
use App\Models\PatchCord;
use App\Models\PatchPanel;
use App\Models\PatchPanelPort;
use App\Models\Port;
use App\Models\PortVlan;
use App\Models\Room;
use App\Models\Site;
use App\Models\User;
use App\Models\Vlan;
use Database\Factories\LinkFactory;
use Database\Factories\PatchCordFactory;
use Database\Factories\PortFactory;
use Illuminate\Database\QueryException;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PDOException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Patch cords: from a port of a panel to a port of a device of the same
 * site, one at a time or as a series, and taken away.
 */
class PatchCordTest extends NetworkTestCase
{
    private User $editor;

    private Site $office;

    private Room $rack;

    private PatchPanel $panel;

    private Device $stack;

    /**
     * The ports of the stack, by name: two members of four ports.
     *
     * @var array<string, Port>
     */
    private array $ports = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->editor = $this->editor();
        $this->office = Site::factory()->create(['name' => 'Siège']);

        $building = Building::factory()->create(['site_id' => $this->office->id, 'name' => 'Bâtiment principal']);
        $floor = Floor::factory()->create(['building_id' => $building->id, 'level' => 1, 'name' => '1er étage']);

        $this->rack = Room::factory()->technical()->create(['floor_id' => $floor->id, 'name' => 'Local technique']);
        $this->panel = PatchPanel::factory()->create(['room_id' => $this->rack->id, 'name' => 'PB-A', 'port_count' => 24]);
        $this->stack = Device::factory()->create(['site_id' => $this->office->id, 'room_id' => $this->rack->id, 'name' => 'sw-etage-1', 'type' => DeviceType::Switch]);

        foreach ([2, 1] as $position) {
            $member = DeviceMember::factory()->create(['device_id' => $this->stack->id, 'position' => $position]);

            foreach (PortFactory::series($member, 4) as $port) {
                $this->ports[$port->name] = $port;
            }
        }
    }

    private function port(int $number, ?PatchPanel $panel = null): PatchPanelPort
    {
        return PatchPanelPort::query()->where('patch_panel_id', ($panel ?? $this->panel)->id)->where('number', $number)->sole();
    }

    /**
     * What the window "Patch" sends.
     *
     * @param  list<int>  $numbers  The ports of the panel.
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function patchPorts(array $numbers, ?Port $first, array $overrides = [], ?Device $device = null): TestResponse
    {
        return $this->actingAs($this->editor)->post(route('patch-cords.store', $this->panel), [
            'panel_port_ids' => array_map(fn (int $number): int => $this->port($number)->id, $numbers),
            'device_id' => ($device ?? $this->stack)->id,
            'port_id' => $first?->id,
            'color' => '',
            'description' => '',
            ...$overrides,
        ]);
    }

    /**
     * Each cord, from the number of its port of the panel to the name of
     * its port of the device.
     *
     * @return array<int, string>
     */
    private function cords(): array
    {
        return PatchCord::query()
            ->with(['panelPort', 'port'])
            ->get()
            ->sortBy(fn (PatchCord $cord): int => $cord->panelPort->number)
            ->mapWithKeys(fn (PatchCord $cord): array => [$cord->panelPort->number => $cord->port->name])
            ->all();
    }

    /**
     * Have the database give up the writes of a cord, as it gives up one of
     * two writes that cross: the first ones only, or every one.
     *
     * @return \Closure(): int How many times a write was given up.
     */
    private function crossing(int $times): \Closure
    {
        $given = 0;

        // The cord is the first row a patching writes: given up there, an
        // attempt has written nothing.
        PatchCord::creating(function () use (&$given, $times): void {
            if ($given >= $times) {
                return;
            }

            $given++;

            throw new QueryException('mariadb', 'insert into patch_cords', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
        });

        return function () use (&$given): int {
            return $given;
        };
    }

    // --- Patching -------------------------------------------------------------

    public function test_an_editor_patches_a_port_of_a_panel_to_a_port_of_a_device(): void
    {
        $this->patchPorts([15], $this->ports['1/0/3'], ['color' => 'Bleu', 'description' => 'Poste de l’accueil'])
            ->assertRedirect(route('patch-panels.show', $this->panel))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '1 port brassé.');

        $cord = PatchCord::query()->sole();

        $this->assertSame(
            [$this->port(15)->id, $this->ports['1/0/3']->id, 'Bleu', 'Poste de l’accueil'],
            [$cord->patch_panel_port_id, $cord->port_id, $cord->color, $cord->description],
        );

        $entry = $this->lastEntry(ActivityEvent::PatchCordCreated);

        $this->assertSame('Bâtiment principal › 1er étage › Local technique › PB-A 15 ↔ sw-etage-1 (Siège) › 1/0/3', $entry->getProperty('label'));
        $this->assertSame(
            [
                'panel_port' => 'Bâtiment principal › 1er étage › Local technique › PB-A 15',
                'port' => 'sw-etage-1 (Siège) › 1/0/3',
                'color' => 'Bleu',
                'description' => 'Poste de l’accueil',
            ],
            ($entry->attribute_changes?->toArray() ?? [])['attributes'],
        );

        // An empty colour and description are kept as nothing.
        $this->patchPorts([16], $this->ports['1/0/4'])->assertSessionHasNoErrors();

        $this->assertSame([null, null], PatchCord::query()->where('port_id', $this->ports['1/0/4']->id)->get(['color', 'description'])->map(fn (PatchCord $cord): array => [$cord->color, $cord->description])->sole());
    }

    public function test_consecutive_ports_are_patched_in_the_order_of_the_device_member_then_place(): void
    {
        // From 1/0/3: 1/0/4, then the ports of the member 2.
        $this->patchPorts([4, 2, 3, 1], $this->ports['1/0/3'], ['color' => 'Jaune'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '4 ports brassés.');

        $this->assertSame([1 => '1/0/3', 2 => '1/0/4', 3 => '2/0/1', 4 => '2/0/2'], $this->cords());
        $this->assertSame(['Jaune'], PatchCord::query()->distinct()->pluck('color')->all());

        // One entry per cord, as one per link of a series.
        $this->assertSame(4, $this->countEntries(ActivityEvent::PatchCordCreated));
    }

    public function test_a_series_is_patched_whole_or_not_at_all(): void
    {
        PatchCordFactory::between($this->port(3), $this->ports['2/0/4']);
        Jack::factory()->create(['floor_id' => $this->rack->floor_id, 'label' => 'B.05', 'patch_panel_port_id' => $this->port(5)->id]);
        PatchCordFactory::between($this->port(5), $this->ports['1/0/2']);

        // Ports of the panel that do not follow one another.
        $this->assertRefused(
            $this->patchPorts([1, 2, 4], $this->ports['1/0/1']),
            'panel_port_ids',
            'Les ports du panneau brassés en une fois se suivent : choisissez des ports consécutifs.',
        );

        // A port of the panel already patched.
        $this->assertRefused(
            $this->patchPorts([2, 3], $this->ports['1/0/3']),
            'panel_port_ids',
            "PB-A 3 est brassé vers sw-etage-1 2/0/4 : débrassez-le d'abord.",
        );

        // A port of the device already patched, named with the jack of its
        // port of the panel.
        $this->assertRefused(
            $this->patchPorts([10, 11], $this->ports['1/0/1']),
            'port_id',
            "sw-etage-1 1/0/2 est brassé vers PB-A 5 (B.05) : débrassez-le d'abord.",
        );

        // Not enough ports from the first one.
        $this->assertRefused(
            $this->patchPorts([10, 11, 12], $this->ports['2/0/3']),
            'port_id',
            "À partir de 2/0/3, sw-etage-1 n'a pas assez de ports pour 3 cordons.",
        );

        // A port that is not one of the device, a device that no longer
        // exists, and a port of another panel.
        $this->assertRefused($this->patchPorts([10], Port::factory()->create()), 'port_id', "Ce port n'est plus un port de sw-etage-1 : choisissez-le à nouveau.");
        $this->assertRefused($this->patchPorts([10], $this->ports['1/0/3'], ['device_id' => 999999]), 'device_id', "Cet équipement n'existe plus.");

        $other = PatchPanel::factory()->create(['room_id' => $this->rack->id, 'name' => 'PB-B', 'port_count' => 2]);

        $this->assertRefused(
            $this->patchPorts([10], $this->ports['1/0/3'], ['panel_port_ids' => [$this->port(1, $other)->id]]),
            'panel_port_ids',
            'Certains de ces ports ne sont plus des ports de PB-A : rechargez la page.',
        );

        // The shape of the request.
        $this->assertRefused($this->patchPorts([], $this->ports['1/0/3']), 'panel_port_ids');
        $this->assertRefused($this->patchPorts([10], null), 'port_id');
        $this->assertRefused($this->patchPorts([10], $this->ports['1/0/3'], ['color' => str_repeat('a', 33)]), 'color');
        $this->assertRefused($this->patchPorts([10], $this->ports['1/0/3'], ['description' => str_repeat('a', 256)]), 'description');

        // Nothing was patched, and nothing was written to the log.
        $this->assertDatabaseCount('patch_cords', 2);
        $this->assertSame(0, $this->countEntries(ActivityEvent::PatchCordCreated));
    }

    public function test_a_cord_never_joins_two_sites(): void
    {
        $workshop = Site::factory()->create(['name' => 'Atelier']);
        $device = Device::factory()->create(['site_id' => $workshop->id, 'name' => 'sw-atelier']);
        $port = PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $device->id]), 1)[0];

        $this->assertRefused(
            $this->patchPorts([1], $port, [], $device),
            'device_id',
            "L'équipement sw-atelier est sur le site Atelier, le panneau sur le site Siège : un cordon ne relie pas deux sites.",
        );
        $this->assertDatabaseCount('patch_cords', 0);
    }

    public function test_a_linked_port_is_accepted_by_the_server_even_towards_a_port_with_a_jack(): void
    {
        $core = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-coeur-01']);
        $uplink = PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $core->id]), 1)[0];
        LinkFactory::between($this->ports['1/0/4'], $uplink);
        Jack::factory()->create(['floor_id' => $this->rack->floor_id, 'label' => 'B.12', 'patch_panel_port_id' => $this->port(12)->id]);

        // The window does not offer it: what it declares is a deviation,
        // which never keeps anything from being saved.
        $this->patchPorts([12], $this->ports['1/0/4'])->assertSessionHasNoErrors();

        $this->assertSame([12 => '1/0/4'], $this->cords());
    }

    public function test_unpatching_takes_the_cord_away_and_keeps_both_ports(): void
    {
        $cord = PatchCordFactory::between($this->port(1), $this->ports['1/0/1'], ['color' => 'Rouge']);

        $this->actingAs($this->editor)
            ->from(route('patch-panels.show', $this->panel))
            ->delete(route('patch-cords.destroy', $cord))
            ->assertRedirect(route('patch-panels.show', $this->panel))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Port débrassé.');

        $this->assertModelMissing($cord);
        $this->assertModelExists($this->port(1));
        $this->assertModelExists($this->ports['1/0/1']);

        $entry = $this->lastEntry(ActivityEvent::PatchCordDeleted);

        $this->assertSame('Bâtiment principal › 1er étage › Local technique › PB-A 1 ↔ sw-etage-1 (Siège) › 1/0/1', $entry->getProperty('label'));

        // Patched again at once.
        $this->patchPorts([1], $this->ports['1/0/1'])->assertSessionHasNoErrors();

        $this->actingAs($this->editor)->delete(route('patch-cords.destroy', $cord))->assertNotFound();
    }

    // --- Changing a cord ---------------------------------------------------------

    public function test_the_colour_and_the_description_of_a_cord_change_and_its_two_ends_stay(): void
    {
        $cord = PatchCordFactory::between($this->port(1), $this->ports['1/0/1'], ['color' => 'Bleu']);

        // The ends sent with it are not read: the request knows of none.
        $this->actingAs($this->editor)
            ->from(route('patch-panels.show', $this->panel))
            ->put(route('patch-cords.update', $cord), [
                'color' => 'Vert',
                'description' => 'Poste du fond',
                'patch_panel_port_id' => $this->port(2)->id,
                'port_id' => $this->ports['1/0/2']->id,
            ])
            ->assertRedirect(route('patch-panels.show', $this->panel))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Cordon modifié.');

        $cord->refresh();

        $this->assertSame(
            [$this->port(1)->id, $this->ports['1/0/1']->id, 'Vert', 'Poste du fond'],
            [$cord->patch_panel_port_id, $cord->port_id, $cord->color, $cord->description],
        );

        // One entry, which names the two ends, with the values before and
        // after.
        $entry = $this->lastEntry(ActivityEvent::PatchCordUpdated);

        $this->assertSame('Bâtiment principal › 1er étage › Local technique › PB-A 1 ↔ sw-etage-1 (Siège) › 1/0/1', $entry->getProperty('label'));
        $this->assertSame(
            ['old' => ['color' => 'Bleu', 'description' => null], 'attributes' => ['color' => 'Vert', 'description' => 'Poste du fond']],
            $entry->attribute_changes?->toArray(),
        );

        // Emptied, the colour and the description are kept as nothing; the
        // same values again write nothing to the log.
        $this->actingAs($this->editor)->put(route('patch-cords.update', $cord), ['color' => '', 'description' => ''])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->put(route('patch-cords.update', $cord), ['color' => '', 'description' => ''])->assertSessionHasNoErrors();

        $this->assertSame([null, null], [$cord->refresh()->color, $cord->description]);
        $this->assertSame(2, $this->countEntries(ActivityEvent::PatchCordUpdated));

        $this->assertRefused($this->actingAs($this->editor)->put(route('patch-cords.update', $cord), ['color' => str_repeat('a', 33)]), 'color');
        $this->assertRefused($this->actingAs($this->editor)->put(route('patch-cords.update', $cord), ['description' => str_repeat('a', 256)]), 'description');
    }

    public function test_a_cord_unpatched_in_the_meantime_is_told_in_words_never_as_an_error(): void
    {
        $cord = PatchCordFactory::between($this->port(1), $this->ports['1/0/1']);
        $id = $cord->id;

        $cord->delete();

        $this->assertRefused(
            $this->actingAs($this->editor)->from(route('patch-panels.show', $this->panel))->put(route('patch-cords.update', $id), ['color' => 'Vert']),
            'cord',
            'Ce cordon a été débrassé entre-temps : rechargez la page.',
        );
        $this->assertSame(0, $this->countEntries(ActivityEvent::PatchCordUpdated));
    }

    public function test_the_activity_log_tells_a_cord_changed_in_words(): void
    {
        $cord = PatchCordFactory::between($this->port(7), $this->ports['1/0/3'], ['color' => 'Bleu']);

        $this->actingAs($this->editor)->put(route('patch-cords.update', $cord), ['color' => 'Jaune', 'description' => ''])->assertSessionHasNoErrors();

        $this->actingAs($this->admin())
            ->get(route('activity-log.index', ['event' => 'patch_cord.updated']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.event_label', 'Cordon modifié')
                ->where('entries.data.0.changes', [['field' => 'Couleur', 'old' => 'Bleu', 'new' => 'Jaune']]));
    }

    // --- Unpatching several ports ------------------------------------------------

    /**
     * What the button "Unpatch" of the ports ticked sends.
     *
     * @param  list<int>  $numbers  The ports of the panel.
     * @return TestResponse<Response>
     */
    private function unpatchPorts(array $numbers, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->editor)->delete(route('patch-cords.destroy-many', $this->panel), [
            'panel_port_ids' => array_map(fn (int $number): int => $this->port($number)->id, $numbers),
        ]);
    }

    /**
     * Have the database give up the deletion of a cord, as it gives up one
     * of two writes that cross: the first ones only, or every one.
     *
     * @return \Closure(): int How many times a write was given up.
     */
    private function crossingDeletion(int $times): \Closure
    {
        $given = 0;

        // The entry of the log of a cord comes before its deletion: it is
        // the first row an unpatching writes, and given up there, an
        // attempt has written nothing.
        Activity::creating(function (Activity $entry) use (&$given, $times): void {
            if ($given >= $times || $entry->event !== ActivityEvent::PatchCordDeleted->value) {
                return;
            }

            $given++;

            throw new QueryException('mariadb', 'delete from patch_cords', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
        });

        return function () use (&$given): int {
            return $given;
        };
    }

    public function test_the_ports_ticked_are_unpatched_at_once_and_those_without_a_cord_are_left_out(): void
    {
        foreach ([1, 2, 3, 5] as $index => $number) {
            PatchCordFactory::between($this->port($number), $this->ports['1/0/'.($index + 1)]);
        }

        PatchCordFactory::between($this->port(9), $this->ports['2/0/1']);

        // Consecutive or not; the port 4 has no cord.
        $this->unpatchPorts([5, 1, 2, 3, 4])
            ->assertRedirect(route('patch-panels.show', $this->panel))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '4 cordons supprimés.');

        // The cord of a port not ticked stays, and so do every port.
        $this->assertSame([9 => '2/0/1'], $this->cords());
        $this->assertDatabaseCount('patch_panel_ports', 24);
        $this->assertDatabaseCount('ports', 8);

        // One entry per cord, each naming its two ends.
        $this->assertSame(4, $this->countEntries(ActivityEvent::PatchCordDeleted));
        $this->assertSame(
            'Bâtiment principal › 1er étage › Local technique › PB-A 5 ↔ sw-etage-1 (Siège) › 1/0/4',
            $this->lastEntry(ActivityEvent::PatchCordDeleted)->getProperty('label'),
        );

        $this->unpatchPorts([9])->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', '1 cordon supprimé.');

        $this->assertSame([], $this->cords());
    }

    public function test_unpatching_ports_that_have_no_cord_or_are_not_of_the_panel_is_refused(): void
    {
        PatchCordFactory::between($this->port(1), $this->ports['1/0/1']);

        $this->assertRefused($this->unpatchPorts([2, 3]), 'panel_port_ids', "Aucun de ces ports n'est plus brassé : rechargez la page.");

        $other = PatchPanel::factory()->create(['room_id' => $this->rack->id, 'name' => 'PB-B', 'port_count' => 2]);

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('patch-cords.destroy-many', $this->panel), ['panel_port_ids' => [$this->port(1)->id, $this->port(1, $other)->id]]),
            'panel_port_ids',
            'Certains de ces ports ne sont plus des ports de PB-A : rechargez la page.',
        );
        $this->assertRefused($this->unpatchPorts([]), 'panel_port_ids');

        $this->assertSame([1 => '1/0/1'], $this->cords());
        $this->assertSame(0, $this->countEntries(ActivityEvent::PatchCordDeleted));
    }

    public function test_an_unpatching_given_up_for_a_crossing_write_is_replayed_then_told_in_words(): void
    {
        PatchCordFactory::between($this->port(1), $this->ports['1/0/1']);
        PatchCordFactory::between($this->port(2), $this->ports['1/0/2']);

        $given = $this->crossingDeletion(2);

        $this->unpatchPorts([1, 2])->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', '2 cordons supprimés.');

        $this->assertSame(2, $given());
        $this->assertSame([], $this->cords());
        $this->assertSame(2, $this->countEntries(ActivityEvent::PatchCordDeleted));
    }

    public function test_an_unpatching_given_up_at_every_attempt_is_a_message_never_a_server_error(): void
    {
        PatchCordFactory::between($this->port(1), $this->ports['1/0/1']);

        $given = $this->crossingDeletion(PHP_INT_MAX);

        $this->assertRefused($this->unpatchPorts([1]), 'panel_port_ids', "L'enregistrement a croisé une autre modification : réessayez.");

        $this->assertSame(3, $given());
        $this->assertSame([1 => '1/0/1'], $this->cords());
        $this->assertSame(0, $this->countEntries(ActivityEvent::PatchCordDeleted));
    }

    // --- What a cord keeps from being deleted ---------------------------------

    public function test_a_patched_port_member_or_device_is_not_deleted_and_the_refusal_names_the_cord(): void
    {
        Jack::factory()->create(['floor_id' => $this->rack->floor_id, 'label' => 'B.01', 'patch_panel_port_id' => $this->port(1)->id]);
        PatchCordFactory::between($this->port(1), $this->ports['1/0/1']);
        PatchCordFactory::between($this->port(2), $this->ports['2/0/3']);

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('ports.destroy', $this->ports['1/0/1'])),
            'port',
            "1/0/1 est brassé vers PB-A 1 (B.01) : débrassez-le d'abord.",
        );
        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('ports.destroy-many', $this->stack), ['ports' => [$this->ports['1/0/2']->id, $this->ports['2/0/3']->id]]),
            'ports',
            "2/0/3 est brassé vers PB-A 2 : débrassez-le d'abord.",
        );

        $members = $this->stack->members()->orderBy('position')->get();

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('device-members.destroy', $members[0])),
            'member',
            "1/0/1 est brassé vers PB-A 1 (B.01) : débrassez-le d'abord.",
        );
        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('devices.destroy', $this->stack)),
            'cords',
            "1/0/1 est brassé vers PB-A 1 (B.01), 2/0/3 est brassé vers PB-A 2 : débrassez-les d'abord.",
        );

        $this->assertDatabaseCount('ports', 8);
        $this->assertModelExists($this->stack);
    }

    public function test_a_patched_device_does_not_move_to_another_site_and_the_refusal_names_its_cords(): void
    {
        $workshop = Site::factory()->create(['name' => 'Atelier']);
        $move = fn (Device $device, array $changes = []) => $this->actingAs($this->editor)->put(route('devices.update', $device), [
            'site_id' => $workshop->id, 'room_id' => null, 'name' => $device->name, 'type' => 'switch', 'status' => 'active', ...$changes,
        ]);

        Jack::factory()->create(['floor_id' => $this->rack->floor_id, 'label' => 'B.01', 'patch_panel_port_id' => $this->port(1)->id]);
        PatchCordFactory::between($this->port(1), $this->ports['1/0/1']);
        PatchCordFactory::between($this->port(2), $this->ports['2/0/3']);

        // A cord does not join two sites: in the order of the device.
        $this->assertRefused(
            $move($this->stack),
            'site_id',
            "1/0/1 est brassé vers PB-A 1 (B.01), 2/0/3 est brassé vers PB-A 2 : débrassez-les avant de déplacer l'équipement vers un autre site.",
        );

        $this->assertSame($this->office->id, $this->stack->refresh()->site_id);
        $this->assertSame($this->rack->id, $this->stack->room_id);

        // On its own site, the rest of it changes.
        $move($this->stack, ['site_id' => $this->office->id, 'room_id' => $this->rack->id, 'name' => 'sw-etage-01'])->assertSessionHasNoErrors();

        $this->assertSame('sw-etage-01', $this->stack->refresh()->name);

        // Ten cords named at most, then how many more.
        $large = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-etage-2', 'type' => DeviceType::Switch]);
        $ports = PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $large->id, 'position' => 1]), 12);

        foreach ($ports as $index => $port) {
            PatchCordFactory::between($this->port(11 + $index), $port);
        }

        $move($large);

        $this->assertStringStartsWith('1/0/1 est brassé vers PB-A 11, 1/0/2 est brassé vers PB-A 12, ', $this->error('site_id'));
        $this->assertStringEndsWith("1/0/10 est brassé vers PB-A 20 et 2 de plus : débrassez-les avant de déplacer l'équipement vers un autre site.", $this->error('site_id'));

        // Unpatched, the device moves: a link, which may join two sites,
        // does not hold it.
        $other = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-coeur']);
        $otherPort = PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $other->id, 'position' => 1]), 1)[0];
        LinkFactory::between($this->ports['1/0/2'], $otherPort);

        PatchCord::query()->whereIn('port_id', [$this->ports['1/0/1']->id, $this->ports['2/0/3']->id])->delete();

        $move($this->stack)->assertSessionHasNoErrors();

        $this->assertSame($workshop->id, $this->stack->refresh()->site_id);
        $this->assertNull($this->stack->room_id);
    }

    // --- Every port of a device at once --------------------------------------

    /**
     * What "Unpatch all its ports" of the page of a device sends.
     *
     * @return TestResponse<Response>
     */
    private function unpatchDevice(?Device $device = null, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->editor)->delete(route('devices.patch-cords.destroy-all', $device ?? $this->stack));
    }

    public function test_every_port_of_a_device_is_unpatched_at_once_one_entry_of_the_log_per_cord(): void
    {
        $other = PatchPanel::factory()->create(['room_id' => $this->rack->id, 'name' => 'PB-B', 'port_count' => 4]);
        PatchCordFactory::between($this->port(2), $this->ports['2/0/3']);
        PatchCordFactory::between($this->port(1), $this->ports['1/0/1']);
        PatchCordFactory::between($this->port(1, $other), $this->ports['1/0/4']);

        // The cord of another device stays.
        $neighbour = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-etage-2']);
        $neighbourPort = PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $neighbour->id, 'position' => 1]), 1)[0];
        PatchCordFactory::between($this->port(9), $neighbourPort);

        $this->unpatchDevice()
            ->assertRedirect(route('devices.show', $this->stack))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '3 cordons supprimés.');

        // The ports stay; only the cords of the device are gone.
        $this->assertSame([9 => '1/0/1'], $this->cords());
        $this->assertSame($neighbourPort->id, PatchCord::query()->sole()->port_id);
        $this->assertDatabaseCount('ports', 9);

        // One entry per cord, in the order of the device: member, then place.
        $entries = Activity::query()->where('event', ActivityEvent::PatchCordDeleted->value)->orderBy('id')->get();

        $this->assertSame(
            ['PB-A 1 ↔ sw-etage-1 (Siège) › 1/0/1', 'PB-B 1 ↔ sw-etage-1 (Siège) › 1/0/4', 'PB-A 2 ↔ sw-etage-1 (Siège) › 2/0/3'],
            $entries->map(fn (Activity $entry): string => str_replace('Bâtiment principal › 1er étage › Local technique › ', '', (string) $entry->getProperty('label')))->all(),
        );
        $this->assertTrue($this->editor->is($entries[0]->causer));

        // Nothing left: told in words, nothing written.
        $this->assertRefused($this->unpatchDevice(), 'cords', "Aucun port de cet équipement n'est plus brassé : rechargez la page.");
        $this->assertSame(3, $this->countEntries(ActivityEvent::PatchCordDeleted));
    }

    public function test_an_unpatching_of_a_device_given_up_for_a_crossing_write_is_replayed_then_told_in_words(): void
    {
        PatchCordFactory::between($this->port(1), $this->ports['1/0/1']);
        PatchCordFactory::between($this->port(2), $this->ports['1/0/2']);

        $given = $this->crossingDeletion(2);

        $this->unpatchDevice()->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', '2 cordons supprimés.');

        $this->assertSame(2, $given());
        $this->assertSame([], $this->cords());
        $this->assertSame(2, $this->countEntries(ActivityEvent::PatchCordDeleted));
    }

    public function test_an_unpatching_of_a_device_given_up_at_every_attempt_is_a_message_never_a_server_error(): void
    {
        PatchCordFactory::between($this->port(1), $this->ports['1/0/1']);

        $given = $this->crossingDeletion(PHP_INT_MAX);

        $this->assertRefused($this->unpatchDevice(), 'cords', "L'enregistrement a croisé une autre modification : réessayez.");

        $this->assertSame(3, $given());
        $this->assertSame([1 => '1/0/1'], $this->cords());
        $this->assertSame(0, $this->countEntries(ActivityEvent::PatchCordDeleted));
    }

    public function test_a_reader_cannot_unpatch_every_port_of_a_device(): void
    {
        PatchCordFactory::between($this->port(1), $this->ports['1/0/1']);

        $this->unpatchDevice(null, $this->reader())->assertForbidden();

        $this->assertSame([1 => '1/0/1'], $this->cords());

        // An administrator does, as an editor.
        $this->unpatchDevice(null, $this->admin())->assertSessionHasNoErrors();

        $this->assertSame([], $this->cords());
    }

    public function test_once_its_ports_are_unpatched_a_device_moves_to_another_site(): void
    {
        $workshop = Site::factory()->create(['name' => 'Atelier']);
        $payload = ['site_id' => $workshop->id, 'room_id' => null, 'name' => 'sw-etage-1', 'type' => 'switch', 'status' => 'active'];

        PatchCordFactory::between($this->port(1), $this->ports['1/0/1']);

        $this->assertRefused($this->actingAs($this->editor)->put(route('devices.update', $this->stack), $payload), 'site_id');

        $this->unpatchDevice()->assertSessionHasNoErrors();

        $this->actingAs($this->editor)->put(route('devices.update', $this->stack), $payload)->assertSessionHasNoErrors();

        $this->assertSame($workshop->id, $this->stack->refresh()->site_id);
    }

    // --- Crossed writes -------------------------------------------------------

    public function test_a_patching_given_up_for_a_crossing_write_is_written_again(): void
    {
        $given = $this->crossing(2);

        $this->patchPorts([1, 2], $this->ports['1/0/1'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '2 ports brassés.');

        $this->assertSame(2, $given());
        $this->assertSame([1 => '1/0/1', 2 => '1/0/2'], $this->cords());
        $this->assertSame(2, $this->countEntries(ActivityEvent::PatchCordCreated));
    }

    public function test_a_patching_given_up_at_every_attempt_is_a_message_never_a_server_error(): void
    {
        $given = $this->crossing(PHP_INT_MAX);

        $this->assertRefused(
            $this->patchPorts([1, 2], $this->ports['1/0/1']),
            'panel_port_ids',
            "L'enregistrement a croisé une autre modification : réessayez.",
        );

        $this->assertSame(3, $given());
        $this->assertDatabaseCount('patch_cords', 0);
        $this->assertSame(0, $this->countEntries(ActivityEvent::PatchCordCreated));
    }

    // --- Pages ----------------------------------------------------------------

    public function test_the_page_of_a_panel_offers_the_devices_of_its_site_and_their_ports_when_asked(): void
    {
        Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-10']);
        Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-9']);
        $faraway = Device::factory()->create(['site_id' => Site::factory()->create()->id, 'name' => 'sw-ailleurs']);

        $core = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-coeur-01']);
        $uplink = PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $core->id]), 1)[0];
        LinkFactory::between($this->ports['1/0/4'], $uplink);
        Jack::factory()->create(['floor_id' => $this->rack->floor_id, 'label' => 'B.03', 'patch_panel_port_id' => $this->port(3)->id]);
        PatchCordFactory::between($this->port(3), $this->ports['1/0/2']);

        $vlan = Vlan::factory()->create(['site_id' => $this->office->id, 'vid' => 10]);
        $this->ports['1/0/1']->update(['mode' => PortMode::Access]);
        PortVlan::factory()->create(['port_id' => $this->ports['1/0/1']->id, 'vlan_id' => $vlan->id, 'tagged' => false]);

        $this->actingAs($this->reader())
            ->get(route('patch-panels.show', $this->panel))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // The devices of the site only, by name, a number read as a
                // number.
                ->where('patchDevices', [
                    ['id' => Device::query()->where('name', 'sw-9')->value('id'), 'name' => 'sw-9', 'place' => null],
                    ['id' => Device::query()->where('name', 'sw-10')->value('id'), 'name' => 'sw-10', 'place' => null],
                    ['id' => $core->id, 'name' => 'sw-coeur-01', 'place' => null],
                    ['id' => $this->stack->id, 'name' => 'sw-etage-1', 'place' => 'Bâtiment principal › 1er étage › Local technique'],
                ])
                ->missing('devicePorts'));

        $ports = $this->devicePorts($this->stack);

        // In the order of the device, member then place.
        $this->assertSame(['1/0/1', '1/0/2', '1/0/3', '1/0/4', '2/0/1', '2/0/2', '2/0/3', '2/0/4'], array_column($ports, 'name'));
        $this->assertSame(
            ['id' => $this->ports['1/0/1']->id, 'name' => '1/0/1', 'member' => 1, 'cord' => null, 'link' => null, 'plugged' => null, 'configuration' => 'Accès 10'],
            $ports[0],
        );
        $this->assertSame('PB-A 3 (B.03)', $ports[1]['cord']);
        $this->assertSame('sw-coeur-01 › 1/0/1', $ports[3]['link']);

        // A device of another site has nothing to offer.
        $this->assertSame([], $this->devicePorts($faraway));
    }

    /**
     * What the window "Patch" lists for a device.
     *
     * @return list<array<string, mixed>>
     */
    private function devicePorts(Device $device): array
    {
        $found = null;

        $this->actingAs($this->reader())
            ->get(route('patch-panels.show', ['patchPanel' => $this->panel, 'device' => $device->id]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$found): void {
                $page->reloadOnly('devicePorts', function (Assert $reload) use (&$found): void {
                    $found = $reload->toArray()['props']['devicePorts'];
                });
            });

        $this->assertIsArray($found);

        return $found;
    }

    public function test_the_page_of_a_device_tells_what_each_port_is_patched_to(): void
    {
        $jack = Jack::factory()->create(['floor_id' => $this->rack->floor_id, 'label' => 'B.15', 'patch_panel_port_id' => $this->port(15)->id]);
        $cord = PatchCordFactory::between($this->port(15), $this->ports['1/0/1']);
        $riser = PatchCordFactory::between($this->port(24), $this->ports['1/0/2']);

        $ports = null;

        $this->actingAs($this->reader())
            ->get(route('devices.show', $this->stack))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$ports): void {
                $ports = array_column($page->toArray()['props']['ports'], 'cord', 'name');
            });

        $this->assertIsArray($ports);
        $this->assertSame(
            [
                'id' => $cord->id,
                'panel_port' => ['id' => $this->port(15)->id, 'name' => 'PB-A 15', 'panel_id' => $this->panel->id],
                'jack' => ['id' => $jack->id, 'label' => 'B.15'],
            ],
            $ports['1/0/1'],
        );
        // Towards a port of the panel without a jack, the port of the panel.
        $this->assertSame([$riser->id, null], [$ports['1/0/2']['id'], $ports['1/0/2']['jack']]);
        $this->assertNull($ports['1/0/3']);

        $this->actingAs($this->reader())->get(route('patch-panels.show', ['patchPanel' => $this->panel, 'device' => 999999]))->assertSessionHasErrors('device');
    }

    // --- Rights and log ------------------------------------------------------

    public function test_a_reader_cannot_patch_or_unpatch(): void
    {
        $cord = PatchCordFactory::between($this->port(1), $this->ports['1/0/1']);
        $reader = $this->reader();

        $this->actingAs($reader)->post(route('patch-cords.store', $this->panel), [
            'panel_port_ids' => [$this->port(2)->id], 'device_id' => $this->stack->id, 'port_id' => $this->ports['1/0/2']->id,
        ])->assertForbidden();
        $this->actingAs($reader)->delete(route('patch-cords.destroy', $cord))->assertForbidden();
        $this->actingAs($reader)->put(route('patch-cords.update', $cord), ['color' => 'Vert'])->assertForbidden();
        $this->unpatchPorts([1], $reader)->assertForbidden();

        $this->assertSame([1 => '1/0/1'], $this->cords());
        $this->assertNull($cord->refresh()->color);

        auth()->logout();

        $this->delete(route('patch-cords.destroy', $cord))->assertRedirect(route('login'));

        // An administrator patches like an editor.
        $this->actingAs($this->admin())->post(route('patch-cords.store', $this->panel), [
            'panel_port_ids' => [$this->port(2)->id], 'device_id' => $this->stack->id, 'port_id' => $this->ports['1/0/2']->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame([1 => '1/0/1', 2 => '1/0/2'], $this->cords());
    }

    public function test_the_activity_log_tells_each_cord_in_words(): void
    {
        $this->patchPorts([7], $this->ports['1/0/3'], ['color' => 'Vert'])->assertSessionHasNoErrors();

        $this->actingAs($this->editor)->delete(route('patch-cords.destroy', PatchCord::query()->sole()))->assertSessionHasNoErrors();

        $this->actingAs($this->admin())
            ->get(route('activity-log.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.event_label', 'Port débrassé')
                ->where('entries.data.1.event_label', 'Port brassé')
                ->where('entries.data.1.changes', [
                    ['field' => 'Port de panneau', 'old' => null, 'new' => 'Bâtiment principal › 1er étage › Local technique › PB-A 7'],
                    ['field' => 'Port', 'old' => null, 'new' => 'sw-etage-1 (Siège) › 1/0/3'],
                    ['field' => 'Couleur', 'old' => null, 'new' => 'Vert'],
                ]));
    }
}
