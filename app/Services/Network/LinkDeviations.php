<?php

// torsade/app/Services/Network/LinkDeviations.php

namespace App\Services\Network;

use App\Enums\DeviationKind;
use App\Enums\PortMode;
use App\Enums\Status;
use App\Models\Link;
use App\Models\Port;
use App\Models\PortAggregate;
use App\Models\Vlan;
use App\Services\Network\Concerns\PhrasesMessages;
use Illuminate\Support\Collection;

/**
 * Where the two ends of a link do not agree: an access port facing a trunk,
 * two access ports on different VLANs, two trunks whose native VLAN or
 * tagged VLANs differ, two different VLANs carried under one number, which
 * the link merges into one network, an aggregate whose links lead to
 * several devices or face ports that are not one same aggregate, an active
 * link on a port that is shut down; and, when the cabling is given, where
 * it contradicts the link, carries a retired one, or leads to a jack where
 * nothing is plugged.
 *
 * Nothing here is stored and nothing is refused: a network is documented as
 * it is, and a deviation only makes its imperfection visible. Each one
 * comes with its reason in words.
 *
 * What is compared is the effective configuration of each end: the mode and
 * the VLANs of its port or, for a port of an aggregate, those of the
 * aggregate. VLANs are compared by their number first: the two ends of a
 * link carry a VLAN under its number, the cable knows nothing else. Then,
 * for each number both ends carry, by the VLANs themselves: two different
 * VLANs under one number, the VLAN 10 of two sites, are two networks the
 * link merges into one. Modes and VLANs are only compared when both ends
 * have a mode: the port of a firewall or of a server has none, and nothing
 * is expected of it.
 *
 * The methods work on links already read, with what RELATIONS names: they
 * never query one link at a time, so that a page costs the same whatever it
 * lists. Every link between two devices must be given together: whether an
 * aggregate faces one same aggregate is told by all of them. Whether the
 * aggregate missing in front of an aggregate on one side only can be
 * created depends on all the links of that aggregate, which a page may not
 * show: they are read for all such aggregates at once.
 *
 * @phpstan-type Deviation array{kind: string, reason: string, action?: array<string, int|string>}
 * @phpstan-type Facing array{aggregate: PortAggregate, device: string, device_id: int, facing: array<int, PortAggregate>, loose: bool, links: list<int>}
 */
final class LinkDeviations
{
    use PhrasesMessages;

    /**
     * What a link must be loaded with for its deviations to be worked out
     * without a query.
     *
     * @var list<string>
     */
    public const array RELATIONS = [
        'ends.port.device',
        'ends.port.portVlans.vlan.site',
        'ends.port.aggregate.portVlans.vlan.site',
    ];

    /**
     * How many VLANs a reason names before it says "and N more".
     */
    public const int VLANS_NAMED = 10;

    /**
     * The action of an aggregate whose opposite ports, all on one device,
     * are in no aggregate: the aggregate in front is created from them.
     */
    public const string CREATE_OPPOSITE_AGGREGATE = 'create_opposite_aggregate';

    public function __construct(
        private readonly OppositeAggregates $opposites,
    ) {}

    /**
     * The deviations of each link, by the identifier of the link.
     *
     * @param  Collection<int, Link>  $links
     * @param  int|null  $from  The device whose page shows them, if any: its own ports and aggregates are named without it.
     * @param  CablingMap|null  $cabling  The cabled paths of the sites of the links, when what they tell is wanted.
     * @return array<int, list<Deviation>>
     */
    public function ofLinks(Collection $links, ?int $from = null, ?CablingMap $cabling = null): array
    {
        $oneSided = [];
        $facings = $this->facings($links);
        $inFront = $this->devicesInFront($facings);

        foreach ($facings as $id => $byDevice) {
            foreach ($byDevice as $facing) {
                $deviation = $this->oneSided($facing, $from, $inFront[$id] ?? null);

                if ($deviation === null) {
                    continue;
                }

                foreach ($facing['links'] as $link) {
                    $oneSided[$link][] = $deviation;
                }
            }
        }

        $found = [];

        foreach ($links as $link) {
            [$near, $far] = $link->portsFrom($from);

            $found[$link->id] = [
                ...$this->between($near, $far),
                ...$this->merged($near, $far),
                ...($oneSided[$link->id] ?? []),
                ...$this->disabled($link, $near, $far, $from),
                ...($cabling?->ofLink($link) ?? []),
            ];
        }

        return $found;
    }

