<?php

// torsade/app/Services/Network/CatalogVlans.php

namespace App\Services\Network;

use App\Enums\ActivityEvent;
use App\Exceptions\CatalogVlanTaken;
use App\Models\CatalogVlan;
use App\Models\Vlan;
use App\Services\Network\Concerns\TranslatesUniqueViolations;
use App\Support\Actor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change to the VLAN catalogue goes through this class, whether it
 * comes from the interface or from a command: the rules are enforced here
 * and each change is written to the activity log.
 *
 * Changing an entry never changes the VLANs attached to it: what then
 * differs becomes a deviation, shown where the VLAN is.
 *
 * @phpstan-type EntryData array{vid: int, name: string, description?: string|null}
 */
final class CatalogVlans
{
    use TranslatesUniqueViolations;

    public function __construct(private readonly ChangeLog $log) {}

    /**
     * @param  EntryData  $data
     *
     * @throws ValidationException
     */
    public function create(array $data, Actor $actor): CatalogVlan
    {
        return DB::transaction(function () use ($data, $actor): CatalogVlan {
            $this->assertVid($data['vid']);

            $entry = new CatalogVlan($this->attributes($data));

            $this->save($entry);

            $this->log->created(ActivityEvent::CatalogVlanCreated, $entry, $actor);

            return $entry;
        });
    }

    /**
     * @param  EntryData  $data
     *
     * @throws ValidationException
     */
    public function update(CatalogVlan $entry, array $data, Actor $actor): void
    {
        DB::transaction(function () use ($entry, $data, $actor): void {
            $this->assertVid($data['vid']);

            $before = $entry->auditValues();

            $entry->fill($this->attributes($data));

            $this->save($entry);

            $this->log->updated(ActivityEvent::CatalogVlanUpdated, $entry, $before, $actor);
        });
    }

    /**
     * An entry is only deleted once no VLAN is attached to it.
     *
     * @throws ValidationException
     */
    public function delete(CatalogVlan $entry, Actor $actor): void
    {
        DB::transaction(function () use ($entry, $actor): void {
            $vlans = $entry->vlans()->lockForUpdate()->count();

            if ($vlans > 0) {
                throw ValidationException::withMessages([
                    'catalog_vlan' => trans_choice(
                        'A VLAN is still attached to this entry: detach it first.|:count VLANs are still attached to this entry: detach them first.',
                        $vlans,
                    ),
                ]);
            }

            $this->log->deleted(ActivityEvent::CatalogVlanDeleted, $entry, $actor);

            $entry->delete();
        });
    }

