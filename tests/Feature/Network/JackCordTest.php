<?php

// torsade/tests/Feature/Network/JackCordTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Enums\DeviceType;
use App\Enums\PortMode;
use App\Enums\Status;
use App\Models\Activity;
use App\Models\Building;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\Floor;
use App\Models\Jack;
use App\Models\JackCord;
use App\Models\PatchPanel;
use App\Models\PatchPanelPort;
use App\Models\Port;
use App\Models\PortVlan;
use App\Models\Room;
use App\Models\Site;
use App\Models\User;
use App\Models\Vlan;
use Database\Factories\JackCordFactory;
use Database\Factories\PatchCordFactory;
use Database\Factories\PortFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * Jack cords: a port of a device plugged into a wall jack of its site, on
 * the side of the desk, from the line of the port or from the jack; changed
 * and taken away. A port has one connector, a jack one cord.
 */
class JackCordTest extends NetworkTestCase
{
    private User $editor;

    private Site $office;

    private Floor $floor;

    private PatchPanel $panel;

    private Device $switch;

    private Device $accessPoint;

    /**
     * The jacks of the floor, by label: B.01 to B.08 on the ports 1 to 8 of
     * the panel.
     *
     * @var array<string, Jack>
     */
    private array $jacks = [];

    /**
     * The ports of the switch, by name: four.
     *
     * @var array<string, Port>
     */
    private array $ports = [];

    private Port $uplink;

