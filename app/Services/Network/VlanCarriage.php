<?php

// torsade/app/Services/Network/VlanCarriage.php

namespace App\Services\Network;

use App\Enums\PortMode;
use App\Models\Port;
use App\Models\PortAggregate;
use App\Models\PortVlan;
use App\Models\Vlan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The mode of a port or of an aggregate of ports, and the VLANs carried in
 * that mode: the rules are the same for both, and are enforced here.
 *
 * In access mode, one untagged VLAN and, at most, one tagged VLAN for the
 * voice. In trunk mode, one untagged VLAN at most, the native VLAN, and
 * tagged VLANs that are not the native one. Without a mode, no VLAN.
 *
 * A VLAN of any site can be carried: a central firewall routes the VLANs of
 * several sites, a fibre between two buildings carries those of the other
 * one. Two VLANs of one number never are: nothing would tell them apart on
 * the cable. The database cannot keep that rule without the number in each
 * line: it is checked here, on the VLANs as they are stored when the port
 * or the aggregate is written, and App\Services\Network\Vlans keeps it
 * when the number of a VLAN changes.
 *
 * @phpstan-type CarriageData array{mode?: string|null, untagged_vlan_id?: int|null, tagged_vlan_ids?: list<int>}
 * @phpstan-type Carriage array{mode: PortMode|null, untagged: Vlan|null, tagged: list<Vlan>}
 */
final class VlanCarriage
{
    /**
     * Check a mode and its VLANs for a device of a site, and return them
     * ready to be written. To be called in the transaction that writes
     * them: the VLANs are kept from a change of their number until its end.
     *
     * @param  CarriageData  $data
     * @return Carriage
     *
     * @throws ValidationException Messages are keyed by field.
     */
    public function resolve(int $siteId, array $data): array
    {
        $mode = ($data['mode'] ?? null) === null ? null : PortMode::from($data['mode']);
        $untaggedId = $data['untagged_vlan_id'] ?? null;
        $taggedIds = array_values(array_unique($data['tagged_vlan_ids'] ?? []));

        if ($mode === null) {
            if ($untaggedId !== null || $taggedIds !== []) {
                throw ValidationException::withMessages([
                    'mode' => __('Without a mode, no VLAN is carried: choose a mode.'),
                ]);
            }

            return ['mode' => null, 'untagged' => null, 'tagged' => []];
        }

        if ($mode === PortMode::Access && $untaggedId === null) {
            throw ValidationException::withMessages([
                'untagged_vlan_id' => __('Access mode carries one untagged VLAN: choose it.'),
            ]);
        }

        if ($mode === PortMode::Access && count($taggedIds) > 1) {
            throw ValidationException::withMessages([
                'tagged_vlan_ids' => __('Access mode carries one tagged VLAN at most, for the voice.'),
            ]);
        }

        if ($untaggedId !== null && in_array($untaggedId, $taggedIds, true)) {
            throw ValidationException::withMessages([
                'tagged_vlan_ids' => $mode === PortMode::Access
                    ? __('The voice VLAN is another VLAN than the untagged one.')
                    : __('The native VLAN cannot be tagged as well.'),
            ]);
        }

        // Their numbers as they are stored now, kept from any change until
        // the end of the transaction.
        $vlans = Vlan::query()
            ->with('site')
            ->whereKey([...$taggedIds, ...($untaggedId === null ? [] : [$untaggedId])])
            ->sharedLock()
            ->get()
            ->keyBy('id');

        $find = function (int $id, string $field) use ($vlans): Vlan {
            $vlan = $vlans->get($id);

            if ($vlan === null) {
                throw ValidationException::withMessages([$field => __('This VLAN no longer exists.')]);
            }

            return $vlan;
        };

        $carriage = [
            'mode' => $mode,
            'untagged' => $untaggedId === null ? null : $find($untaggedId, 'untagged_vlan_id'),
            'tagged' => array_map(fn (int $id): Vlan => $find($id, 'tagged_vlan_ids'), $taggedIds),
        ];

        $this->assertOneVlanPerNumber($carriage, $siteId);

        return $carriage;
    }

