<?php

// torsade/app/Services/Network/Jacks.php

namespace App\Services\Network;

use App\Enums\ActivityEvent;
use App\Models\Floor;
use App\Models\Jack;
use App\Models\PatchPanel;
use App\Models\PatchPanelPort;
use App\Services\Network\Concerns\PhrasesMessages;
use App\Services\Network\Concerns\TranslatesUniqueViolations;
use App\Support\Actor;
use App\Support\JackLabels;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change to the wall jacks goes through this class, whether it comes
 * from the interface or from a command: the rules are enforced here and each
 * change is written to the activity log.
 *
 * A jack is on a floor, with a label unique within the floor. Its horizontal
 * cable ends on a port of a patch panel of the same site, which has one
 * jack at most. Jacks created as a series are told in one entry, on their
 * floor, each with its label and its port of a panel.
 *
 * @phpstan-type JackData array{label: string, location?: string|null, patch_panel_port_id?: int|null, status: string, description?: string|null}
 * @phpstan-type SeriesData array{prefix?: string|null, suffix?: string|null, first_number: int, last_number: int, digits: int, patch_panel_id?: int|null, first_port?: int|null, status: string}
 */
final class Jacks
{
    use PhrasesMessages;
    use TranslatesUniqueViolations;

    /**
     * How many labels or ports a refusal names at most: the others are
     * counted.
     */
    public const int NAMED = 10;

    public function __construct(
        private readonly ChangeLog $log,
        private readonly JackCords $cords,
    ) {}

    /**
     * One jack on a floor.
     *
     * @param  JackData  $data
     *
     * @throws ValidationException Messages are keyed by field.
     */
    public function create(Floor $floor, array $data, Actor $actor): Jack
    {
        return DB::transaction(function () use ($floor, $data, $actor): Jack {
            $floor->loadMissing('building.site');

            $jack = new Jack;
            $jack->floor()->associate($floor);

            $this->write($jack, $data);

            $this->log->created(ActivityEvent::JackCreated, $jack, $actor);

            return $jack;
        });
    }

    /**
     * Change a jack: its label, where it is, its port of a panel, its
     * status and its description. It stays on its floor.
     *
     * @param  JackData  $data
     *
     * @throws ValidationException Messages are keyed by field.
     */
    public function update(Jack $jack, array $data, Actor $actor): void
    {
        DB::transaction(function () use ($jack, $data, $actor): void {
            $jack = Jack::query()->with(['floor.building.site', 'panelPort.panel.room.floor.building'])->whereKey($jack->getKey())->lockForUpdate()->firstOrFail();

            $before = $jack->auditValues();

            $this->write($jack, $data);

            $this->log->updated(ActivityEvent::JackUpdated, $jack, $before, $actor);
        });
    }

    /**
     * Delete a jack, once no address and no device are plugged into it.
     * Its port of a panel stays, free.
     *
     * @throws ValidationException
     */
    public function delete(Jack $jack, Actor $actor): void
    {
        DB::transaction(function () use ($jack, $actor): void {
            $jack = Jack::query()->with(['floor.building.site', 'panelPort.panel.room.floor.building'])->whereKey($jack->getKey())->lockForUpdate()->firstOrFail();

            $addresses = $jack->ipAddresses()->lockForUpdate()->count();

            if ($addresses > 0) {
                throw ValidationException::withMessages([
                    'jack' => trans_choice(
                        'An address is still plugged into this jack: unplug it first.|:count addresses are still plugged into this jack: unplug them first.',
                        $addresses,
                    ),
                ]);
            }

            $this->cords->refuseJackDeletion($jack, 'jack');

            $this->log->deleted(ActivityEvent::JackDeleted, $jack, $actor);

            $jack->delete();
        });
    }

