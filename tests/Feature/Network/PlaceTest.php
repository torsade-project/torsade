<?php

// torsade/tests/Feature/Network/PlaceTest.php

namespace Tests\Feature\Network;

use App\Enums\ActivityEvent;
use App\Models\Building;
use App\Models\Device;
use App\Models\DeviceMember;
use App\Models\Floor;
use App\Models\PatchPanel;
use App\Models\Room;
use App\Models\Site;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Buildings, floors and rooms: the places of a site, read on its page and
 * written in windows opened from it.
 */
class PlaceTest extends NetworkTestCase
{
    private User $editor;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->editor = $this->editor();
        $this->site = Site::factory()->create(['name' => 'Siège']);
    }

    private function building(string $name = 'Bâtiment principal', ?Site $site = null): Building
    {
        return Building::factory()->create(['site_id' => ($site ?? $this->site)->id, 'name' => $name]);
    }

    private function floor(Building $building, int $level, string $name): Floor
    {
        return Floor::factory()->create(['building_id' => $building->id, 'level' => $level, 'name' => $name]);
    }

    // --- The tree of the page of a site ---------------------------------------

    public function test_the_page_of_a_site_shows_its_places_as_a_tree(): void
    {
        $main = $this->building('Bâtiment principal');
        $main->update(['code' => 'A', 'description' => 'Bureaux']);
        $annex = $this->building('Annexe');

        // Created out of order: the floors come back from the lowest up.
        $first = $this->floor($main, 1, '1er étage');
        $basement = $this->floor($main, -1, 'Sous-sol');
        $ground = $this->floor($main, 0, 'Rez-de-chaussée');

        $technical = Room::factory()->technical()->create(['floor_id' => $ground->id, 'name' => 'Local technique', 'description' => 'Baie 42U']);
        $reception = Room::factory()->create(['floor_id' => $ground->id, 'name' => 'Accueil']);
        Room::factory()->create(['floor_id' => $first->id, 'name' => 'Bureau 12']);

        Device::factory()->count(3)->create(['site_id' => $this->site->id, 'room_id' => $technical->id]);
        Device::factory()->create(['site_id' => $this->site->id, 'room_id' => $reception->id]);

        // The places of another site are not those of this one.
        $this->building('Entrepôt', Site::factory()->create());

        $this->actingAs($this->reader())
            ->get(route('sites.show', $this->site))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sites/Show')
                ->has('places', 2)
                // By name.
                ->where('places.0.name', 'Annexe')
                ->where('places.0.floors', [])
                ->where('places.1', [
                    'id' => $main->id,
                    'name' => 'Bâtiment principal',
                    'code' => 'A',
                    'description' => 'Bureaux',
                    'floors' => [
                        ['id' => $basement->id, 'level' => -1, 'name' => 'Sous-sol', 'description' => null, 'jacks_count' => 0, 'rooms' => []],
                        [
                            'id' => $ground->id, 'level' => 0, 'name' => 'Rez-de-chaussée', 'description' => null, 'jacks_count' => 0,
                            'rooms' => [
                                ['id' => $reception->id, 'name' => 'Accueil', 'is_technical' => false, 'description' => null, 'devices_count' => 1, 'panels' => []],
                                ['id' => $technical->id, 'name' => 'Local technique', 'is_technical' => true, 'description' => 'Baie 42U', 'devices_count' => 3, 'panels' => []],
                            ],
                        ],
                        [
                            'id' => $first->id, 'level' => 1, 'name' => '1er étage', 'description' => null, 'jacks_count' => 0,
                            'rooms' => [
                                ['id' => Room::query()->where('name', 'Bureau 12')->sole()->id, 'name' => 'Bureau 12', 'is_technical' => false, 'description' => null, 'devices_count' => 0, 'panels' => []],
                            ],
                        ],
                    ],
                ]));

        $this->assertSame($annex->id, Building::query()->where('name', 'Annexe')->sole()->id);
    }

    public function test_a_place_is_written_with_its_path(): void
    {
        $ground = $this->floor($this->building(), 0, 'Rez-de-chaussée');
        $room = Room::factory()->create(['floor_id' => $ground->id, 'name' => 'Local technique']);

        $this->assertSame('Bâtiment principal › Rez-de-chaussée', $ground->path());
        $this->assertSame('Bâtiment principal › Rez-de-chaussée › Local technique', $room->path());
        $this->assertSame($this->site->id, $room->siteId());
    }

    // --- Buildings ------------------------------------------------------------

    public function test_an_editor_creates_updates_and_deletes_a_building(): void
    {
        $this->actingAs($this->editor)
            ->post(route('buildings.store', $this->site), ['name' => 'Bâtiment principal', 'code' => 'bat-a', 'description' => 'Bureaux'])
            ->assertRedirect(route('sites.show', $this->site))
            ->assertSessionHasNoErrors();

        $building = Building::query()->sole();

        $this->assertSame($this->site->id, $building->site_id);
        // Stored in capitals, like the code of a site.
        $this->assertSame('BAT-A', $building->code);

        $this->actingAs($this->editor)
            ->put(route('buildings.update', $building), ['name' => 'Bâtiment A', 'code' => '', 'description' => ''])
            ->assertRedirect(route('sites.show', $this->site))
            ->assertSessionHasNoErrors();

        $building->refresh();

        $this->assertSame('Bâtiment A', $building->name);
        $this->assertNull($building->code);
        $this->assertNull($building->description);

        $this->actingAs($this->editor)
            ->delete(route('buildings.destroy', $building))
            ->assertRedirect(route('sites.show', $this->site))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('buildings', 0);
    }

    public function test_the_name_and_the_code_of_a_building_are_unique_within_its_site(): void
    {
        $this->building('Bâtiment principal')->update(['code' => 'A']);

        $this->assertRefused(
            $this->actingAs($this->editor)->post(route('buildings.store', $this->site), ['name' => 'bâtiment principal']),
            'name',
            'Un autre bâtiment de ce site porte déjà ce nom.',
        );

        $this->assertRefused(
            $this->actingAs($this->editor)->post(route('buildings.store', $this->site), ['name' => 'Annexe', 'code' => 'a']),
            'code',
            'Un autre bâtiment de ce site porte déjà ce code.',
        );

        // Another site has its own buildings; and several buildings of a
        // site may have no code.
        $other = Site::factory()->create();

        $this->actingAs($this->editor)->post(route('buildings.store', $other), ['name' => 'Bâtiment principal', 'code' => 'A'])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post(route('buildings.store', $this->site), ['name' => 'Annexe'])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post(route('buildings.store', $this->site), ['name' => 'Atelier'])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('buildings', 4);
    }

    public function test_the_shape_of_a_building_is_checked(): void
    {
        $post = fn (array $data) => $this->actingAs($this->editor)->post(route('buildings.store', $this->site), $data);

        $this->assertRefused($post(['name' => '']), 'name');
        $this->assertRefused($post(['name' => str_repeat('a', 101)]), 'name');
        $this->assertRefused($post(['name' => 'Annexe', 'code' => 'ABCDEFGHIJK']), 'code');
        $this->assertRefused(
            $post(['name' => 'Annexe', 'code' => 'bât']),
            'code',
            'Un code de bâtiment ne contient que des lettres sans accent, des chiffres et des tirets.',
        );
        $this->assertRefused($post(['name' => 'Annexe', 'description' => str_repeat('a', 1001)]), 'description');

        $this->assertDatabaseCount('buildings', 0);
    }

    // --- Floors ---------------------------------------------------------------

    public function test_an_editor_creates_updates_and_deletes_a_floor(): void
    {
        $building = $this->building();

        $this->actingAs($this->editor)
            ->post(route('floors.store', $building), ['level' => '-1', 'name' => 'Sous-sol'])
            ->assertRedirect(route('sites.show', $this->site))
            ->assertSessionHasNoErrors();

        $floor = Floor::query()->sole();

        $this->assertSame($building->id, $floor->building_id);
        $this->assertSame(-1, $floor->level);

        $this->actingAs($this->editor)
            ->put(route('floors.update', $floor), ['level' => 0, 'name' => 'Rez-de-chaussée', 'description' => 'Accueil et ateliers'])
            ->assertRedirect(route('sites.show', $this->site))
            ->assertSessionHasNoErrors();

        $floor->refresh();

        $this->assertSame([0, 'Rez-de-chaussée', 'Accueil et ateliers'], [$floor->level, $floor->name, $floor->description]);

        $this->actingAs($this->editor)
            ->delete(route('floors.destroy', $floor))
            ->assertRedirect(route('sites.show', $this->site))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('floors', 0);
    }

    public function test_the_level_and_the_name_of_a_floor_are_unique_within_its_building(): void
    {
        $building = $this->building();
        $ground = $this->floor($building, 0, 'Rez-de-chaussée');

        // The refusal names the floor that already has the level.
        $this->assertRefused(
            $this->actingAs($this->editor)->post(route('floors.store', $building), ['level' => 0, 'name' => 'Accueil']),
            'level',
            "L'étage Rez-de-chaussée de ce bâtiment est déjà au niveau 0.",
        );

        $this->assertRefused(
            $this->actingAs($this->editor)->post(route('floors.store', $building), ['level' => 1, 'name' => 'Rez-de-chaussée']),
            'name',
            'Un autre étage de ce bâtiment porte déjà ce nom.',
        );

        // A floor keeps its own level and name when updated.
        $this->actingAs($this->editor)
            ->put(route('floors.update', $ground), ['level' => 0, 'name' => 'Rez-de-chaussée', 'description' => 'Accueil'])
            ->assertSessionHasNoErrors();

        // Another building has its own ground floor.
        $this->actingAs($this->editor)
            ->post(route('floors.store', $this->building('Annexe')), ['level' => 0, 'name' => 'Rez-de-chaussée'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('floors', 2);
    }

    public function test_a_level_goes_from_minus_9_to_99(): void
    {
        $building = $this->building();
        $post = fn (mixed $level, string $name) => $this->actingAs($this->editor)->post(route('floors.store', $building), ['level' => $level, 'name' => $name]);

        foreach ([-10, 100, 1.5, 'rez', ''] as $index => $level) {
            $this->assertRefused($post($level, "Étage refusé {$index}"), 'level');
        }

        $post(-9, 'Le plus bas')->assertSessionHasNoErrors();
        $post(99, 'Le plus haut')->assertSessionHasNoErrors();

        $this->assertSame([-9, 99], Floor::query()->orderBy('level')->pluck('level')->all());
    }

    // --- Rooms ----------------------------------------------------------------

    public function test_an_editor_creates_updates_and_deletes_a_room(): void
    {
        $floor = $this->floor($this->building(), 0, 'Rez-de-chaussée');

        $this->actingAs($this->editor)
            ->post(route('rooms.store', $floor), ['name' => 'Local technique', 'is_technical' => true, 'description' => 'Baie 42U'])
            ->assertRedirect(route('sites.show', $this->site))
            ->assertSessionHasNoErrors();

        $room = Room::query()->sole();

        $this->assertSame($floor->id, $room->floor_id);
        $this->assertTrue($room->is_technical);

        // A room is not technical unless said so.
        $this->actingAs($this->editor)->post(route('rooms.store', $floor), ['name' => 'Accueil'])->assertSessionHasNoErrors();

        $this->assertFalse(Room::query()->where('name', 'Accueil')->sole()->is_technical);

        $this->actingAs($this->editor)
            ->put(route('rooms.update', $room), ['name' => 'Réserve', 'is_technical' => false])
            ->assertRedirect(route('sites.show', $this->site))
            ->assertSessionHasNoErrors();

        $room->refresh();

        $this->assertSame(['Réserve', false, null], [$room->name, $room->is_technical, $room->description]);

        $this->actingAs($this->editor)
            ->delete(route('rooms.destroy', $room))
            ->assertRedirect(route('sites.show', $this->site))
            ->assertSessionHasNoErrors();

        $this->assertSame(['Accueil'], Room::query()->pluck('name')->all());
    }

    public function test_the_name_of_a_room_is_unique_within_its_floor(): void
    {
        $building = $this->building();
        $ground = $this->floor($building, 0, 'Rez-de-chaussée');
        Room::factory()->create(['floor_id' => $ground->id, 'name' => 'Local technique']);

        $this->assertRefused(
            $this->actingAs($this->editor)->post(route('rooms.store', $ground), ['name' => 'LOCAL TECHNIQUE']),
            'name',
            'Un autre local de cet étage porte déjà ce nom.',
        );

        // Each floor may have its own technical room.
        $this->actingAs($this->editor)
            ->post(route('rooms.store', $this->floor($building, 1, '1er étage')), ['name' => 'Local technique', 'is_technical' => true])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('rooms', 2);
    }

    // --- Moving a place within its site ---------------------------------------

    public function test_a_room_is_moved_to_another_floor_of_its_site_with_the_devices_kept_in_it(): void
    {
        $main = $this->building('Bâtiment principal');
        $annex = $this->building('Annexe');
        $ground = $this->floor($main, 0, 'Rez-de-chaussée');
        $first = $this->floor($main, 1, '1er étage');
        $elsewhere = $this->floor($annex, 0, 'Rez-de-chaussée');

        $room = Room::factory()->technical()->create(['floor_id' => $ground->id, 'name' => 'Local technique']);
        $device = Device::factory()->create(['site_id' => $this->site->id, 'room_id' => $room->id]);

        // To another floor of the same building.
        $this->actingAs($this->editor)
            ->put(route('rooms.update', $room), ['name' => 'Local technique', 'is_technical' => true, 'floor_id' => $first->id])
            ->assertRedirect(route('sites.show', $this->site))
            ->assertSessionHasNoErrors();

        $this->assertSame($first->id, $room->refresh()->floor_id);
        $this->assertSame('Bâtiment principal › 1er étage › Local technique', $room->path());

        // The log tells where it was and where it is.
        $changes = $this->lastEntry(ActivityEvent::RoomUpdated)->attribute_changes?->toArray() ?? [];

        $this->assertSame(['floor' => 'Bâtiment principal › Rez-de-chaussée'], $changes['old']);
        $this->assertSame(['floor' => 'Bâtiment principal › 1er étage'], $changes['attributes']);

        // To a floor of another building of the site, renamed on the way.
        $this->actingAs($this->editor)
            ->put(route('rooms.update', $room), ['name' => 'Baie', 'is_technical' => true, 'floor_id' => $elsewhere->id])
            ->assertSessionHasNoErrors();

        $this->assertSame('Annexe › Rez-de-chaussée › Baie', $room->refresh()->path());

        // The device stays in its room, on its site.
        $this->assertSame([$room->id, $this->site->id], [$device->refresh()->room_id, $device->site_id]);

        // A request that says nothing of the floor, or names the floor the
        // room is on, moves nothing.
        $this->actingAs($this->editor)->put(route('rooms.update', $room), ['name' => 'Baie', 'is_technical' => true])->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->put(route('rooms.update', $room), ['name' => 'Baie', 'is_technical' => true, 'floor_id' => $elsewhere->id])->assertSessionHasNoErrors();

        $this->assertSame($elsewhere->id, $room->refresh()->floor_id);
        $this->assertSame(2, $this->countEntries(ActivityEvent::RoomUpdated));
    }

    public function test_a_room_never_leaves_its_site(): void
    {
        $ground = $this->floor($this->building(), 0, 'Rez-de-chaussée');
        $room = Room::factory()->create(['floor_id' => $ground->id, 'name' => 'Local technique']);

        $workshop = Site::factory()->create(['name' => 'Atelier']);
        $there = $this->floor($this->building('Halle', $workshop), 0, 'Rez-de-chaussée');

        $this->assertRefused(
            $this->actingAs($this->editor)->put(route('rooms.update', $room), ['name' => 'Local technique', 'floor_id' => $there->id]),
            'floor_id',
            "L'étage Halle › Rez-de-chaussée appartient au site Atelier : un lieu ne change jamais de site.",
        );

        $this->assertRefused($this->actingAs($this->editor)->put(route('rooms.update', $room), ['name' => 'Local technique', 'floor_id' => 999999]), 'floor_id');
        $this->assertRefused($this->actingAs($this->editor)->put(route('rooms.update', $room), ['name' => 'Local technique', 'floor_id' => '']), 'floor_id');

        $this->assertSame($ground->id, $room->refresh()->floor_id);
        $this->assertSame(0, $this->countEntries(ActivityEvent::RoomUpdated));
    }

    public function test_a_room_is_not_moved_to_a_floor_that_has_a_room_of_its_name(): void
    {
        $building = $this->building();
        $ground = $this->floor($building, 0, 'Rez-de-chaussée');
        $first = $this->floor($building, 1, '1er étage');

        $room = Room::factory()->create(['floor_id' => $ground->id, 'name' => 'Bureaux']);
        Room::factory()->create(['floor_id' => $first->id, 'name' => 'Bureaux']);

        $this->assertRefused(
            $this->actingAs($this->editor)->put(route('rooms.update', $room), ['name' => 'Bureaux', 'floor_id' => $first->id]),
            'name',
            'Un autre local de cet étage porte déjà ce nom.',
        );

        $this->assertSame($ground->id, $room->refresh()->floor_id);

        // Under another name, it goes.
        $this->actingAs($this->editor)
            ->put(route('rooms.update', $room), ['name' => 'Bureaux nord', 'floor_id' => $first->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($first->id, $room->refresh()->floor_id);
    }

    public function test_a_floor_is_moved_to_another_building_of_its_site_with_its_rooms(): void
    {
        $main = $this->building('Bâtiment principal');
        $annex = $this->building('Annexe');
        $floor = $this->floor($main, 1, '1er étage');
        $this->floor($annex, 0, 'Rez-de-chaussée');

        $room = Room::factory()->create(['floor_id' => $floor->id, 'name' => 'Bureaux']);
        $device = Device::factory()->create(['site_id' => $this->site->id, 'room_id' => $room->id]);

        $this->actingAs($this->editor)
            ->put(route('floors.update', $floor), ['level' => 1, 'name' => '1er étage', 'building_id' => $annex->id])
            ->assertRedirect(route('sites.show', $this->site))
            ->assertSessionHasNoErrors();

        $this->assertSame($annex->id, $floor->refresh()->building_id);
        // Its rooms follow, and the devices kept in them.
        $this->assertSame('Annexe › 1er étage › Bureaux', $room->refresh()->path());
        $this->assertSame($room->id, $device->refresh()->room_id);

        $changes = $this->lastEntry(ActivityEvent::FloorUpdated)->attribute_changes?->toArray() ?? [];

        $this->assertSame(['building' => 'Bâtiment principal'], $changes['old']);
        $this->assertSame(['building' => 'Annexe'], $changes['attributes']);
    }

    public function test_a_floor_never_leaves_its_site_nor_takes_a_level_or_a_name_already_there(): void
    {
        $main = $this->building('Bâtiment principal');
        $annex = $this->building('Annexe');
        $floor = $this->floor($main, 0, 'Rez-de-chaussée');
        $this->floor($annex, 0, 'Accueil');
        $this->floor($annex, 1, 'Rez-de-chaussée');

        $workshop = Site::factory()->create(['name' => 'Atelier']);
        $hall = $this->building('Halle', $workshop);

        $move = fn (array $data) => $this->actingAs($this->editor)->put(route('floors.update', $floor), ['level' => 0, 'name' => 'Rez-de-chaussée', ...$data]);

        $this->assertRefused(
            $move(['building_id' => $hall->id]),
            'building_id',
            'Le bâtiment Halle appartient au site Atelier : un lieu ne change jamais de site.',
        );
        $this->assertRefused($move(['building_id' => 999999]), 'building_id');

        // The annex already has a floor at this level, and one of this name.
        $this->assertRefused($move(['building_id' => $annex->id]), 'level', "L'étage Accueil de ce bâtiment est déjà au niveau 0.");
        $this->assertRefused($move(['building_id' => $annex->id, 'level' => 2]), 'name', 'Un autre étage de ce bâtiment porte déjà ce nom.');

        $this->assertSame([$main->id, 0], [$floor->refresh()->building_id, $floor->level]);

        // At another level and under another name, it goes.
        $move(['building_id' => $annex->id, 'level' => 2, 'name' => '2e étage'])->assertSessionHasNoErrors();

        $this->assertSame([$annex->id, 2, '2e étage'], [$floor->refresh()->building_id, $floor->level, $floor->name]);
    }

    public function test_a_new_floor_or_a_new_room_is_created_where_its_address_says(): void
    {
        $main = $this->building('Bâtiment principal');
        $annex = $this->building('Annexe');
        $ground = $this->floor($main, 0, 'Rez-de-chaussée');
        $other = $this->floor($annex, 0, 'Rez-de-chaussée');

        // What a creation says of another building or floor is not heard.
        $this->actingAs($this->editor)
            ->post(route('floors.store', $main), ['level' => 1, 'name' => '1er étage', 'building_id' => $annex->id])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->editor)
            ->post(route('rooms.store', $ground), ['name' => 'Accueil', 'floor_id' => $other->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($main->id, Floor::query()->where('name', '1er étage')->sole()->building_id);
        $this->assertSame($ground->id, Room::query()->sole()->floor_id);
    }

    public function test_a_reader_cannot_move_a_place(): void
    {
        $building = $this->building();
        $ground = $this->floor($building, 0, 'Rez-de-chaussée');
        $first = $this->floor($building, 1, '1er étage');
        $room = Room::factory()->create(['floor_id' => $ground->id, 'name' => 'Bureaux']);
        $reader = $this->reader();

        $this->actingAs($reader)->put(route('rooms.update', $room), ['name' => 'Bureaux', 'floor_id' => $first->id])->assertForbidden();
        $this->actingAs($reader)
            ->put(route('floors.update', $first), ['level' => 1, 'name' => '1er étage', 'building_id' => $this->building('Annexe')->id])
            ->assertForbidden();

        $this->assertSame($ground->id, $room->refresh()->floor_id);
        $this->assertSame($building->id, $first->refresh()->building_id);
    }

    // --- What a deletion is refused for, level by level -----------------------

    public function test_the_refusal_to_delete_a_room_names_every_kind_of_object_it_holds(): void
    {
        $ground = $this->floor($this->building(), 0, 'Rez-de-chaussée');
        $room = Room::factory()->technical()->create(['floor_id' => $ground->id, 'name' => 'Local technique']);

        // A device of the room, a member of another device kept there, and
        // two patch panels.
        Device::factory()->create(['site_id' => $this->site->id, 'room_id' => $room->id]);
        DeviceMember::factory()->create([
            'device_id' => Device::factory()->create(['site_id' => $this->site->id])->id,
            'room_id' => $room->id,
        ]);
        PatchPanel::factory()->count(2)->create(['room_id' => $room->id]);

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('rooms.destroy', $room)),
            'room',
            "Ce local contient encore 1 équipement, 1 membre d'équipement et 2 panneaux de brassage : videz-le d'abord.",
        );

        $this->assertModelExists($room);
        $this->assertSame(0, $this->countEntries(ActivityEvent::RoomDeleted));
    }

    public function test_a_place_is_only_deleted_once_empty_and_the_refusal_says_what_to_delete_first(): void
    {
        $building = $this->building();
        $ground = $this->floor($building, 0, 'Rez-de-chaussée');
        $this->floor($building, 1, '1er étage');
        $room = Room::factory()->technical()->create(['floor_id' => $ground->id, 'name' => 'Local technique']);
        Room::factory()->create(['floor_id' => $ground->id, 'name' => 'Accueil']);
        $devices = Device::factory()->count(2)->create(['site_id' => $this->site->id, 'room_id' => $room->id]);

        // From the top down, each level is kept by the one below it.
        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('sites.destroy', $this->site)),
            'site',
            "Un bâtiment est encore rattaché à ce site : supprimez-le d'abord.",
        );

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('buildings.destroy', $building)),
            'building',
            "Des étages sont encore rattachés à ce bâtiment (2) : supprimez-les d'abord.",
        );

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('floors.destroy', $ground)),
            'floor',
            "Des locaux sont encore rattachés à cet étage (2) : supprimez-les d'abord.",
        );

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('rooms.destroy', $room)),
            'room',
            "Ce local contient encore 2 équipements : videz-le d'abord.",
        );

        $this->assertSame([1, 2, 2, 2], [Building::query()->count(), Floor::query()->count(), Room::query()->count(), Device::query()->count()]);

        // With one device left, the message is in the singular.
        $devices[0]->delete();

        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('rooms.destroy', $room)),
            'room',
            "Ce local contient encore 1 équipement : videz-le d'abord.",
        );

        // From the bottom up, everything goes.
        $devices[1]->update(['room_id' => null]);

        $this->actingAs($this->editor)->delete(route('rooms.destroy', $room))->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->delete(route('rooms.destroy', Room::query()->sole()))->assertSessionHasNoErrors();

        foreach (Floor::query()->get() as $floor) {
            $this->actingAs($this->editor)->delete(route('floors.destroy', $floor))->assertSessionHasNoErrors();
        }

        $this->actingAs($this->editor)->delete(route('buildings.destroy', $building))->assertSessionHasNoErrors();

        // The site is still kept by the device that belongs to it.
        $this->assertRefused(
            $this->actingAs($this->editor)->delete(route('sites.destroy', $this->site)),
            'site',
            "Un équipement est encore rattaché à ce site : supprimez-le ou changez son site d'abord.",
        );

        $devices[1]->delete();

        $this->actingAs($this->editor)->delete(route('sites.destroy', $this->site))->assertSessionHasNoErrors();

        $this->assertSame([0, 0, 0, 0], [Site::query()->count(), Building::query()->count(), Floor::query()->count(), Room::query()->count()]);
    }

    // --- Rights ---------------------------------------------------------------

    public function test_a_reader_reads_the_places_but_cannot_write_them(): void
    {
        $reader = $this->reader();
        $building = $this->building();
        $floor = $this->floor($building, 0, 'Rez-de-chaussée');
        $room = Room::factory()->create(['floor_id' => $floor->id, 'name' => 'Accueil']);

        $this->actingAs($reader)
            ->get(route('sites.show', $this->site))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('places', 1));

        $this->actingAs($reader)->post(route('buildings.store', $this->site), ['name' => 'Annexe'])->assertForbidden();
        $this->actingAs($reader)->put(route('buildings.update', $building), ['name' => 'Renommé'])->assertForbidden();
        $this->actingAs($reader)->delete(route('buildings.destroy', $building))->assertForbidden();
        $this->actingAs($reader)->post(route('floors.store', $building), ['level' => 1, 'name' => '1er étage'])->assertForbidden();
        $this->actingAs($reader)->put(route('floors.update', $floor), ['level' => 0, 'name' => 'Renommé'])->assertForbidden();
        $this->actingAs($reader)->delete(route('floors.destroy', $floor))->assertForbidden();
        $this->actingAs($reader)->post(route('rooms.store', $floor), ['name' => 'Réserve'])->assertForbidden();
        $this->actingAs($reader)->put(route('rooms.update', $room), ['name' => 'Renommé'])->assertForbidden();
        $this->actingAs($reader)->delete(route('rooms.destroy', $room))->assertForbidden();

        $this->assertSame(['Bâtiment principal'], Building::query()->pluck('name')->all());
        $this->assertSame(['Rez-de-chaussée'], Floor::query()->pluck('name')->all());
        $this->assertSame(['Accueil'], Room::query()->pluck('name')->all());
    }

    public function test_an_administrator_writes_places_like_an_editor_and_a_guest_is_sent_to_the_login_page(): void
    {
        $this->post(route('buildings.store', $this->site), ['name' => 'Annexe'])->assertRedirect(route('login'));

        $this->actingAs($this->admin())
            ->post(route('buildings.store', $this->site), ['name' => 'Annexe'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('buildings', ['name' => 'Annexe']);
    }

    // --- Activity log ---------------------------------------------------------

    public function test_the_life_of_each_place_is_logged(): void
    {
        $changes = fn (ActivityEvent $event): array => $this->lastEntry($event)->attribute_changes?->toArray() ?? [];

        $this->actingAs($this->editor)->post(route('buildings.store', $this->site), ['name' => 'Bâtiment principal', 'code' => 'a']);
        $building = Building::query()->sole();

        $created = $this->lastEntry(ActivityEvent::BuildingCreated);

        $this->assertTrue($this->editor->is($created->causer));
        $this->assertTrue($building->is($created->subject));
        $this->assertSame(['site' => 'Siège', 'name' => 'Bâtiment principal', 'code' => 'A', 'description' => null], $changes(ActivityEvent::BuildingCreated)['attributes']);
        $this->assertSame('Bâtiment principal (Siège)', $created->getProperty('label'));

        $this->actingAs($this->editor)->put(route('buildings.update', $building), ['name' => 'Bâtiment A', 'code' => 'A']);

        $this->assertSame(['name' => 'Bâtiment principal'], $changes(ActivityEvent::BuildingUpdated)['old']);
        $this->assertSame(['name' => 'Bâtiment A'], $changes(ActivityEvent::BuildingUpdated)['attributes']);

        $this->actingAs($this->editor)->post(route('floors.store', $building), ['level' => 0, 'name' => 'Rez-de-chaussée']);
        $floor = Floor::query()->sole();

        $this->assertSame(['building' => 'Bâtiment A', 'level' => 0, 'name' => 'Rez-de-chaussée', 'description' => null], $changes(ActivityEvent::FloorCreated)['attributes']);
        $this->assertSame('Bâtiment A › Rez-de-chaussée (Siège)', $this->lastEntry(ActivityEvent::FloorCreated)->getProperty('label'));

        $this->actingAs($this->editor)->put(route('floors.update', $floor), ['level' => 1, 'name' => 'Rez-de-chaussée']);

        $this->assertSame(['level' => 0], $changes(ActivityEvent::FloorUpdated)['old']);
        $this->assertSame(['level' => 1], $changes(ActivityEvent::FloorUpdated)['attributes']);

        $this->actingAs($this->editor)->post(route('rooms.store', $floor), ['name' => 'Local technique', 'is_technical' => true]);
        $room = Room::query()->sole();

        $this->assertSame(
            ['floor' => 'Bâtiment A › Rez-de-chaussée', 'name' => 'Local technique', 'is_technical' => true, 'description' => null],
            $changes(ActivityEvent::RoomCreated)['attributes'],
        );
        $this->assertSame('Bâtiment A › Rez-de-chaussée › Local technique (Siège)', $this->lastEntry(ActivityEvent::RoomCreated)->getProperty('label'));

        $this->actingAs($this->editor)->put(route('rooms.update', $room), ['name' => 'Local technique', 'is_technical' => false]);

        $this->assertSame(['is_technical' => true], $changes(ActivityEvent::RoomUpdated)['old']);
        $this->assertSame(['is_technical' => false], $changes(ActivityEvent::RoomUpdated)['attributes']);

        // An update that changes nothing, and a refused one, write nothing.
        $this->actingAs($this->editor)->put(route('rooms.update', $room), ['name' => 'Local technique', 'is_technical' => false]);
        $this->actingAs($this->editor)->delete(route('floors.destroy', $floor));

        $this->assertSame(1, $this->countEntries(ActivityEvent::RoomUpdated));
        $this->assertSame(0, $this->countEntries(ActivityEvent::FloorDeleted));

        $this->actingAs($this->editor)->delete(route('rooms.destroy', $room));
        $this->actingAs($this->editor)->delete(route('floors.destroy', $floor));
        $this->actingAs($this->editor)->delete(route('buildings.destroy', $building));

        foreach ([ActivityEvent::RoomDeleted, ActivityEvent::FloorDeleted, ActivityEvent::BuildingDeleted] as $event) {
            $this->assertSame(1, $this->countEntries($event), $event->value);
            // The place is gone, its name stays in the log.
            $this->assertNull($this->lastEntry($event)->subject);
        }

        $this->assertSame('Bâtiment A › Rez-de-chaussée › Local technique (Siège)', $this->lastEntry(ActivityEvent::RoomDeleted)->getProperty('label'));
        $this->assertSame(['name' => 'Local technique', 'floor' => 'Bâtiment A › Rez-de-chaussée', 'is_technical' => false, 'description' => null], [
            'name' => $changes(ActivityEvent::RoomDeleted)['old']['name'],
            'floor' => $changes(ActivityEvent::RoomDeleted)['old']['floor'],
            'is_technical' => $changes(ActivityEvent::RoomDeleted)['old']['is_technical'],
            'description' => $changes(ActivityEvent::RoomDeleted)['old']['description'],
        ]);
    }

    public function test_the_log_page_reads_the_places_in_french(): void
    {
        $floor = $this->floor($this->building(), 0, 'Rez-de-chaussée');

        $this->actingAs($this->editor)->post(route('rooms.store', $floor), ['name' => 'Local technique', 'is_technical' => true]);

        $this->actingAs($this->admin())
            ->get(route('activity-log.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.event_label', 'Local créé')
                ->where('entries.data.0.subject.title', 'Bâtiment principal › Rez-de-chaussée › Local technique (Siège)')
                ->where('entries.data.0.changes', [
                    ['field' => 'Étage', 'old' => null, 'new' => 'Bâtiment principal › Rez-de-chaussée'],
                    ['field' => 'Nom', 'old' => null, 'new' => 'Local technique'],
                    ['field' => 'Local technique', 'old' => null, 'new' => 'Oui'],
                ]));
    }
}