    /**
     * The deviations of each aggregate that has a link, by the identifier
     * of the aggregate: its links lead to several devices, or the ports it
     * faces on a device are not one same aggregate.
     *
     * @param  Collection<int, Link>  $links
     * @param  int|null  $from  The device whose page shows them, if any.
     * @return array<int, list<Deviation>>
     */
    public function ofAggregates(Collection $links, ?int $from = null): array
    {
        $found = [];
        $facings = $this->facings($links);
        $inFront = $this->devicesInFront($facings);

        foreach ($facings as $id => $byDevice) {
            $deviations = [];
            $aggregate = array_values($byDevice)[0]['aggregate'];

            if (count($byDevice) > 1) {
                $devices = array_column($byDevice, 'device');

                sort($devices, SORT_NATURAL | SORT_FLAG_CASE);

                $deviations[] = $this->deviation(
                    DeviationKind::AggregateSeveralDevices,
                    __(':aggregate leads to :devices', ['aggregate' => $this->named($aggregate, $from), 'devices' => $this->listed($devices)]),
                );
            }

            foreach ($byDevice as $facing) {
                $deviation = $this->oneSided($facing, $from, $inFront[$id] ?? null);

                if ($deviation !== null) {
                    $deviations[] = $deviation;
                }
            }

            $found[$id] = $deviations;
        }

        return $found;
    }

    /**
     * What each aggregate faces, device by device: the aggregates of the
     * opposite ports, whether one of them is in no aggregate, and the
     * links that lead there.
     *
     * @param  Collection<int, Link>  $links
     * @return array<int, array<int, Facing>>
     */
    private function facings(Collection $links): array
    {
        $facings = [];

        foreach ($links as $link) {
            [$first, $second] = $link->portsFrom();

            foreach ([[$first, $second], [$second, $first]] as [$port, $opposite]) {
                $aggregate = $port->aggregate;

                if ($aggregate === null) {
                    continue;
                }

                // The device of an aggregate is the one of its ports.
                $aggregate->setRelation('device', $port->device);

                $facings[$aggregate->id][$opposite->device_id] ??= [
                    'aggregate' => $aggregate,
                    'device' => $opposite->device->name,
                    'device_id' => $opposite->device_id,
                    'facing' => [],
                    'loose' => false,
                    'links' => [],
                ];

                $facing = &$facings[$aggregate->id][$opposite->device_id];

                if ($opposite->aggregate === null) {
                    $facing['loose'] = true;
                } else {
                    $facing['facing'][$opposite->aggregate->id] = $opposite->aggregate;
                }

                $facing['links'][] = $link->id;

                unset($facing);
            }
        }

        return $facings;
    }

    /**
     * The device on which the aggregate missing in front of each aggregate
     * can be created, for the aggregates that face only ports outside any
     * aggregate, on one device, in the links given. The other links of
     * such an aggregate may not be among them: a page shows the links of
     * one device or of one site, so all of them are read.
     *
     * @param  array<int, array<int, Facing>>  $facings
     * @return array<int, int>
     */
    private function devicesInFront(array $facings): array
    {
        $candidates = [];

        foreach ($facings as $id => $byDevice) {
            $facing = array_values($byDevice)[0];

            if (count($byDevice) === 1 && $facing['loose'] && $facing['facing'] === []) {
                $candidates[] = $id;
            }
        }

        return $this->opposites->devicesInFront($candidates);
    }

    /**
     * An aggregate on one side only: the ports it faces on a device are
     * not all in one same aggregate. When the aggregate missing in front
     * can be created on that device, from those ports, the deviation says
     * so.
     *
     * @param  Facing  $facing
     * @param  int|null  $inFront  The device on which the aggregate missing in front can be created, if any.
     * @return Deviation|null
     */
    private function oneSided(array $facing, ?int $from, ?int $inFront): ?array
    {
        $name = $this->named($facing['aggregate'], $from);

        if ($facing['loose']) {
            $deviation = $this->deviation(
                DeviationKind::AggregateOneSided,
                __(':aggregate: opposite, ports outside any aggregate on :device', ['aggregate' => $name, 'device' => $facing['device']]),
            );

            if ($inFront === $facing['device_id'] && $facing['facing'] === []) {
                $deviation['action'] = [
                    'type' => self::CREATE_OPPOSITE_AGGREGATE,
                    'aggregate' => $facing['aggregate']->id,
                    'device' => $facing['device_id'],
                ];
            }

            return $deviation;
        }

        if (count($facing['facing']) < 2) {
            return null;
        }

        $names = array_map(fn (PortAggregate $aggregate): string => $aggregate->name, array_values($facing['facing']));

        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $this->deviation(
            DeviationKind::AggregateOneSided,
            __(':aggregate: opposite, several aggregates on :device (:names)', [
                'aggregate' => $name,
                'device' => $facing['device'],
                'names' => implode(', ', $names),
            ]),
        );
    }