    /**
     * Jacks labelled from a first number to a last one on a floor and,
     * when a panel is given, cabled to its ports in order from a first one:
     * all of them, or none.
     *
     * @param  SeriesData  $data
     * @return int How many jacks were created.
     *
     * @throws ValidationException Messages are keyed by field.
     */
    public function createSeries(Floor $floor, array $data, Actor $actor): int
    {
        return DB::transaction(function () use ($floor, $data, $actor): int {
            $floor->loadMissing('building.site');

            $quantity = $data['last_number'] - $data['first_number'] + 1;

            if ($quantity > JackLabels::MAX_QUANTITY) {
                throw ValidationException::withMessages([
                    'last_number' => trans_choice('A series holds :count jack at most.|A series holds :count jacks at most.', JackLabels::MAX_QUANTITY),
                ]);
            }

            $labels = JackLabels::labels(
                $data['prefix'] ?? '',
                $data['suffix'] ?? '',
                $data['first_number'],
                $data['last_number'],
                $data['digits'],
            );

            // The last label bears the highest number: it is the longest.
            if (mb_strlen($labels[count($labels) - 1]) > Jack::MAX_LABEL_LENGTH) {
                throw ValidationException::withMessages([
                    'prefix' => trans_choice(
                        'The labels of this series are longer than :count character.|The labels of this series are longer than :count characters.',
                        Jack::MAX_LABEL_LENGTH,
                    ),
                ]);
            }

            /** @var list<string> $taken */
            $taken = $floor->jacks()->whereIn('label', $labels)->pluck('label')->all();

            if ($taken !== []) {
                natcasesort($taken);

                throw ValidationException::withMessages([
                    'prefix' => trans_choice(
                        'The floor already has a jack labelled :labels.|The floor already has jacks labelled :labels.',
                        count($taken),
                        ['labels' => $this->enumerate(array_values($taken), self::NAMED)],
                    ),
                ]);
            }

            $ports = $this->seriesPorts($floor, $data, count($labels));

            $told = [];

            foreach ($labels as $index => $label) {
                $port = $ports[$index] ?? null;

                $jack = new Jack([
                    'label' => $label,
                    'patch_panel_port_id' => $port?->id,
                    'status' => $data['status'],
                ]);
                $jack->floor()->associate($floor);

                $this->guardingUniqueness(fn (): bool => $jack->save(), [
                    'jacks_floor_id_label_unique' => ['prefix', __('The floor already has a jack labelled :label.', ['label' => $label])],
                    'jacks_patch_panel_port_id_unique' => ['first_port', __('A port of the panel was given a jack in the meantime: reload the page.')],
                ]);

                $told[] = $port === null ? $label : $label.' ('.$port->name().')';
            }

            // One entry for the whole series, on its floor.
            $this->log->noted(ActivityEvent::FloorJacksAdded, $floor, [], ['jacks_count' => count($told), 'jacks' => implode(', ', $told)], $actor);

            return count($told);
        });
    }

    /**
     * Cable a jack of the floor of the panel to one of its ports, from the
     * page of the panel.
     *
     * @throws ValidationException
     */
    public function attach(PatchPanelPort $port, int $jackId, Actor $actor): void
    {
        DB::transaction(function () use ($port, $jackId, $actor): void {
            $jack = Jack::query()->with(['floor.building.site', 'panelPort.panel.room.floor.building'])->whereKey($jackId)->lockForUpdate()->first();

            if ($jack === null) {
                throw ValidationException::withMessages(['jack_id' => __('This jack no longer exists.')]);
            }

            if ($jack->panelPort !== null) {
                throw ValidationException::withMessages([
                    'jack_id' => __('The jack :jack is already cabled to the port :port: detach it from there first.', [
                        'jack' => $jack->label,
                        'port' => $jack->panelPort->name(),
                    ]),
                ]);
            }

            $before = $jack->auditValues();

            $this->cable($jack, $port->id, 'jack_id');

            $this->log->updated(ActivityEvent::JackUpdated, $jack, $before, $actor);
        });
    }

    /**
     * Detach the jack of a port of a panel: the jack stays on its floor,
     * the port is free.
     *
     * @throws ValidationException
     */
    public function detach(PatchPanelPort $port, Actor $actor): void
    {
        DB::transaction(function () use ($port, $actor): void {
            $jack = Jack::query()->with(['floor.building.site', 'panelPort.panel.room.floor.building'])->where('patch_panel_port_id', $port->id)->lockForUpdate()->first();

            if ($jack === null) {
                throw ValidationException::withMessages(['jack' => __('This port of the panel has no jack.')]);
            }

            $before = $jack->auditValues();

            $jack->panelPort()->dissociate();
            $jack->save();

            $this->log->updated(ActivityEvent::JackUpdated, $jack, $before, $actor);
        });
    }