    /**
     * Attach several VLANs to an entry at once. Either all of them are
     * attached, or none is: each refusal is told on the line of its VLAN.
     *
     * Only the VLANs numbered like the entry are attached this way. A VLAN
     * with another number is a deviation one decides on, VLAN by VLAN, in
     * its own form: it is not something to do in a row.
     *
     * @param  array<int, int>  $vlanIds  The VLANs, by line.
     * @return list<Vlan>
     *
     * @throws ValidationException Messages are keyed "vlans.<line>".
     */
    public function attach(CatalogVlan $entry, array $vlanIds, Actor $actor): array
    {
        return DB::transaction(function () use ($entry, $vlanIds, $actor): array {
            // The entry is locked, so that two simultaneous requests cannot
            // both give it a VLAN on the same site, and read again: its
            // number is the one the VLANs are compared with.
            $entry = CatalogVlan::query()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail();

            $vlans = Vlan::query()
                ->with(['site', 'catalogVlan'])
                ->whereKey($vlanIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // The sites that already follow this entry, and with which VLAN.
            // Two VLANs of this very request cannot be of the same site: a
            // site has one VLAN per number, and all of them must have the
            // number of the entry.
            $taken = $entry->vlans()->get()->mapWithKeys(fn (Vlan $vlan): array => [$vlan->site_id => $vlan->label()])->all();

            $errors = [];

            foreach ($vlanIds as $line => $id) {
                $vlan = $vlans->get($id);

                $error = match (true) {
                    $vlan === null => __('This VLAN no longer exists.'),
                    $vlan->catalog_vlan_id === $entry->id => __('This VLAN is already attached to this entry.'),
                    $vlan->catalogVlan !== null => __('This VLAN is already attached to the entry :entry.', ['entry' => $vlan->catalogVlan->label()]),
                    $vlan->vid !== $entry->vid => __('This VLAN is numbered :vid, not :expected like the entry: attach it from its own form.', [
                        'vid' => $vlan->vid,
                        'expected' => $entry->vid,
                    ]),
                    isset($taken[$vlan->site_id]) => __('The site :site already has a VLAN attached to this entry (:vlan).', [
                        'site' => $vlan->site->name,
                        'vlan' => $taken[$vlan->site_id],
                    ]),
                    default => null,
                };

                if ($error !== null) {
                    $errors['vlans.'.$line] = $error;
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $attached = [];

            foreach ($vlanIds as $line => $id) {
                /** @var Vlan $vlan */
                $vlan = $vlans->get($id);

                $before = $vlan->auditValues();

                $vlan->catalog_vlan_id = $entry->id;

                $this->guardingUniqueness(fn (): bool => $vlan->save(), [
                    'vlans_site_id_catalog_vlan_id_unique' => ['vlans.'.$line, __('This site already has a VLAN attached to this entry of the catalogue.')],
                ]);

                $vlan->unsetRelation('catalogVlan');

                $this->log->updated(ActivityEvent::VlanUpdated, $vlan, $before, $actor);

                $attached[] = $vlan;
            }

            return $attached;
        });
    }

    /**
     * The entry a VLAN can be attached to in one action: the one that has
     * its number, when the VLAN follows no entry yet and no other VLAN of
     * its site follows that one. Null when there is nothing to propose.
     *
     * Such a VLAN passes every rule of attach().
     */
    public function entryFor(Vlan $vlan): ?CatalogVlan
    {
        if ($vlan->catalog_vlan_id !== null) {
            return null;
        }

        $entry = CatalogVlan::query()->where('vid', $vlan->vid)->first();

        if ($entry === null) {
            return null;
        }

        $siteFollowsIt = Vlan::query()
            ->where('site_id', $vlan->site_id)
            ->where('catalog_vlan_id', $entry->id)
            ->exists();

        return $siteFollowsIt ? null : $entry;
    }

    /**
     * @param  EntryData  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'vid' => $data['vid'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ];
    }

    /**
     * @throws ValidationException
     */
    private function assertVid(int $vid): void
    {
        if ($vid < Vlan::MIN_VID || $vid > Vlan::MAX_VID) {
            throw ValidationException::withMessages([
                'vid' => __('The VLAN number must be between :min and :max.', ['min' => Vlan::MIN_VID, 'max' => Vlan::MAX_VID]),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function save(CatalogVlan $entry): void
    {
        $this->assertFree($entry);

        // Two simultaneous requests can both find the number and the name
        // free: the database has the last word, with a shorter message.
        $this->guardingUniqueness(fn (): bool => $entry->save(), [
            'catalog_vlans_vid_unique' => ['vid', __('The catalogue already has an entry with this number.')],
            'catalog_vlans_name_unique' => ['name', __('The catalogue already has an entry with this name.')],
        ]);
    }

    /**
     * The number and the name of an entry are each unique in the catalogue.
     *
     * An entry stands for every site. Someone who creates a second "10
     * Bureautique" for another site needs to be told so, and where to go:
     * the refusal names the entry that is already there, and carries it.
     *
     * @throws CatalogVlanTaken
     */
    private function assertFree(CatalogVlan $entry): void
    {
        $others = fn (): Builder => CatalogVlan::query()
            ->when($entry->exists, fn (Builder $query) => $query->whereKeyNot($entry->getKey()));

        $byVid = $others()->where('vid', $entry->vid)->first();
        // Compared as the unique index of the database compares names.
        $byName = $others()->where('name', $entry->name)->first();

        $messages = [];

        if ($byVid !== null) {
            $messages['vid'] = __('The VLAN :vid ":name" is already in the catalogue. An entry stands for every site: attach the VLAN of this site to it from the page of the entry.', [
                'vid' => $byVid->vid,
                'name' => $byVid->name,
            ]);
        }

        if ($byName !== null) {
            $messages['name'] = __('The name ":name" is already that of the entry :vid.', [
                'name' => $byName->name,
                'vid' => $byName->vid,
            ]);
        }

        if ($messages !== []) {
            throw CatalogVlanTaken::withMessages($messages)->inTheWay($byVid, $byName);
        }
    }
}
