<?php

// torsade/app/Services/Network/Places.php

namespace App\Services\Network;

use App\Enums\ActivityEvent;
use App\Models\Building;
use App\Models\DeviceMember;
use App\Models\Floor;
use App\Models\Room;
use App\Models\Site;
use App\Services\Network\Concerns\PhrasesMessages;
use App\Services\Network\Concerns\TranslatesUniqueViolations;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change to a building, a floor or a room goes through this class,
 * whether it comes from the interface or from a command: the rules are
 * enforced here and each change is written to the activity log.
 *
 * A place never changes site: that would move the devices kept in its
 * rooms to another site behind their back. Within its site, a room can be
 * moved to another floor and a floor to another building, to put right
 * what was typed in the wrong place.
 *
 * @phpstan-type BuildingData array{name: string, code?: string|null, description?: string|null}
 * @phpstan-type FloorData array{level: int, name: string, description?: string|null, building_id?: int|null}
 * @phpstan-type RoomData array{name: string, is_technical?: bool, description?: string|null, floor_id?: int|null}
 */
final class Places
{
    use PhrasesMessages;
    use TranslatesUniqueViolations;

    public function __construct(private readonly ChangeLog $log) {}

    /**
     * @param  BuildingData  $data
     *
     * @throws ValidationException
     */
    public function createBuilding(Site $site, array $data, Actor $actor): Building
    {
        return DB::transaction(function () use ($site, $data, $actor): Building {
            $building = new Building($this->buildingAttributes($data));
            $building->site()->associate($site);

            $this->saveBuilding($building);

            $this->log->created(ActivityEvent::BuildingCreated, $building, $actor);

            return $building;
        });
    }

    /**
     * @param  BuildingData  $data
     *
     * @throws ValidationException
     */
    public function updateBuilding(Building $building, array $data, Actor $actor): void
    {
        DB::transaction(function () use ($building, $data, $actor): void {
            $before = $building->auditValues();

            $building->fill($this->buildingAttributes($data));

            $this->saveBuilding($building);

            $this->log->updated(ActivityEvent::BuildingUpdated, $building, $before, $actor);
        });
    }

    /**
     * A building is only deleted once empty: nothing disappears with it.
     *
     * @throws ValidationException
     */
    public function deleteBuilding(Building $building, Actor $actor): void
    {
        DB::transaction(function () use ($building, $actor): void {
            $floors = $building->floors()->lockForUpdate()->count();

            if ($floors > 0) {
                throw ValidationException::withMessages([
                    'building' => trans_choice(
                        'A floor still belongs to this building: delete it first.|:count floors still belong to this building: delete them first.',
                        $floors,
                    ),
                ]);
            }

            $this->log->deleted(ActivityEvent::BuildingDeleted, $building, $actor);

            $building->delete();
        });
    }

    /**
     * @param  FloorData  $data
     *
     * @throws ValidationException
     */
    public function createFloor(Building $building, array $data, Actor $actor): Floor
    {
        return DB::transaction(function () use ($building, $data, $actor): Floor {
            $floor = new Floor($this->floorAttributes($data));
            $floor->building()->associate($building);

            $this->saveFloor($floor);

            $this->log->created(ActivityEvent::FloorCreated, $floor, $actor);

            return $floor;
        });
    }

    /**
     * Change a floor and, when another building of its site is given, move
     * it there with its rooms.
     *
     * @param  FloorData  $data
     *
     * @throws ValidationException
     */
    public function updateFloor(Floor $floor, array $data, Actor $actor): void
    {
        DB::transaction(function () use ($floor, $data, $actor): void {
            $before = $floor->auditValues();

            $floor->fill($this->floorAttributes($data));

            $this->moveFloor($floor, $data['building_id'] ?? null);
            $this->saveFloor($floor);

            $this->log->updated(ActivityEvent::FloorUpdated, $floor, $before, $actor);
        });
    }

    /**
     * @throws ValidationException
     */
    public function deleteFloor(Floor $floor, Actor $actor): void
    {
        DB::transaction(function () use ($floor, $actor): void {
            $rooms = $floor->rooms()->lockForUpdate()->count();

            if ($rooms > 0) {
                throw ValidationException::withMessages([
                    'floor' => trans_choice(
                        'A room still belongs to this floor: delete it first.|:count rooms still belong to this floor: delete them first.',
                        $rooms,
                    ),
                ]);
            }

            $jacks = $floor->jacks()->lockForUpdate()->count();

            if ($jacks > 0) {
                throw ValidationException::withMessages([
                    'floor' => trans_choice(
                        'A jack is still on this floor: delete it first.|:count jacks are still on this floor: delete them first.',
                        $jacks,
                    ),
                ]);
            }

            $this->log->deleted(ActivityEvent::FloorDeleted, $floor, $actor);

            $floor->delete();
        });
    }

    /**
     * @param  RoomData  $data
     *
     * @throws ValidationException
     */
    public function createRoom(Floor $floor, array $data, Actor $actor): Room
    {
        return DB::transaction(function () use ($floor, $data, $actor): Room {
            $room = new Room($this->roomAttributes($data));
            $room->floor()->associate($floor);

            $this->saveRoom($room);

            $this->log->created(ActivityEvent::RoomCreated, $room, $actor);

            return $room;
        });
    }