    /**
     * Two VLANs of one number, of two sites, are refused: the VLAN of the
     * site of the device is named first, then the other sites by name.
     *
     * @param  Carriage  $carriage
     *
     * @throws ValidationException
     */
    private function assertOneVlanPerNumber(array $carriage, int $siteId): void
    {
        /** @var array<int, list<Vlan>> $byNumber */
        $byNumber = [];

        foreach ([...($carriage['untagged'] === null ? [] : [$carriage['untagged']]), ...$carriage['tagged']] as $vlan) {
            $byNumber[$vlan->vid][] = $vlan;
        }

        ksort($byNumber);

        foreach ($byNumber as $vid => $same) {
            if (count($same) < 2) {
                continue;
            }

            usort($same, fn (Vlan $a, Vlan $b): int => [$a->site_id !== $siteId, mb_strtolower($a->site->name), $a->id] <=> [$b->site_id !== $siteId, mb_strtolower($b->site->name), $b->id]);

            throw ValidationException::withMessages([
                'tagged_vlan_ids' => __('The VLAN :vid of the site :site and the VLAN :vid of the site :other cannot go through the same port.', [
                    'vid' => $vid,
                    'site' => $same[0]->site->name,
                    'other' => $same[1]->site->name,
                ]),
            ]);
        }
    }

    /**
     * Whether a port or an aggregate already has this mode and carries
     * exactly these VLANs. Its "portVlans" must be loaded.
     *
     * @param  Carriage  $carriage
     */
    public function matches(Port|PortAggregate $holder, array $carriage): bool
    {
        return $holder->mode === $carriage['mode'] && $this->key($holder->portVlans->all()) === $this->key($this->lines($carriage));
    }

    /**
     * Give a port or an aggregate its mode and its VLANs, in place of
     * those it had. To be called in a transaction.
     *
     * @param  Carriage  $carriage
     */
    public function write(Port|PortAggregate $holder, array $carriage): void
    {
        $holder->mode = $carriage['mode'];
        $holder->save();

        $holder->portVlans()->delete();

        $lines = [];

        foreach ($this->lines($carriage) as $line) {
            $written = $holder->portVlans()->create(['vlan_id' => $line->vlan_id, 'tagged' => $line->tagged]);
            // What the summary and the activity log read of each line.
            $written->setRelation('vlan', $line->vlan);

            $lines[] = $written;
        }

        $holder->setRelation('portVlans', new Collection($lines));
    }

    /**
     * Take its mode and its VLANs away from a port or an aggregate.
     */
    public function clear(Port|PortAggregate $holder): void
    {
        $this->write($holder, ['mode' => null, 'untagged' => null, 'tagged' => []]);
    }

    /**
     * The lines a carriage is made of, not written yet.
     *
     * @param  Carriage  $carriage
     * @return list<PortVlan>
     */
    private function lines(array $carriage): array
    {
        $line = function (Vlan $vlan, bool $tagged): PortVlan {
            $line = new PortVlan(['vlan_id' => $vlan->id, 'tagged' => $tagged]);
            $line->setRelation('vlan', $vlan);

            return $line;
        };

        return [
            ...($carriage['untagged'] === null ? [] : [$line($carriage['untagged'], false)]),
            ...array_map(fn (Vlan $vlan): PortVlan => $line($vlan, true), $carriage['tagged']),
        ];
    }

    /**
     * Lines as one comparable value, whatever their order.
     *
     * @param  array<int, PortVlan>  $lines
     * @return list<string>
     */
    private function key(array $lines): array
    {
        $key = array_map(fn (PortVlan $line): string => $line->vlan_id.($line->tagged ? 'T' : 'U'), array_values($lines));

        sort($key);

        return $key;
    }
}
