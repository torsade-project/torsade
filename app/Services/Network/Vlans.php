<?php

// torsade/app/Services/Network/Vlans.php

namespace App\Services\Network;

use App\Enums\ActivityEvent;
use App\Enums\Status;
use App\Models\Vlan;
use App\Services\Network\Concerns\TranslatesUniqueViolations;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change to a VLAN goes through this class, whether it comes from the
 * interface or from a command: the rules are enforced here and each change
 * is written to the activity log.
 *
 * @phpstan-type VlanData array{site_id: int, catalog_vlan_id?: int|null, vid: int, name: string, description?: string|null, status?: string}
 */
final class Vlans
{
    use TranslatesUniqueViolations;

    public function __construct(
        private readonly ChangeLog $log,
        private readonly Subnets $subnets,
        private readonly VlanCarriers $carriers,
    ) {}

    /**
     * Create a VLAN and, when given, its subnet, cut into the ranges of the
     * addressing rule when asked. All of it is saved together or not at
     * all: a refused subnet leaves no VLAN behind.
     *
     * @param  VlanData  $data
     * @param  array{network: string, prefix_length: int, gateway?: string|null, name?: string|null, description?: string|null}|null  $subnet
     *
     * @throws ValidationException Messages about the subnet are keyed "subnet.<field>".
     */
    public function create(array $data, ?array $subnet, Actor $actor, bool $withBreakdown = false): Vlan
    {
        return DB::transaction(function () use ($data, $subnet, $actor, $withBreakdown): Vlan {
            $this->assertVid($data['vid']);

            $vlan = new Vlan($this->attributes($data));

            $this->assertEntryIsFree($vlan);

            $this->save($vlan);

            $this->log->created(ActivityEvent::VlanCreated, $vlan, $actor);

            if ($subnet !== null) {
                try {
                    // The subnet starts with the status of its VLAN.
                    $this->subnets->create([...$subnet, 'vlan_id' => $vlan->id, 'status' => $vlan->status->value], $actor, $withBreakdown);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(
                        collect($exception->errors())
                            ->mapWithKeys(fn (array $messages, string $field): array => ['subnet.'.$field => $messages])
                            ->all(),
                    );
                }
            }

            return $vlan;
        });
    }

    /**
     * @param  VlanData  $data
     *
     * @throws ValidationException
     */
    public function update(Vlan $vlan, array $data, Actor $actor): void
    {
        DB::transaction(function () use ($vlan, $data, $actor): void {
            $this->assertVid($data['vid']);

            $before = $vlan->auditValues();

            // What the subnet of the VLAN is before the change, should it
            // have to follow the VLAN to another site.
            $subnet = $vlan->subnet()->lockForUpdate()->first();
            $subnetBefore = $subnet?->auditValues();

            // The VLAN itself, kept from other changes: a port given it
            // meanwhile waits for the end of the transaction, then reads its
            // new number.
            Vlan::query()->whereKey($vlan->id)->lockForUpdate()->first();

            $vlan->fill($this->attributes($data, $vlan));

            $renumbered = $vlan->isDirty('vid');
            $moved = $vlan->isDirty('site_id');

            $this->assertEntryIsFree($vlan);

            $this->save($vlan);

            // Once the number is known to be free on the site of the VLAN,
            // which is the first thing to tell.
            if ($renumbered) {
                $this->assertOneVlanPerNumber($vlan);
            }

            // The site and the entry of the catalogue may have changed:
            // read them again for the log.
            $vlan->unsetRelation('site')->unsetRelation('catalogVlan');

            $this->log->updated(ActivityEvent::VlanUpdated, $vlan, $before, $actor);

            if ($moved && $subnet !== null && $subnetBefore !== null) {
                $this->subnets->followVlan($subnet, $vlan, $subnetBefore, $actor);
            }
        });
    }

    /**
     * A VLAN is only deleted once its subnet is gone or detached, and once
     * no port nor aggregate carries it.
     *
     * @throws ValidationException
     */
    public function delete(Vlan $vlan, Actor $actor): void
    {
        DB::transaction(function () use ($vlan, $actor): void {
            $subnet = $vlan->subnet()->lockForUpdate()->first();

            if ($subnet !== null) {
                throw ValidationException::withMessages([
                    'vlan' => __('The subnet :cidr is attached to this VLAN: delete it or detach it first.', ['cidr' => $subnet->cidr()]),
                ]);
            }

            $carriers = $this->carriers->of($vlan);

            if ($carriers !== []) {
                throw ValidationException::withMessages([
                    'vlan' => trans_choice(
                        'A port or an aggregate still carries this VLAN: :ports. Take the VLAN away from it first.|:count ports or aggregates still carry this VLAN: :ports. Take the VLAN away from them first.',
                        array_sum(array_map(fn (array $group): int => count($group['carriers']), $carriers)),
                        ['ports' => VlanCarriers::named($carriers)],
                    ),
                ]);
            }

            $this->log->deleted(ActivityEvent::VlanDeleted, $vlan, $actor);

            $vlan->delete();
        });
    }

    /**
     * @param  VlanData  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?Vlan $vlan = null): array
    {
        return [
            'site_id' => $data['site_id'],
            'catalog_vlan_id' => $data['catalog_vlan_id'] ?? null,
            'vid' => $data['vid'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? $vlan?->status->value ?? Status::Active->value,
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
     * A port or an aggregate never carries two VLANs of one number: the
     * VLAN does not take the number of another VLAN carried with it.
     *
     * @throws ValidationException
     */
    private function assertOneVlanPerNumber(Vlan $vlan): void
    {
        $crossed = $this->carriers->alongside($vlan, $vlan->vid);

        if ($crossed !== []) {
            throw ValidationException::withMessages([
                'vid' => trans_choice(
                    'A port or an aggregate that carries this VLAN already carries another VLAN :vid: :ports.|:count ports or aggregates that carry this VLAN already carry another VLAN :vid: :ports.',
                    array_sum(array_map(fn (array $group): int => count($group['carriers']), $crossed)),
                    ['vid' => $vlan->vid, 'ports' => VlanCarriers::named($crossed)],
                ),
            ]);
        }
    }

    /**
     * An entry of the catalogue is followed by one VLAN per site at most.
     * The VLAN may differ from its entry, by its number or its name: that
     * is a deviation, never a refusal.
     *
     * @throws ValidationException
     */
    private function assertEntryIsFree(Vlan $vlan): void
    {
        if ($vlan->catalog_vlan_id === null) {
            return;
        }

        $other = Vlan::query()
            ->where('site_id', $vlan->site_id)
            ->where('catalog_vlan_id', $vlan->catalog_vlan_id)
            ->whereKeyNot($vlan->getKey())
            ->first();

        if ($other !== null) {
            throw ValidationException::withMessages([
                'catalog_vlan_id' => __('This site already has a VLAN attached to this entry of the catalogue (:vlan).', ['vlan' => $other->label()]),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function save(Vlan $vlan): void
    {
        $this->guardingUniqueness(fn (): bool => $vlan->save(), [
            'vlans_site_id_vid_unique' => ['vid', __('This site already has a VLAN with this number.')],
            'vlans_site_id_name_unique' => ['name', __('This site already has a VLAN with this name.')],
            'vlans_site_id_catalog_vlan_id_unique' => ['catalog_vlan_id', __('This site already has a VLAN attached to this entry of the catalogue.')],
        ]);
    }
}