    /**
     * How the modes and the VLANs of the two ends differ. Nothing when one
     * of them has no mode: it is not compared.
     *
     * @return list<Deviation>
     */
    private function between(Port $near, Port $far): array
    {
        $one = $near->aggregate ?? $near;
        $other = $far->aggregate ?? $far;

        if ($one->mode === null || $other->mode === null) {
            return [];
        }

        if ($one->mode !== $other->mode) {
            return [$this->deviation(
                DeviationKind::LinkModes,
                __('Access on one side (:end), trunk on the other', ['end' => $this->holder($one->mode === PortMode::Access ? $near : $far)]),
            )];
        }

        $untagged = [$one->untaggedVlan()?->vid, $other->untaggedVlan()?->vid];

        if ($one->mode === PortMode::Access) {
            // An access port without its VLAN yet is not on another VLAN.
            return $untagged[0] === null || $untagged[1] === null || $untagged[0] === $untagged[1] ? [] : [$this->deviation(
                DeviationKind::LinkAccessVlans,
                __('VLAN :first on one side, :second on the other', ['first' => $untagged[0], 'second' => $untagged[1]]),
            )];
        }

        $deviations = [];

        if ($untagged[0] !== $untagged[1]) {
            $deviations[] = $this->deviation(DeviationKind::LinkNativeVlans, match (true) {
                $untagged[0] === null => __('Native :vid on one side (:end), none on the other', ['vid' => $untagged[1], 'end' => $this->holder($far)]),
                $untagged[1] === null => __('Native :vid on one side (:end), none on the other', ['vid' => $untagged[0], 'end' => $this->holder($near)]),
                default => __('Native :first on one side, :second on the other', ['first' => $untagged[0], 'second' => $untagged[1]]),
            });
        }

        $tagged = [
            array_map(fn (Vlan $vlan): int => $vlan->vid, $one->taggedVlans()),
            array_map(fn (Vlan $vlan): int => $vlan->vid, $other->taggedVlans()),
        ];

        $only = array_filter([
            $this->onlyOn($near, array_values(array_diff($tagged[0], $tagged[1]))),
            $this->onlyOn($far, array_values(array_diff($tagged[1], $tagged[0]))),
        ]);

        if ($only !== []) {
            $deviations[] = $this->deviation(DeviationKind::LinkTaggedVlans, implode(' ; ', $only));
        }

        return $deviations;
    }

    /**
     * Two different VLANs under one number at the two ends, however each
     * end carries it, untagged, for the voice, native or tagged: the link
     * merges two networks, one deviation for each number. Nothing when one
     * of them has no mode.
     *
     * @return list<Deviation>
     */
    private function merged(Port $near, Port $far): array
    {
        $one = $near->aggregate ?? $near;
        $other = $far->aggregate ?? $far;

        if ($one->mode === null || $other->mode === null) {
            return [];
        }

        $opposite = [];

        foreach ($other->portVlans as $line) {
            $opposite[$line->vlan->vid] = $line->vlan;
        }

        $own = [];

        foreach ($one->portVlans as $line) {
            $own[$line->vlan->vid] = $line->vlan;
        }

        ksort($own);

        $deviations = [];

        foreach ($own as $vid => $vlan) {
            $facing = $opposite[$vid] ?? null;

            if ($facing === null || $facing->id === $vlan->id) {
                continue;
            }

            $deviations[] = $this->deviation(
                DeviationKind::LinkMergesVlans,
                __('VLAN :vid of the site :site on one side, VLAN :vid of the site :other on the other: the link merges two networks', [
                    'vid' => $vid,
                    'site' => $vlan->site->name,
                    'other' => $facing->site->name,
                ]),
            );
        }

        return $deviations;
    }

    /**
     * "VLAN 30 only on sw-coeur-01": the VLANs one end tags and the other
     * does not, ten at most. Null when there is none.
     *
     * @param  list<int>  $vids
     */
    private function onlyOn(Port $port, array $vids): ?string
    {
        if ($vids === []) {
            return null;
        }

        // "VLAN" names what follows, one number or several: it has one form.
        return __('VLAN :vids only on :device', [
            'vids' => $this->enumerate(array_map(strval(...), $vids), self::VLANS_NAMED),
            'device' => $port->device->name,
        ]);
    }

    /**
     * An active link on a port that is shut down: one of the two says
     * something that is no longer true.
     *
     * @return list<Deviation>
     */
    private function disabled(Link $link, Port $near, Port $far, ?int $from): array
    {
        if ($link->status !== Status::Active) {
            return [];
        }

        $names = [];

        foreach ([$near, $far] as $port) {
            if (! $port->enabled) {
                $names[] = $port->device_id === $from ? $port->name : Links::named($port);
            }
        }

        return $names === [] ? [] : [$this->deviation(
            DeviationKind::LinkOnDisabledPort,
            trans_choice(':ports is disabled|:ports are disabled', count($names), ['ports' => $this->listed($names)]),
        )];
    }

    /**
     * What holds the configuration of an end, named with its device:
     * "sw-etage-1 › 1/0/48", or "sw-serveurs › Po1" for a port of an
     * aggregate.
     */
    private function holder(Port $port): string
    {
        return $port->device->name.' › '.($port->aggregate === null ? $port->name : $port->aggregate->name);
    }

    /**
     * How an aggregate is named: by its name on the page of its device,
     * with its device anywhere else, two devices often having each their
     * "Po1".
     */
    private function named(PortAggregate $aggregate, ?int $from): string
    {
        return $aggregate->device_id === $from ? $aggregate->name : $aggregate->device->name.' › '.$aggregate->name;
    }

    /**
     * @return Deviation
     */
    private function deviation(DeviationKind $kind, string $reason): array
    {
        return ['kind' => $kind->value, 'reason' => $reason];
    }
}