    protected function setUp(): void
    {
        parent::setUp();

        $this->editor = $this->editor();
        $this->office = Site::factory()->create(['name' => 'Siège']);

        $building = Building::factory()->create(['site_id' => $this->office->id, 'name' => 'Bâtiment principal']);
        $this->floor = Floor::factory()->create(['building_id' => $building->id, 'level' => 1, 'name' => '1er étage']);

        $rack = Room::factory()->technical()->create(['floor_id' => $this->floor->id, 'name' => 'Placard technique']);
        $this->panel = PatchPanel::factory()->create(['room_id' => $rack->id, 'name' => 'PB-B', 'port_count' => 24]);

        foreach (range(1, 8) as $number) {
            $label = sprintf('B.%02d', $number);
            $this->jacks[$label] = Jack::factory()->create(['floor_id' => $this->floor->id, 'label' => $label, 'patch_panel_port_id' => $this->panelPort($number)->id]);
        }

        $this->switch = Device::factory()->create(['site_id' => $this->office->id, 'room_id' => $rack->id, 'name' => 'sw-etage-1', 'type' => DeviceType::Switch]);

        foreach (PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $this->switch->id, 'position' => 1]), 4) as $port) {
            $this->ports[$port->name] = $port;
        }

        $this->accessPoint = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'ap-salle-reunion', 'type' => DeviceType::AccessPoint]);
        $this->uplink = PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $this->accessPoint->id, 'position' => 1]), 1)[0];
    }

    private function panelPort(int $number): PatchPanelPort
    {
        return PatchPanelPort::query()->where('patch_panel_id', $this->panel->id)->where('number', $number)->sole();
    }

    /**
     * What the window "Plug into a jack" and the window "Plug a device"
     * send.
     *
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function plug(Jack $jack, Port $port, array $overrides = [], ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->editor)
            ->from(route('devices.show', $port->device_id))
            ->post(route('jack-cords.store'), [
                'jack_id' => $jack->id,
                'port_id' => $port->id,
                'color' => '',
                'description' => '',
                ...$overrides,
            ]);
    }

    /**
     * Each cord, from the label of its jack to the name of its port.
     *
     * @return array<string, string>
     */
    private function cords(): array
    {
        return JackCord::query()
            ->with(['jack', 'port'])
            ->get()
            ->sortBy(fn (JackCord $cord): string => $cord->jack->label)
            ->mapWithKeys(fn (JackCord $cord): array => [$cord->jack->label => $cord->port->name])
            ->all();
    }

    // --- Plugging ---------------------------------------------------------------

    public function test_an_editor_plugs_a_port_of_a_device_into_a_jack(): void
    {
        $this->plug($this->jacks['B.07'], $this->uplink, ['color' => 'Bleu', 'description' => 'Au plafond'])
            ->assertRedirect(route('devices.show', $this->accessPoint))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Port branché sur la prise.');

        $cord = JackCord::query()->sole();

        $this->assertSame(
            [$this->jacks['B.07']->id, $this->uplink->id, 'Bleu', 'Au plafond'],
            [$cord->jack_id, $cord->port_id, $cord->color, $cord->description],
        );

        // One entry, which names its two ends.
        $entry = $this->lastEntry(ActivityEvent::JackCordCreated);

        $this->assertSame('Bâtiment principal › 1er étage › B.07 ↔ ap-salle-reunion (Siège) › 1/0/1', $entry->getProperty('label'));
        $this->assertSame(
            [
                'jack' => 'Bâtiment principal › 1er étage › B.07',
                'port' => 'ap-salle-reunion (Siège) › 1/0/1',
                'color' => 'Bleu',
                'description' => 'Au plafond',
            ],
            ($entry->attribute_changes?->toArray() ?? [])['attributes'],
        );
        $this->assertTrue($this->editor->is($entry->causer));

        // An empty colour and description are kept as nothing.
        $this->plug($this->jacks['B.08'], $this->ports['1/0/4'])->assertSessionHasNoErrors();

        $this->assertSame([null, null], JackCord::query()->where('port_id', $this->ports['1/0/4']->id)->get(['color', 'description'])->map(fn (JackCord $cord): array => [$cord->color, $cord->description])->sole());
    }

    public function test_any_device_is_plugged_even_one_kept_in_a_technical_room(): void
    {
        // The switch is in the technical room of the floor.
        $this->plug($this->jacks['B.01'], $this->ports['1/0/1'])->assertSessionHasNoErrors();

        $this->assertSame(['B.01' => '1/0/1'], $this->cords());
    }

    public function test_a_cord_never_joins_two_sites(): void
    {
        $workshop = Site::factory()->create(['name' => 'Atelier']);
        $faraway = Device::factory()->create(['site_id' => $workshop->id, 'name' => 'ap-atelier-1']);
        $port = PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $faraway->id]), 1)[0];

        $this->assertRefused(
            $this->plug($this->jacks['B.01'], $port),
            'port_id',
            "L'équipement ap-atelier-1 est sur le site Atelier, la prise sur le site Siège : un cordon ne relie pas deux sites.",
        );
        $this->assertDatabaseCount('jack_cords', 0);
    }

    public function test_neither_a_retired_jack_nor_a_retired_device_is_plugged(): void
    {
        $this->jacks['B.01']->update(['status' => Status::Retired]);

        $this->assertRefused(
            $this->plug($this->jacks['B.01'], $this->uplink),
            'jack_id',
            "La prise B.01 est retirée : rien n'y est branché.",
        );

        $this->accessPoint->update(['status' => Status::Retired]);

        $this->assertRefused(
            $this->plug($this->jacks['B.02'], $this->uplink),
            'port_id',
            "L'équipement ap-salle-reunion est retiré : il n'est branché sur aucune prise.",
        );

        // A planned one is plugged: it is designed.
        $this->accessPoint->update(['status' => Status::Planned]);
        $this->jacks['B.02']->update(['status' => Status::Planned]);

        $this->plug($this->jacks['B.02'], $this->uplink)->assertSessionHasNoErrors();

        $this->assertSame(['B.02' => '1/0/1'], $this->cords());
    }

    public function test_a_jack_takes_one_device_and_a_port_one_jack(): void
    {
        JackCordFactory::between($this->jacks['B.03'], $this->ports['1/0/2']);

        $this->assertRefused(
            $this->plug($this->jacks['B.03'], $this->uplink),
            'jack_id',
            "sw-etage-1 1/0/2 est déjà branché sur la prise B.03 : débranchez-le d'abord.",
        );
        $this->assertRefused(
            $this->plug($this->jacks['B.04'], $this->ports['1/0/2']),
            'port_id',
            "sw-etage-1 1/0/2 est déjà branché sur la prise B.03 : débranchez-le d'abord.",
        );

        $this->assertSame(['B.03' => '1/0/2'], $this->cords());
    }

    public function test_a_port_has_one_connector_whichever_comes_first(): void
    {
        // Patched to a panel: not plugged into a jack.
        PatchCordFactory::between($this->panelPort(1), $this->ports['1/0/1']);

        $this->assertRefused(
            $this->plug($this->jacks['B.05'], $this->ports['1/0/1']),
            'port_id',
            "sw-etage-1 1/0/1 est brassé vers PB-B 1 : un port n'a qu'un connecteur, débrassez-le d'abord.",
        );

        // Plugged into a jack: not patched to a panel, one port or a series.
        JackCordFactory::between($this->jacks['B.05'], $this->ports['1/0/3']);

        $this->assertRefused(
            $this->actingAs($this->editor)->post(route('patch-cords.store', $this->panel), [
                'panel_port_ids' => [$this->panelPort(12)->id, $this->panelPort(13)->id],
                'device_id' => $this->switch->id,
                'port_id' => $this->ports['1/0/2']->id,
            ]),
            'port_id',
            "sw-etage-1 1/0/3 est branché sur la prise B.05 : un port n'a qu'un connecteur, débranchez-le d'abord.",
        );

        $this->assertDatabaseCount('patch_cords', 1);
        $this->assertSame(['B.05' => '1/0/3'], $this->cords());
    }

    public function test_a_cord_laid_in_the_meantime_is_told_in_words_never_as_an_error(): void
    {
        $other = $this->ports['1/0/2'];

        // Another request plugs a device into the jack between the checks
        // and the write: the database refuses the second cord.
        JackCord::creating(function (JackCord $cord) use ($other): void {
            if ($cord->port_id !== $other->id) {
                DB::table('jack_cords')->insert(['jack_id' => $cord->jack_id, 'port_id' => $other->id, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
            }
        });

        $this->assertRefused(
            $this->plug($this->jacks['B.06'], $this->uplink),
            'jack_id',
            'Un équipement a été branché sur cette prise entre-temps : rechargez la page.',
        );

        $this->assertSame(0, $this->countEntries(ActivityEvent::JackCordCreated));
    }

    public function test_a_port_or_a_jack_that_no_longer_exists_is_told_in_words(): void
    {
        $this->assertRefused(
            $this->actingAs($this->editor)->post(route('jack-cords.store'), ['jack_id' => 999999, 'port_id' => $this->uplink->id]),
            'jack_id',
            "Cette prise n'existe plus.",
        );
        $this->assertRefused(
            $this->actingAs($this->editor)->post(route('jack-cords.store'), ['jack_id' => $this->jacks['B.01']->id, 'port_id' => 999999]),
            'port_id',
            "Ce port n'existe plus.",
        );

        // The shape of the request is checked first.
        $this->actingAs($this->editor)
            ->post(route('jack-cords.store'), ['jack_id' => 'B.01', 'color' => str_repeat('a', 33), 'description' => str_repeat('a', 256)])
            ->assertSessionHasErrors(['jack_id', 'port_id', 'color', 'description']);
    }

    // --- Changing and taking away -----------------------------------------------

    public function test_the_colour_and_the_description_of_a_cord_change_and_its_two_ends_stay(): void
    {
        $cord = JackCordFactory::between($this->jacks['B.07'], $this->uplink, ['color' => 'Bleu']);

        $this->actingAs($this->editor)
            ->from(route('jacks.index'))
            ->put(route('jack-cords.update', $cord), ['color' => 'Gris', 'description' => 'Sous le faux plafond', 'jack_id' => $this->jacks['B.08']->id])
            ->assertRedirect(route('jacks.index'))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Cordon modifié.');

        $cord->refresh();

        $this->assertSame(
            [$this->jacks['B.07']->id, $this->uplink->id, 'Gris', 'Sous le faux plafond'],
            [$cord->jack_id, $cord->port_id, $cord->color, $cord->description],
        );

        $entry = $this->lastEntry(ActivityEvent::JackCordUpdated);

        $this->assertSame(
            ['old' => ['color' => 'Bleu', 'description' => null], 'attributes' => ['color' => 'Gris', 'description' => 'Sous le faux plafond']],
            $entry->attribute_changes?->toArray(),
        );

        // Nothing changed: nothing written.
        $this->actingAs($this->editor)->put(route('jack-cords.update', $cord), ['color' => 'Gris', 'description' => 'Sous le faux plafond'])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->countEntries(ActivityEvent::JackCordUpdated));
    }

    public function test_a_cord_unplugged_in_the_meantime_is_told_in_words_never_as_an_error(): void
    {
        $cord = JackCordFactory::between($this->jacks['B.07'], $this->uplink);
        $cord->delete();

        $this->assertRefused(
            $this->actingAs($this->editor)->put(route('jack-cords.update', $cord->id), ['color' => 'Vert']),
            'cord',
            'Ce cordon a été débranché entre-temps : rechargez la page.',
        );

        // Unplugging it again leaves nothing to do.
        $this->actingAs($this->editor)->delete(route('jack-cords.destroy', $cord->id))->assertSessionHasNoErrors();

        $this->assertSame(0, $this->countEntries(ActivityEvent::JackCordDeleted));
    }

    public function test_unplugging_takes_the_cord_away_and_keeps_the_jack_and_the_port(): void
    {
        $cord = JackCordFactory::between($this->jacks['B.07'], $this->uplink, ['color' => 'Bleu']);

        $this->actingAs($this->editor)
            ->from(route('jacks.index'))
            ->delete(route('jack-cords.destroy', $cord))
            ->assertRedirect(route('jacks.index'))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Port débranché de la prise.');

        $this->assertModelMissing($cord);
        $this->assertModelExists($this->jacks['B.07']);
        $this->assertModelExists($this->uplink);

        $entry = $this->lastEntry(ActivityEvent::JackCordDeleted);

        $this->assertSame('Bâtiment principal › 1er étage › B.07 ↔ ap-salle-reunion (Siège) › 1/0/1', $entry->getProperty('label'));
        $this->assertSame(
            ['jack' => 'Bâtiment principal › 1er étage › B.07', 'port' => 'ap-salle-reunion (Siège) › 1/0/1', 'color' => 'Bleu', 'description' => null],
            ($entry->attribute_changes?->toArray() ?? [])['old'],
        );
    }

    public function test_every_port_of_a_device_is_unplugged_at_once_one_entry_of_the_log_per_cord(): void
    {
        JackCordFactory::between($this->jacks['B.04'], $this->ports['1/0/3']);
        JackCordFactory::between($this->jacks['B.01'], $this->ports['1/0/1']);
        // The cord of another device stays.
        JackCordFactory::between($this->jacks['B.07'], $this->uplink);

        $this->actingAs($this->editor)
            ->delete(route('devices.jack-cords.destroy-all', $this->switch))
            ->assertRedirect(route('devices.show', $this->switch))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', '2 cordons supprimés.');

        $this->assertSame(['B.07' => '1/0/1'], $this->cords());
        $this->assertSame($this->uplink->id, JackCord::query()->sole()->port_id);

        // One entry per cord, in the order of the device.
        $this->assertSame(
            ['Bâtiment principal › 1er étage › B.01 ↔ sw-etage-1 (Siège) › 1/0/1', 'Bâtiment principal › 1er étage › B.04 ↔ sw-etage-1 (Siège) › 1/0/3'],
            Activity::query()->where('event', ActivityEvent::JackCordDeleted->value)->orderBy('id')->get()->map(fn (Activity $entry): string => (string) $entry->getProperty('label'))->all(),
        );

        // Nothing left: told in words, nothing written.
        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('devices.jack-cords.destroy-all', $this->switch)),
            'jack_cords',
            "Plus aucun port de cet équipement n'est branché sur une prise : rechargez la page.",
        );
        $this->assertSame(2, $this->countEntries(ActivityEvent::JackCordDeleted));
    }

    // --- What a cord keeps from being deleted or moved ----------------------------

    public function test_a_plugged_port_member_or_device_is_not_deleted_and_the_refusal_names_the_cord(): void
    {
        JackCordFactory::between($this->jacks['B.01'], $this->ports['1/0/1']);
        JackCordFactory::between($this->jacks['B.02'], $this->ports['1/0/3']);

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('ports.destroy', $this->ports['1/0/1'])),
            'port',
            "1/0/1 est branché sur la prise B.01 : débranchez-le d'abord.",
        );
        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('ports.destroy-many', $this->switch), ['ports' => [$this->ports['1/0/2']->id, $this->ports['1/0/3']->id]]),
            'ports',
            "1/0/3 est branché sur la prise B.02 : débranchez-le d'abord.",
        );
        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('device-members.destroy', $this->switch->members()->sole())),
            'member',
            "1/0/1 est branché sur la prise B.01, 1/0/3 est branché sur la prise B.02 : débranchez-les d'abord.",
        );
        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('devices.destroy', $this->switch)),
            'jack_cords',
            "1/0/1 est branché sur la prise B.01, 1/0/3 est branché sur la prise B.02 : débranchez-les d'abord.",
        );

        $this->assertDatabaseCount('ports', 5);
        $this->assertModelExists($this->switch);
    }

    public function test_a_jack_a_device_is_plugged_into_is_not_deleted(): void
    {
        $cord = JackCordFactory::between($this->jacks['B.07'], $this->uplink);

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('jacks.destroy', $this->jacks['B.07'])),
            'jack',
            "ap-salle-reunion 1/0/1 est branché sur cette prise : débranchez-le d'abord.",
        );
        $this->assertModelExists($this->jacks['B.07']);

        $cord->delete();

        $this->actingAs($this->editor)->delete(route('jacks.destroy', $this->jacks['B.07']))->assertSessionHasNoErrors();

        $this->assertModelMissing($this->jacks['B.07']);
    }

    public function test_a_plugged_device_does_not_move_to_another_site_and_the_refusal_names_both_kinds_of_cords(): void
    {
        $workshop = Site::factory()->create(['name' => 'Atelier']);
        $move = fn (Device $device) => $this->actingAs($this->editor)->put(route('devices.update', $device), [
            'site_id' => $workshop->id, 'room_id' => null, 'name' => $device->name, 'type' => $device->type->value, 'status' => 'active',
        ]);

        JackCordFactory::between($this->jacks['B.02'], $this->ports['1/0/2']);

        $this->assertRefused(
            $move($this->switch),
            'site_id',
            "1/0/2 est branché sur la prise B.02 : débranchez-le avant de déplacer l'équipement vers un autre site.",
        );

        // Both kinds of cords, each named.
        PatchCordFactory::between($this->panelPort(20), $this->ports['1/0/1']);

        $this->assertRefused(
            $move($this->switch),
            'site_id',
            "1/0/1 est brassé vers PB-B 20, 1/0/2 est branché sur la prise B.02 : débrassez-les et débranchez-les avant de déplacer l'équipement vers un autre site.",
        );

        $this->assertSame($this->office->id, $this->switch->refresh()->site_id);

        // Ten cords named at most, then how many more.
        $large = Device::factory()->create(['site_id' => $this->office->id, 'name' => 'sw-reunion', 'type' => DeviceType::Switch]);
        $ports = PortFactory::series(DeviceMember::factory()->primary()->create(['device_id' => $large->id, 'position' => 1]), 12);

        foreach ($ports as $index => $port) {
            $jack = Jack::factory()->create(['floor_id' => $this->floor->id, 'label' => sprintf('S.%02d', $index + 1)]);
            JackCordFactory::between($jack, $port);
        }

        $move($large);

        $this->assertStringStartsWith('1/0/1 est branché sur la prise S.01, 1/0/2 est branché sur la prise S.02, ', $this->error('site_id'));
        $this->assertStringEndsWith("1/0/10 est branché sur la prise S.10 et 2 de plus : débranchez-les avant de déplacer l'équipement vers un autre site.", $this->error('site_id'));

        // Unplugged, the device moves.
        $this->actingAs($this->editor)->delete(route('devices.jack-cords.destroy-all', $large))->assertSessionHasNoErrors();

        $move($large)->assertSessionHasNoErrors();

        $this->assertSame($workshop->id, $large->refresh()->site_id);
    }

    // --- Pages ----------------------------------------------------------------

    public function test_the_page_of_a_device_tells_the_jack_each_port_is_plugged_into(): void
    {
        $cord = JackCordFactory::between($this->jacks['B.07'], $this->uplink, ['color' => 'Bleu']);

        $ports = null;

        $this->actingAs($this->reader())
            ->get(route('devices.show', $this->accessPoint))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$ports): void {
                $ports = array_column($page->missing('pluggableJacks')->toArray()['props']['ports'], 'jack_cord', 'name');
            });

        $this->assertSame(
            [
                '1/0/1' => [
                    'id' => $cord->id,
                    'color' => 'Bleu',
                    'description' => null,
                    'jack' => ['id' => $this->jacks['B.07']->id, 'label' => 'B.07', 'path' => 'Bâtiment principal › 1er étage › B.07', 'floor_id' => $this->floor->id],
                ],
            ],
            $ports,
        );
    }

    public function test_the_window_of_a_port_offers_the_free_jacks_of_the_site_each_with_its_path(): void
    {
        // A jack taken, a retired one, one of another floor, one of
        // another site.
        JackCordFactory::between($this->jacks['B.01'], $this->ports['1/0/4']);
        $this->jacks['B.02']->update(['status' => Status::Retired]);

        $ground = Floor::factory()->create(['building_id' => $this->floor->building_id, 'level' => 0, 'name' => 'Rez-de-chaussée']);
        $reception = Jack::factory()->create(['floor_id' => $ground->id, 'label' => 'R.10']);
        $second = Jack::factory()->create(['floor_id' => $ground->id, 'label' => 'R.9']);

        $elsewhere = Floor::factory()->create(['building_id' => Building::factory()->create(['site_id' => Site::factory()->create()->id])->id]);
        Jack::factory()->create(['floor_id' => $elsewhere->id, 'label' => 'X.01']);

        // What the port at the end of B.03 carries is part of its path.
        PatchCordFactory::between($this->panelPort(3), $this->ports['1/0/3']);
        $vlan = Vlan::factory()->create(['site_id' => $this->office->id, 'vid' => 10]);
        $this->ports['1/0/3']->update(['mode' => PortMode::Access]);
        PortVlan::factory()->create(['port_id' => $this->ports['1/0/3']->id, 'vlan_id' => $vlan->id, 'tagged' => false]);

        $groups = null;

        $this->actingAs($this->reader())
            ->get(route('devices.show', $this->accessPoint))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$groups): void {
                $page->reloadOnly('pluggableJacks', function (Assert $reload) use (&$groups): void {
                    $groups = $reload->toArray()['props']['pluggableJacks'];
                });
            });

        $this->assertIsArray($groups);

        // By building, floor (lowest first) and label, a number read as a
        // number.
        $this->assertSame(['Bâtiment principal › Rez-de-chaussée', 'Bâtiment principal › 1er étage'], array_column($groups, 'place'));
        $this->assertSame([$second->id, $reception->id], array_column($groups[0]['jacks'], 'id'));
        $this->assertSame(['B.03', 'B.04', 'B.05', 'B.06', 'B.07', 'B.08'], array_column($groups[1]['jacks'], 'label'));
        $this->assertSame(
            [
                'jack' => ['id' => $this->jacks['B.03']->id, 'label' => 'B.03', 'place' => 'Bâtiment principal › 1er étage'],
                'plugged' => null,
                'panel_port' => ['id' => $this->panelPort(3)->id, 'name' => 'PB-B 3', 'panel_id' => $this->panel->id],
                'device' => ['id' => $this->switch->id, 'name' => 'sw-etage-1'],
                'port' => ['id' => $this->ports['1/0/3']->id, 'name' => '1/0/3'],
                'configuration' => 'Accès 10',
            ],
            $groups[1]['jacks'][0]['path'],
        );
    }

    public function test_the_window_of_a_jack_offers_the_devices_of_its_site_then_their_free_ports(): void
    {
        Device::factory()->create(['site_id' => $this->office->id, 'name' => 'ap-retiree', 'status' => Status::Retired]);
        Device::factory()->create(['site_id' => Site::factory()->create()->id, 'name' => 'ap-ailleurs']);
        PatchCordFactory::between($this->panelPort(10), $this->ports['1/0/1']);
        JackCordFactory::between($this->jacks['B.01'], $this->ports['1/0/2']);

        $props = $this->jackWindow(['plug_site' => $this->office->id], 'plugDevices');

        $this->assertSame(
            [
                ['id' => $this->accessPoint->id, 'name' => 'ap-salle-reunion', 'place' => null, 'free_ports' => 1],
                ['id' => $this->switch->id, 'name' => 'sw-etage-1', 'place' => 'Bâtiment principal › 1er étage › Placard technique', 'free_ports' => 2],
            ],
            $props,
        );

        // The ports without a connector, in the order of the device.
        $ports = $this->jackWindow(['plug_site' => $this->office->id, 'plug_device' => $this->switch->id], 'plugPorts');

        $this->assertSame(
            [
                'device' => ['id' => $this->switch->id, 'name' => 'sw-etage-1'],
                'ports' => [
                    ['id' => $this->ports['1/0/3']->id, 'name' => '1/0/3', 'member' => 1, 'configuration' => null],
                    ['id' => $this->ports['1/0/4']->id, 'name' => '1/0/4', 'member' => 1, 'configuration' => null],
                ],
            ],
            $ports,
        );

        // A device of another site, or a retired one, offers nothing.
        $this->assertNull($this->jackWindow(['plug_site' => $this->office->id, 'plug_device' => Device::query()->where('name', 'ap-ailleurs')->value('id')], 'plugPorts'));
        $this->assertNull($this->jackWindow(['plug_site' => $this->office->id, 'plug_device' => Device::query()->where('name', 'ap-retiree')->value('id')], 'plugPorts'));
    }

    /**
     * What the list of jacks sends when the window "Plug a device" asks.
     *
     * @param  array<string, int|null>  $query
     */
    private function jackWindow(array $query, string $prop): mixed
    {
        $found = 'not asked';

        $this->actingAs($this->reader())
            ->get(route('jacks.index', $query))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$found, $prop): void {
                $page->missing($prop)->reloadOnly($prop, function (Assert $reload) use (&$found, $prop): void {
                    $found = $reload->toArray()['props'][$prop];
                });
            });

        $this->assertNotSame('not asked', $found);

        return $found;
    }

    public function test_the_path_of_a_jack_tells_first_the_device_plugged_into_it(): void
    {
        PatchCordFactory::between($this->panelPort(7), $this->ports['1/0/2']);
        $cord = JackCordFactory::between($this->jacks['B.07'], $this->uplink, ['color' => 'Gris']);

        $line = null;

        $this->actingAs($this->reader())
            ->get(route('jacks.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$line): void {
                $line = collect($page->toArray()['props']['jacks'])->firstWhere('label', 'B.07');
            });

        $this->assertIsArray($line);
        $this->assertSame(
            ['cord_id' => $cord->id, 'device' => ['id' => $this->accessPoint->id, 'name' => 'ap-salle-reunion'], 'port' => ['id' => $this->uplink->id, 'name' => '1/0/1']],
            $line['path']['plugged'],
        );
        $this->assertSame(['id' => $cord->id, 'color' => 'Gris', 'description' => null], $line['cord']);
        $this->assertSame('sw-etage-1', $line['path']['device']['name']);
    }

    // --- Rights ---------------------------------------------------------------

    public function test_a_reader_cannot_plug_change_or_unplug(): void
    {
        $cord = JackCordFactory::between($this->jacks['B.07'], $this->uplink);
        $reader = $this->reader();

        $this->plug($this->jacks['B.01'], $this->ports['1/0/1'], [], $reader)->assertForbidden();
        $this->actingAs($reader)->put(route('jack-cords.update', $cord), ['color' => 'Vert'])->assertForbidden();
        $this->actingAs($reader)->delete(route('jack-cords.destroy', $cord))->assertForbidden();
        $this->actingAs($reader)->delete(route('devices.jack-cords.destroy-all', $this->accessPoint))->assertForbidden();

        $this->assertSame(['B.07' => '1/0/1'], $this->cords());
        $this->assertNull($cord->refresh()->color);

        auth()->logout();

        $this->delete(route('jack-cords.destroy', $cord))->assertRedirect(route('login'));

        // An administrator plugs like an editor.
        $this->plug($this->jacks['B.01'], $this->ports['1/0/1'], [], $this->admin())->assertSessionHasNoErrors();

        $this->assertSame(['B.01' => '1/0/1', 'B.07' => '1/0/1'], $this->cords());
    }

    public function test_the_activity_log_tells_each_cord_in_words(): void
    {
        $this->plug($this->jacks['B.07'], $this->uplink, ['color' => 'Vert'])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->put(route('jack-cords.update', JackCord::query()->sole()), ['color' => 'Bleu'])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->delete(route('jack-cords.destroy', JackCord::query()->sole()))->assertSessionHasNoErrors();

        $this->actingAs($this->admin())
            ->get(route('activity-log.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.event_label', "Port débranché d'une prise")
                ->where('entries.data.1.event_label', 'Cordon de prise modifié')
                ->where('entries.data.2.event_label', 'Port branché sur une prise')
                ->where('entries.data.2.changes', [
                    ['field' => 'Prise', 'old' => null, 'new' => 'Bâtiment principal › 1er étage › B.07'],
                    ['field' => 'Port', 'old' => null, 'new' => 'ap-salle-reunion (Siège) › 1/0/1'],
                    ['field' => 'Couleur', 'old' => null, 'new' => 'Vert'],
                ]));
    }
}