    /**
     * Check what depends on stored data, then save: the label is free on
     * the floor, and the port of a panel is of the same site and free.
     *
     * @param  JackData  $data
     *
     * @throws ValidationException
     */
    private function write(Jack $jack, array $data): void
    {
        $label = trim($data['label']);

        // The collation of the column does not tell capitals from small
        // letters: the refusal names the jack as it is written.
        $other = Jack::query()
            ->where('floor_id', $jack->floor_id)
            ->where('label', $label)
            ->when($jack->exists, fn ($query) => $query->whereKeyNot($jack->getKey()))
            ->first();

        if ($other !== null) {
            throw ValidationException::withMessages([
                'label' => __('The floor already has a jack labelled :label.', ['label' => $other->label]),
            ]);
        }

        $jack->fill([
            'label' => $label,
            'location' => $this->blankToNull($data['location'] ?? null),
            'status' => $data['status'],
            'description' => $this->blankToNull($data['description'] ?? null),
        ]);

        $this->cable($jack, $data['patch_panel_port_id'] ?? null, 'patch_panel_port_id');
    }

    /**
     * Give a jack its port of a panel, or none, and save it.
     *
     * @throws ValidationException
     */
    private function cable(Jack $jack, ?int $portId, string $field): void
    {
        if ($portId !== null && $portId !== $jack->patch_panel_port_id) {
            $port = PatchPanelPort::query()->with(['panel.room.floor.building.site', 'jack'])->whereKey($portId)->lockForUpdate()->first();

            if ($port === null) {
                throw ValidationException::withMessages([$field => __('This port of a panel no longer exists.')]);
            }

            if ($port->panel->siteId() !== $jack->siteId()) {
                throw ValidationException::withMessages([
                    $field => __('The panel :panel is on the site :site: choose a panel of the site of the jack.', [
                        'panel' => $port->panel->name,
                        'site' => $port->panel->room->floor->building->site->name,
                    ]),
                ]);
            }

            if ($port->jack !== null && ! $port->jack->is($jack)) {
                throw ValidationException::withMessages([
                    $field => __('The port :port already has the jack :jack.', ['port' => $port->name(), 'jack' => $port->jack->label]),
                ]);
            }

            $jack->panelPort()->associate($port);
        } elseif ($portId === null) {
            $jack->panelPort()->dissociate();
        }

        $this->guardingUniqueness(fn (): bool => $jack->save(), [
            'jacks_floor_id_label_unique' => ['label', __('The floor already has a jack labelled :label.', ['label' => $jack->label])],
            'jacks_patch_panel_port_id_unique' => [$field, __('This port of the panel was given a jack in the meantime: reload the page.')],
        ]);
    }

    /**
     * The ports of a panel a series is cabled to, in order from its first
     * port; none when no panel is given.
     *
     * @param  SeriesData  $data
     * @return list<PatchPanelPort>
     *
     * @throws ValidationException
     */
    private function seriesPorts(Floor $floor, array $data, int $quantity): array
    {
        $panelId = $data['patch_panel_id'] ?? null;

        if ($panelId === null) {
            return [];
        }

        $panel = PatchPanel::query()->with('room.floor.building.site')->find($panelId);

        if ($panel === null) {
            throw ValidationException::withMessages(['patch_panel_id' => __('This patch panel no longer exists.')]);
        }

        if ($panel->siteId() !== $floor->building->site_id) {
            throw ValidationException::withMessages([
                'patch_panel_id' => __('The panel :panel is on the site :site: choose a panel of the site of the jack.', [
                    'panel' => $panel->name,
                    'site' => $panel->room->floor->building->site->name,
                ]),
            ]);
        }

        $first = $data['first_port'] ?? 1;
        $last = $first + $quantity - 1;

        if ($last > $panel->port_count) {
            throw ValidationException::withMessages([
                'first_port' => __('The series would end on the port :last of :panel, past its last port, the :max.', [
                    'last' => $last,
                    'panel' => $panel->name,
                    'max' => $panel->port_count,
                ]),
            ]);
        }

        $ports = $panel->ports()
            ->whereBetween('number', [$first, $last])
            ->with('jack')
            ->lockForUpdate()
            ->get();

        /** @var list<int> $occupied */
        $occupied = array_values($ports->filter(fn (PatchPanelPort $port): bool => $port->jack !== null)->map(fn (PatchPanelPort $port): int => $port->number)->all());

        if ($occupied !== []) {
            throw ValidationException::withMessages([
                'first_port' => trans_choice(
                    'The port :ports of :panel already has a jack.|The ports :ports of :panel already have a jack.',
                    count($occupied),
                    ['ports' => $this->enumerate(array_map(strval(...), $occupied), self::NAMED), 'panel' => $panel->name],
                ),
            ]);
        }

        foreach ($ports as $port) {
            $port->setRelation('panel', $panel);
        }

        return array_values($ports->all());
    }

    private function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
