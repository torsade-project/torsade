<?php

// torsade/app/Services/Network/CablingMap.php

namespace App\Services\Network;

use App\Enums\DeviationKind;
use App\Models\Device;
use App\Models\Jack;
use App\Models\Link;
use App\Models\PatchPanelPort;
use App\Models\Port;

/**
 * The cabled paths of some sites, as App\Services\Network\CabledPaths
 * worked them out, and what they tell about links, ports and jacks: the
 * physical path of a link, and the deviations of the cabling. Nothing here
 * queries the database.
 *
 * A pair is named from its patched port, on the side of the panel, to the
 * port plugged into the jack: "sw-etage-1 1/0/24 and ap-salle-reunion eth0
 * are cabled by PB-B 12 › B.07".
 *
 * @phpstan-type Pair array{a: Port, b: Port, panel_port: PatchPanelPort, jack: Jack, state: string, links: list<Link>}
 * @phpstan-type Unplugged array{port: Port, link: Link, opposite: Port, panel_port: PatchPanelPort, jack: Jack, pluggable: bool}
 * @phpstan-type Addressed array{port: Port, panel_port: PatchPanelPort, jack: Jack, devices: list<Device>}
 * @phpstan-type CreateLinkAction array{type: string, device: int, port: int, opposite_device: int, opposite_port: int}
 * @phpstan-type PlugAction array{type: string, jack: int, port: int}
 * @phpstan-type Deviation array{kind: string, reason: string, action?: CreateLinkAction|PlugAction}
 * @phpstan-type Path array{port_id: int, panel_port: array{id: int, name: string, panel_id: int, place: string}, jack: array{id: int, label: string, floor_id: int, place: string}}
 */
final class CablingMap
{
    /** A link that is not retired joins the two ports: the cabling is its path. */
    public const string CONFIRMED = 'confirmed';

    /** A retired link joins the two ports, and the cords are still there. */
    public const string RETIRED = 'retired';

    /** One of the two ports has a link to another port. */
    public const string CONTRADICTED = 'contradicted';

    /** Neither port has a link. */
    public const string UNLINKED = 'unlinked';

    /** The action of a cabling without a link: the link, created from it. */
    public const string CREATE_LINK = 'create_link';

    /** The action of a port linked and patched to a jack where nothing is plugged. */
    public const string PLUG = 'plug';

    /**
     * The pairs and the ports linked without being plugged, by what looks
     * them up: a port, a jack, a link. Each list in the order of the
     * cords.
     *
     * @var array{port: array<int, list<int>>, jack: array<int, list<int>>, link: array<int, list<int>>}
     */
    private array $pairsBy = ['port' => [], 'jack' => [], 'link' => []];

    /**
     * @var array{port: array<int, list<int>>, jack: array<int, list<int>>, link: array<int, list<int>>}
     */
    private array $unpluggedBy = ['port' => [], 'jack' => [], 'link' => []];

    /**
     * @param  list<Pair>  $pairs
     * @param  list<Unplugged>  $unplugged
     * @param  list<Addressed>  $addressed
     */
    public function __construct(
        private readonly array $pairs,
        private readonly array $unplugged,
        private readonly array $addressed,
    ) {
        foreach ($pairs as $index => $pair) {
            $this->pairsBy['port'][$pair['a']->id][] = $index;
            $this->pairsBy['port'][$pair['b']->id][] = $index;
            $this->pairsBy['jack'][$pair['jack']->id][] = $index;

            foreach ($pair['links'] as $link) {
                $this->pairsBy['link'][$link->id][] = $index;
            }
        }

        foreach ($unplugged as $index => $one) {
            $this->unpluggedBy['port'][$one['port']->id][] = $index;
            $this->unpluggedBy['jack'][$one['jack']->id][] = $index;
            $this->unpluggedBy['link'][$one['link']->id][] = $index;
        }
    }

    /**
     * The pairs looked up by a port, a jack or a link.
     *
     * @param  'port'|'jack'|'link'  $by
     * @return list<Pair>
     */
    private function pairsOf(string $by, int $id): array
    {
        return array_map(fn (int $index): array => $this->pairs[$index], $this->pairsBy[$by][$id] ?? []);
    }

