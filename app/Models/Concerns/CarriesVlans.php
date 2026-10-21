<?php

// torsade/app/Models/Concerns/CarriesVlans.php

namespace App\Models\Concerns;

use App\Enums\PortMode;
use App\Models\Device;
use App\Models\PortVlan;
use App\Models\Site;
use App\Models\Vlan;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a port and an aggregate of ports have in common: a mode, and the
 * VLANs carried in that mode.
 *
 * An access port carries one untagged VLAN and, at most, one tagged VLAN,
 * for the voice. A trunk carries one untagged VLAN at most, its native
 * VLAN, and tagged VLANs. Without a mode, no VLAN.
 *
 * The methods read "portVlans", the VLAN of each line and, for a VLAN of
 * another site than the one of the device, the site of the VLAN: they must
 * be loaded where many ports are shown.
 *
 * @property PortMode|null $mode
 * @property-read Device $device
 * @property-read Collection<int, PortVlan> $portVlans
 */
trait CarriesVlans
{
    /**
     * The VLANs carried, each with whether it is tagged.
     *
     * @return HasMany<PortVlan, $this>
     */
    abstract public function portVlans(): HasMany;

    /**
     * The untagged VLAN: the VLAN of an access port, the native VLAN of a
     * trunk.
     */
    public function untaggedVlan(): ?Vlan
    {
        return $this->portVlans->first(fn (PortVlan $line): bool => ! $line->tagged)?->vlan;
    }

    /**
     * The tagged VLANs, by number: the voice VLAN of an access port, the
     * VLANs of a trunk.
     *
     * @return list<Vlan>
     */
    public function taggedVlans(): array
    {
        return array_values(
            $this->portVlans
                ->filter(fn (PortVlan $line): bool => $line->tagged)
                ->map(fn (PortVlan $line): Vlan => $line->vlan)
                ->sortBy(fn (Vlan $vlan): int => $vlan->vid)
                ->all(),
        );
    }

    /**
     * The mode and the VLANs in a few words, as a table shows them:
     * "Access 10", "Access 10, voice 30", "Trunk, native 99: 10, 20, 30".
     * The VLANs of another site than the one of the device follow those of
     * its site, behind the code of their site, or its name when it has no
     * code: "Trunk, native 99: 10, 20 · ATL 60, 70". Null without a mode.
     *
     * @param  int|null  $siteId  The site of the device, read from the device when it is not given.
     */
    public function vlanSummary(?int $siteId = null): ?string
    {
        $siteId ??= $this->device->site_id;
        $site = fn (Site $site): string => $site->shortName();
        $untagged = $this->untaggedVlan();
        $tagged = $this->taggedVlans();

        $number = fn (Vlan $vlan): string => self::numbered([$vlan], $siteId, $site);

        if ($this->mode === PortMode::Access) {
            return match (true) {
                $untagged === null => __('Access'),
                $tagged === [] => __('Access :vid', ['vid' => $number($untagged)]),
                default => __('Access :vid, voice :voice', ['vid' => $number($untagged), 'voice' => $number($tagged[0])]),
            };
        }

        if ($this->mode === PortMode::Trunk) {
            $vlans = self::numbered($tagged, $siteId, $site);

            return match (true) {
                $untagged === null && $tagged === [] => __('Trunk'),
                $untagged === null => __('Trunk: :vlans', ['vlans' => $vlans]),
                $tagged === [] => __('Trunk, native :vid', ['vid' => $number($untagged)]),
                default => __('Trunk, native :vid: :vlans', ['vid' => $number($untagged), 'vlans' => $vlans]),
            };
        }

        return null;
    }

    /**
     * The VLANs as the activity log writes them, in a form that does not
     * depend on a language: "U 10 ; T 20, 30", U for untagged and T for
     * tagged. A VLAN of another site than the one of the device is named
     * with its site, as the summary does: "U 10 ; T 20 · Atelier 60, 70".
     * Null when none is carried.
     */
    public function vlanAuditValue(): ?string
    {
        $siteId = $this->device->site_id;
        $site = fn (Site $site): string => $site->name;
        $untagged = $this->untaggedVlan();
        $tagged = $this->taggedVlans();

        $parts = array_filter([
            $untagged === null ? null : 'U '.self::numbered([$untagged], $siteId, $site),
            $tagged === [] ? null : 'T '.self::numbered($tagged, $siteId, $site),
        ]);

        return $parts === [] ? null : implode(' ; ', $parts);
    }

    /**
     * VLANs by their numbers: those of the site first, then those of each
     * other site behind its name, the other sites by name. "10, 20 · ATL
     * 60, 70".
     *
     * @param  list<Vlan>  $vlans  By number.
     * @param  Closure(Site): string  $name  How a site is named.
     */
    private static function numbered(array $vlans, int $siteId, Closure $name): string
    {
        $own = [];
        /** @var array<int, array{name: string, vids: list<int>}> $others */
        $others = [];

        foreach ($vlans as $vlan) {
            if ($vlan->site_id === $siteId) {
                $own[] = $vlan->vid;

                continue;
            }

            $others[$vlan->site_id] ??= ['name' => $name($vlan->site), 'vids' => []];
            $others[$vlan->site_id]['vids'][] = $vlan->vid;
        }

        uksort($others, fn (int $a, int $b): int => [mb_strtolower($others[$a]['name']), $a] <=> [mb_strtolower($others[$b]['name']), $b]);

        $groups = $own === [] ? [] : [implode(', ', $own)];

        foreach ($others as $other) {
            $groups[] = $other['name'].' '.implode(', ', $other['vids']);
        }

        return implode(' · ', $groups);
    }
}