    /**
     * Change a room and, when another floor of its site is given, move it
     * there. The devices kept in it stay in it.
     *
     * @param  RoomData  $data
     *
     * @throws ValidationException
     */
    public function updateRoom(Room $room, array $data, Actor $actor): void
    {
        DB::transaction(function () use ($room, $data, $actor): void {
            $before = $room->auditValues();

            $room->fill($this->roomAttributes($data));

            $this->moveRoom($room, $data['floor_id'] ?? null);
            $this->saveRoom($room);

            $this->log->updated(ActivityEvent::RoomUpdated, $room, $before, $actor);
        });
    }

    /**
     * A room is only deleted once no device is kept in it: a device never
     * loses its place without anyone deciding so.
     *
     * @throws ValidationException
     */
    public function deleteRoom(Room $room, Actor $actor): void
    {
        DB::transaction(function () use ($room, $actor): void {
            $devices = $room->devices()->lockForUpdate()->count();
            // A member kept apart from its device, in this room.
            $members = DeviceMember::query()->where('room_id', $room->id)->lockForUpdate()->count();
            $panels = $room->patchPanels()->lockForUpdate()->count();

            // Every kind of object that is in the way is named at once.
            $kept = array_values(array_filter([
                $devices > 0 ? trans_choice(':count device|:count devices', $devices) : null,
                $members > 0 ? trans_choice(':count member of a device|:count members of devices', $members) : null,
                $panels > 0 ? trans_choice(':count patch panel|:count patch panels', $panels) : null,
            ]));

            if ($kept !== []) {
                throw ValidationException::withMessages([
                    'room' => __('This room still holds :objects: empty it first.', ['objects' => $this->listed($kept)]),
                ]);
            }

            $this->log->deleted(ActivityEvent::RoomDeleted, $room, $actor);

            $room->delete();
        });
    }

    /**
     * @param  BuildingData  $data
     * @return array<string, mixed>
     */
    private function buildingAttributes(array $data): array
    {
        $code = trim((string) ($data['code'] ?? ''));

        return [
            'name' => $data['name'],
            // A code is written in capitals wherever it is used.
            'code' => $code === '' ? null : strtoupper($code),
            'description' => $data['description'] ?? null,
        ];
    }

    /**
     * @param  FloorData  $data
     * @return array<string, mixed>
     */
    private function floorAttributes(array $data): array
    {
        return [
            'level' => $data['level'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ];
    }

    /**
     * @param  RoomData  $data
     * @return array<string, mixed>
     */
    private function roomAttributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'is_technical' => $data['is_technical'] ?? false,
            'description' => $data['description'] ?? null,
        ];
    }

    /**
     * Put a floor in another building of the same site, if one is asked
     * for.
     *
     * @throws ValidationException
     */
    private function moveFloor(Floor $floor, ?int $buildingId): void
    {
        if ($buildingId === null || $buildingId === $floor->building_id) {
            return;
        }

        $target = Building::query()->with('site')->find($buildingId);

        if ($target === null) {
            throw ValidationException::withMessages(['building_id' => __('This building no longer exists.')]);
        }

        if ($target->site_id !== $floor->building->site_id) {
            throw ValidationException::withMessages([
                'building_id' => __('The building :building belongs to the site :site: a place never changes site.', [
                    'building' => $target->name,
                    'site' => $target->site->name,
                ]),
            ]);
        }

        $floor->building()->associate($target);
    }

    /**
     * Put a room on another floor of the same site, if one is asked for.
     *
     * @throws ValidationException
     */
    private function moveRoom(Room $room, ?int $floorId): void
    {
        if ($floorId === null || $floorId === $room->floor_id) {
            return;
        }

        $target = Floor::query()->with('building.site')->find($floorId);

        if ($target === null) {
            throw ValidationException::withMessages(['floor_id' => __('This floor no longer exists.')]);
        }

        if ($target->building->site_id !== $room->siteId()) {
            throw ValidationException::withMessages([
                'floor_id' => __('The floor :floor belongs to the site :site: a place never changes site.', [
                    'floor' => $target->path(),
                    'site' => $target->building->site->name,
                ]),
            ]);
        }

        $room->floor()->associate($target);
    }

    /**
     * @throws ValidationException
     */
    private function saveBuilding(Building $building): void
    {
        $this->guardingUniqueness(fn (): bool => $building->save(), [
            'buildings_site_id_name_unique' => ['name', __('Another building of this site already has this name.')],
            'buildings_site_id_code_unique' => ['code', __('Another building of this site already has this code.')],
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function saveFloor(Floor $floor): void
    {
        // The level orders the floors: the refusal names the floor that is
        // already there.
        $other = Floor::query()
            ->where('building_id', $floor->building_id)
            ->where('level', $floor->level)
            ->whereKeyNot($floor->getKey())
            ->first();

        if ($other !== null) {
            throw ValidationException::withMessages([
                'level' => __('The floor :floor of this building already has the level :level.', ['floor' => $other->name, 'level' => $floor->level]),
            ]);
        }

        $this->guardingUniqueness(fn (): bool => $floor->save(), [
            'floors_building_id_level_unique' => ['level', __('Another floor of this building already has this level.')],
            'floors_building_id_name_unique' => ['name', __('Another floor of this building already has this name.')],
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function saveRoom(Room $room): void
    {
        $this->guardingUniqueness(fn (): bool => $room->save(), [
            'rooms_floor_id_name_unique' => ['name', __('Another room of this floor already has this name.')],
        ]);
    }
}