    /**
     * The ports linked without being plugged looked up by a port, a jack or
     * a link.
     *
     * @param  'port'|'jack'|'link'  $by
     * @return list<Unplugged>
     */
    private function unpluggedOf(string $by, int $id): array
    {
        return array_map(fn (int $index): array => $this->unplugged[$index], $this->unpluggedBy[$by][$id] ?? []);
    }

    /**
     * The ports cabled to each other.
     *
     * @return list<Pair>
     */
    public function pairs(): array
    {
        return $this->pairs;
    }

    /**
     * The ports linked to another one and patched to a jack where nothing
     * is plugged.
     *
     * @return list<Unplugged>
     */
    public function unplugged(): array
    {
        return $this->unplugged;
    }

    /**
     * The ports cabled to devices plugged into their jack by an address.
     *
     * @return list<Addressed>
     */
    public function addressed(): array
    {
        return $this->addressed;
    }

    /**
     * The physical path of a link, when the cabling confirms it: its
     * patched port, the port of the panel and the jack, "PB-B 12 › B.07".
     *
     * @return Path|null
     */
    public function pathOf(Link $link): ?array
    {
        foreach ($this->pairsOf('link', $link->id) as $pair) {
            if ($pair['state'] === self::CONFIRMED) {
                return self::path($pair['a'], $pair['panel_port'], $pair['jack']);
            }
        }

        return null;
    }

    /**
     * The physical paths of every link the cabling confirms, by the
     * identifier of the link.
     *
     * @return array<int, Path>
     */
    public function paths(): array
    {
        $paths = [];

        foreach ($this->pairs as $pair) {
            if ($pair['state'] === self::CONFIRMED) {
                $paths[$pair['links'][0]->id] = self::path($pair['a'], $pair['panel_port'], $pair['jack']);
            }
        }

        return $paths;
    }

    /**
     * What the cabling tells about a link: it contradicts it, the link is
     * retired while its cords are still there, or one of its ends is
     * patched to a jack where nothing is plugged.
     *
     * @return list<Deviation>
     */
    public function ofLink(Link $link): array
    {
        $deviations = [];

        foreach ($this->pairsOf('link', $link->id) as $pair) {
            if ($pair['state'] === self::RETIRED) {
                $deviations[] = $this->retired($pair);
            }

            if ($pair['state'] === self::CONTRADICTED) {
                foreach ([[$pair['a'], $pair['b']], [$pair['b'], $pair['a']]] as [$end, $cabled]) {
                    if ($end->linkEnd?->link->is($link)) {
                        $deviations[] = $this->contradicted($end, $cabled);
                    }
                }
            }
        }

        foreach ($this->unpluggedOf('link', $link->id) as $unplugged) {
            $deviations[] = $this->unpluggedDeviation($unplugged);
        }

        return $deviations;
    }

    /**
     * What the cabling tells about a port of a device: it is cabled to
     * another port without a link, or it is linked and patched to a jack
     * where nothing is plugged.
     *
     * @return list<Deviation>
     */
    public function ofPort(Port $port): array
    {
        $deviations = [];

        foreach ($this->pairsOf('port', $port->id) as $pair) {
            if ($pair['state'] === self::UNLINKED) {
                $deviations[] = $this->unlinked($pair);
            }
        }

        foreach ($this->unpluggedOf('port', $port->id) as $unplugged) {
            $deviations[] = $this->unpluggedDeviation($unplugged);
        }

        return $deviations;
    }

    /**
     * What the cabling tells about a jack: the two ports it joins have no
     * link, or the port it is patched to is linked while nothing is plugged
     * into it.
     *
     * @return list<Deviation>
     */
    public function ofJack(Jack $jack): array
    {
        $deviations = [];

        foreach ($this->pairsOf('jack', $jack->id) as $pair) {
            if ($pair['state'] === self::UNLINKED) {
                $deviations[] = $this->unlinked($pair);
            }
        }

        foreach ($this->unpluggedOf('jack', $jack->id) as $unplugged) {
            $deviations[] = $this->unpluggedDeviation($unplugged);
        }

        return $deviations;
    }

