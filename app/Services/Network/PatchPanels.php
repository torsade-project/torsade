<?php

// torsade/app/Services/Network/PatchPanels.php

namespace App\Services\Network;

use App\Enums\ActivityEvent;
use App\Models\PatchPanel;
use App\Models\PatchPanelPort;
use App\Models\Room;
use App\Services\Network\Concerns\PhrasesMessages;
use App\Services\Network\Concerns\TranslatesUniqueViolations;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change to the patch panels goes through this class, whether it
 * comes from the interface or from a command: the rules are enforced here
 * and each change is written to the activity log.
 *
 * The ports of a panel are numbered from 1 to its number of ports and are
 * made with it: they are not told one by one in the activity log, but by
 * that number, in the entry of the panel, when the panel is created, when
 * the number changes and when the panel is deleted.
 *
 * A port that has a jack or a cord is not taken away, and the panel that
 * holds it is not deleted: the jack is detached, and the cord removed,
 * first.
 *
 * @phpstan-type PanelData array{name: string, port_count: int, connector: string, category?: string|null, status: string, description?: string|null}
 */
final class PatchPanels
{
    use PhrasesMessages;
    use TranslatesUniqueViolations;

    /**
     * How many ports a refusal names at most: the others are counted.
     */
    public const int NAMED = 10;

    public function __construct(
        private readonly ChangeLog $log,
    ) {}

    /**
     * A panel kept in a room, with its ports.
     *
     * @param  PanelData  $data
     *
     * @throws ValidationException Messages are keyed by field.
     */
    public function create(Room $room, array $data, Actor $actor): PatchPanel
    {
        return DB::transaction(function () use ($room, $data, $actor): PatchPanel {
            $room->loadMissing('floor.building.site');

            $panel = new PatchPanel($this->values($data));
            $panel->room()->associate($room);

            $this->save($panel);
            $this->addPorts($panel, 1, $panel->port_count);

            $this->log->created(ActivityEvent::PatchPanelCreated, $panel, $actor);

            return $panel;
        });
    }

    /**
     * Change a panel. More ports adds them after the last one; fewer takes
     * the last ones away, unless one of them has a jack or a cord.
     *
     * @param  PanelData  $data
     *
     * @throws ValidationException Messages are keyed by field.
     */
    public function update(PatchPanel $panel, array $data, Actor $actor): void
    {
        DB::transaction(function () use ($panel, $data, $actor): void {
            $panel = PatchPanel::query()->with('room.floor.building.site')->whereKey($panel->getKey())->lockForUpdate()->firstOrFail();

            $before = $panel->auditValues();
            $count = $data['port_count'];

            if ($count < $panel->port_count) {
                $busy = $this->busy($panel, $count);

                if ($busy !== []) {
                    throw ValidationException::withMessages([
                        'port_count' => trans_choice(
                            'The port :ports has a jack or a cord: it is not taken away. Detach the jack and remove the cord first, or keep more ports.|The ports :ports have a jack or a cord: they are not taken away. Detach the jacks and remove the cords first, or keep more ports.',
                            count($busy),
                            ['ports' => $this->numbers($busy)],
                        ),
                    ]);
                }

                $panel->ports()->where('number', '>', $count)->delete();
            } elseif ($count > $panel->port_count) {
                $this->addPorts($panel, $panel->port_count + 1, $count);
            }

            $panel->fill($this->values($data));

            $this->save($panel);

            $this->log->updated(ActivityEvent::PatchPanelUpdated, $panel, $before, $actor);
        });
    }

    /**
     * Delete a panel with its ports, once none of them has a jack or a
     * cord.
     *
     * @throws ValidationException
     */
    public function delete(PatchPanel $panel, Actor $actor): void
    {
        DB::transaction(function () use ($panel, $actor): void {
            $panel = PatchPanel::query()->with('room.floor.building.site')->whereKey($panel->getKey())->lockForUpdate()->firstOrFail();

            $busy = $this->busy($panel, 0);

            if ($busy !== []) {
                throw ValidationException::withMessages([
                    'panel' => trans_choice(
                        'The port :ports of this panel has a jack or a cord: detach the jack and remove the cord first.|The ports :ports of this panel have a jack or a cord: detach the jacks and remove the cords first.',
                        count($busy),
                        ['ports' => $this->numbers($busy)],
                    ),
                ]);
            }

            // One entry for the panel and its ports, told by their number.
            $this->log->deleted(ActivityEvent::PatchPanelDeleted, $panel, $actor);

            // Its ports go with it.
            $panel->delete();
        });
    }

    /**
     * The numbers of the ports numbered after a given number that have a
     * jack or a cord, kept from other changes until the end of the
     * transaction.
     *
     * @return list<int>
     */
    private function busy(PatchPanel $panel, int $after): array
    {
        /** @var list<int> $busy */
        $busy = $panel->ports()
            ->where('number', '>', $after)
            ->where(fn ($query) => $query->has('jack')->orHas('cord'))
            ->lockForUpdate()
            ->pluck('number')
            ->all();

        return $busy;
    }

    /**
     * Numbers of ports in a sentence: ten of them at most, then how many
     * are left out.
     *
     * @param  list<int>  $numbers
     */
    private function numbers(array $numbers): string
    {
        return $this->enumerate(array_map(strval(...), $numbers), self::NAMED);
    }

    /**
     * Ports numbered from one number to another, after those the panel has.
     */
    private function addPorts(PatchPanel $panel, int $from, int $to): void
    {
        $now = now();

        PatchPanelPort::query()->insert(array_map(
            fn (int $number): array => ['patch_panel_id' => $panel->id, 'number' => $number, 'created_at' => $now, 'updated_at' => $now],
            range($from, $to),
        ));
    }

    /**
     * The name is unique within the room: the database refuses a second
     * one, whatever its case.
     *
     * @throws ValidationException
     */
    private function save(PatchPanel $panel): void
    {
        $this->guardingUniqueness(fn (): bool => $panel->save(), [
            'patch_panels_room_id_name_unique' => ['name', __('The room already has a patch panel named :name.', ['name' => $panel->name])],
        ]);
    }

    /**
     * @param  PanelData  $data
     * @return array{name: string, port_count: int, connector: string, category: string|null, status: string, description: string|null}
     */
    private function values(array $data): array
    {
        return [
            'name' => trim($data['name']),
            'port_count' => $data['port_count'],
            'connector' => $data['connector'],
            'category' => $this->blankToNull($data['category'] ?? null),
            'status' => $data['status'],
            'description' => $this->blankToNull($data['description'] ?? null),
        ];
    }

    private function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