    /**
     * What the cabling of one pair tells by itself: it has no link, its
     * link is retired, or it contradicts the link of one of its ports. A
     * pair whose link it confirms tells nothing.
     *
     * @param  Pair  $pair
     * @return list<Deviation>
     */
    public function ofPair(array $pair): array
    {
        return match ($pair['state']) {
            self::UNLINKED => [$this->unlinked($pair)],
            self::RETIRED => [$this->retired($pair)],
            self::CONTRADICTED => array_values(array_map(
                fn (array $ends): array => $this->contradicted($ends[0], $ends[1]),
                array_filter(
                    [[$pair['a'], $pair['b']], [$pair['b'], $pair['a']]],
                    fn (array $ends): bool => $ends[0]->linkEnd !== null,
                ),
            )),
            default => [],
        };
    }

    /**
     * How a cabling is written: the port of the panel, then the jack,
     * "PB-B 12 › B.07".
     */
    public static function through(PatchPanelPort $panelPort, Jack $jack): string
    {
        return $panelPort->name().' › '.$jack->label;
    }

    /**
     * How a port is named in a reason: its device, then its name,
     * "sw-etage-1 1/0/24".
     */
    private static function named(Port $port): string
    {
        return $port->device->name.' '.$port->name;
    }

    /**
     * @return Path
     */
    private static function path(Port $patched, PatchPanelPort $panelPort, Jack $jack): array
    {
        return [
            'port_id' => $patched->id,
            'panel_port' => [
                'id' => $panelPort->id,
                'name' => $panelPort->name(),
                'panel_id' => $panelPort->patch_panel_id,
                'place' => $panelPort->panel->room->path(),
            ],
            'jack' => [
                'id' => $jack->id,
                'label' => $jack->label,
                'floor_id' => $jack->floor_id,
                'place' => $jack->floor->path(),
            ],
        ];
    }

    /**
     * @param  Pair  $pair
     * @return Deviation
     */
    private function unlinked(array $pair): array
    {
        return [
            'kind' => DeviationKind::CablingWithoutLink->value,
            'reason' => __(':first and :second are cabled by :path, without a link', [
                'first' => self::named($pair['a']),
                'second' => self::named($pair['b']),
                'path' => self::through($pair['panel_port'], $pair['jack']),
            ]),
            'action' => [
                'type' => self::CREATE_LINK,
                'device' => $pair['a']->device_id,
                'port' => $pair['a']->id,
                'opposite_device' => $pair['b']->device_id,
                'opposite_port' => $pair['b']->id,
            ],
        ];
    }

    /**
     * @param  Pair  $pair
     * @return Deviation
     */
    private function retired(array $pair): array
    {
        return [
            'kind' => DeviationKind::CablingOfRetiredLink->value,
            'reason' => __(':first and :second are still cabled by :path; their link is retired', [
                'first' => self::named($pair['a']),
                'second' => self::named($pair['b']),
                'path' => self::through($pair['panel_port'], $pair['jack']),
            ]),
        ];
    }

    /**
     * A link one of whose ends is cabled to another port than the one the
     * link leads to.
     *
     * @return Deviation
     */
    private function contradicted(Port $end, Port $cabled): array
    {
        $linked = $end->linkedPort();

        return [
            'kind' => DeviationKind::LinkContradictedByCabling->value,
            'reason' => __('The cabling of :end leads to :cabled; the link, to :linked', [
                'end' => self::named($end),
                'cabled' => self::named($cabled),
                'linked' => $linked === null ? '—' : self::named($linked),
            ]),
        ];
    }

    /**
     * @param  Unplugged  $unplugged
     * @return Deviation
     */
    private function unpluggedDeviation(array $unplugged): array
    {
        $deviation = [
            'kind' => DeviationKind::LinkedWithoutPlug->value,
            'reason' => __(':port is linked to :opposite and patched to :jack, where nothing is plugged', [
                'port' => self::named($unplugged['port']),
                'opposite' => self::named($unplugged['opposite']),
                'jack' => $unplugged['jack']->label,
            ]),
        ];

        if ($unplugged['pluggable']) {
            $deviation['action'] = [
                'type' => self::PLUG,
                'jack' => $unplugged['jack']->id,
                'port' => $unplugged['opposite']->id,
            ];
        }

        return $deviation;
    }
}
