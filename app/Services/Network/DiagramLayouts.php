<?php

// torsade/app/Services/Network/DiagramLayouts.php

namespace App\Services\Network;

use App\Enums\ActivityEvent;
use App\Enums\DiagramNode;
use App\Enums\DiagramView;
use App\Models\DiagramPosition;
use App\Models\Site;
use App\Services\Network\Concerns\RetriesCrossedWrites;
use App\Support\Actor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The layout of a diagram of a site placed by hand: where each node sits, a
 * device; on the logical diagram, a subnet or the internet; on the physical
 * diagram, a patch panel. A diagram without any position is laid out on
 * its own; with one or more, each node placed keeps its place and the
 * others line up below.
 *
 * A node is named by its key on the diagram: "device-12", "subnet-4",
 * "patch_panel-3", or "internet".
 *
 * A layout is saved all at once, or not at all, and only over the one it
 * was read at: each reading comes with a version, which another save, or a
 * return to the automatic layout, changes.
 */
final class DiagramLayouts
{
    use RetriesCrossedWrites;

    public function __construct(
        private readonly PhysicalDiagram $diagrams,
        private readonly LogicalDiagram $logical,
        private readonly ChangeLog $log,
    ) {}

    /**
     * The positions of the nodes drawn on the diagram, by their key, and
     * the version of the layout. The positions of nodes no longer drawn
     * are left out.
     *
     * @param  list<string>  $drawn  The keys of the nodes drawn on the diagram.
     * @return array{positions: list<array{node: string, x: int, y: int}>, version: string}
     */
    public function ofNodes(Site $site, DiagramView $view, array $drawn): array
    {
        $rows = $this->rows($site, $view);
        $kept = array_flip($drawn);
        $positions = [];

        foreach ($rows as $row) {
            $key = self::keyOf($row);

            if (isset($kept[$key])) {
                $positions[] = ['node' => $key, 'x' => $row->x, 'y' => $row->y];
            }
        }

        return ['positions' => $positions, 'version' => self::version($rows)];
    }

    /**
     * Save the layout of the diagram: the position of each node, all of
     * them at once. It is refused when the layout changed since it was
     * read, or when a node is not drawn on the diagram. The positions of
     * nodes no longer drawn go.
     *
     * @param  array<string, array{x: int, y: int}>  $positions  By the key of the node.
     * @return int How many nodes are placed.
     *
     * @throws ValidationException
     */
    public function saveNodes(Site $site, DiagramView $view, array $positions, string $version, Actor $actor): int
    {
        return $this->replayingCrossedWrites(function () use ($site, $view, $positions, $version, $actor): int {
            $this->refuseAnOutdated($site, $view, $version);

            if (array_diff_key($positions, array_flip($this->drawn($site, $view))) !== []) {
                throw ValidationException::withMessages(['positions' => __('Some of these elements are not drawn on this diagram any more: reload the page.')]);
            }

            DiagramPosition::query()->where('site_id', $site->id)->where('view', $view->value)->delete();

            $now = now();
            $rows = [];

            foreach ($positions as $key => $position) {
                [$node, $id] = self::parse($key);

                $rows[] = [
                    'site_id' => $site->id, 'view' => $view->value, 'node' => $node->value,
                    'device_id' => $node === DiagramNode::Device ? $id : null,
                    'subnet_id' => $node === DiagramNode::Subnet ? $id : null,
                    'patch_panel_id' => $node === DiagramNode::PatchPanel ? $id : null,
                    'x' => $position['x'], 'y' => $position['y'], 'created_at' => $now, 'updated_at' => $now,
                ];
            }

            // One query for every position, however many nodes there are.
            DiagramPosition::query()->insert($rows);

            $this->log->noted(ActivityEvent::SiteDiagramSaved, $site, [], ['diagram' => $view->value, ...self::counts($view, array_keys($positions))], $actor);

            return count($positions);
        }, 'positions');
    }

    /**
     * Give the diagram back to the automatic layout: its positions go.
     *
     * @return int How many nodes were placed.
     *
     * @throws ValidationException
     */
    public function reset(Site $site, DiagramView $view, Actor $actor): int
    {
        return $this->replayingCrossedWrites(function () use ($site, $view, $actor): int {
            $this->lockTheSite($site);

            $keys = array_values($this->rows($site, $view)->map(fn (DiagramPosition $position): string => self::keyOf($position))->all());
            $count = DiagramPosition::query()->where('site_id', $site->id)->where('view', $view->value)->delete();

            if ($count > 0) {
                $this->log->noted(ActivityEvent::SiteDiagramReset, $site, ['diagram' => $view->value, ...self::counts($view, $keys)], [], $actor);
            }

            return $count;
        }, 'positions');
    }

    /**
     * The keys of the nodes drawn on a diagram of the site.
     *
     * @return list<string>
     */
    private function drawn(Site $site, DiagramView $view): array
    {
        if ($view === DiagramView::Physical) {
            return self::physicalNodes($this->diagrams->of($site));
        }

        return LogicalDiagram::nodes($this->logical->of($site));
    }

    /**
     * The keys of the nodes of a physical diagram: its devices and its
     * panels.
     *
     * @param  array{devices: list<array{key: string}>, panels: list<array{key: string}>}  $diagram
     * @return list<string>
     */
    public static function physicalNodes(array $diagram): array
    {
        return [...array_column($diagram['devices'], 'key'), ...array_column($diagram['panels'], 'key')];
    }

    /**
     * What the activity log counts of the nodes placed: the elements; on
     * the physical diagram, the devices and the panels among them as well.
     *
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    private static function counts(DiagramView $view, array $keys): array
    {
        if ($view === DiagramView::Logical) {
            return ['nodes_count' => count($keys)];
        }

        $panels = count(array_filter($keys, fn (string $key): bool => self::parse($key)[0] === DiagramNode::PatchPanel));

        return ['nodes_count' => count($keys), 'devices_count' => count($keys) - $panels, 'panels_count' => $panels];
    }

    /**
     * The layout read with the page must still be the one stored. The site
     * is locked first: two saves of its diagrams follow one another, and
     * the second one sees what the first one wrote.
     *
     * @throws ValidationException
     */
    private function refuseAnOutdated(Site $site, DiagramView $view, string $version): void
    {
        $this->lockTheSite($site);

        if (! hash_equals(self::version($this->rows($site, $view)), $version)) {
            throw ValidationException::withMessages(['version' => __('The layout has been changed in the meantime: reload the page.')]);
        }
    }

    private function lockTheSite(Site $site): void
    {
        Site::query()->whereKey($site->id)->lockForUpdate()->first();
    }

    /**
     * @return Collection<int, DiagramPosition>
     */
    private function rows(Site $site, DiagramView $view): Collection
    {
        return DiagramPosition::query()
            ->where('site_id', $site->id)
            ->where('view', $view->value)
            ->orderBy('node')
            ->orderBy('device_id')
            ->orderBy('subnet_id')
            ->orderBy('patch_panel_id')
            ->get();
    }

    /**
     * The key of a node on a diagram: "device-12", "subnet-4",
     * "patch_panel-3", "internet".
     */
    private static function key(DiagramNode $node, ?int $id = null): string
    {
        return $node === DiagramNode::Internet ? $node->value : $node->value.'-'.$id;
    }

    private static function keyOf(DiagramPosition $position): string
    {
        return self::key($position->node, $position->device_id ?? $position->subnet_id ?? $position->patch_panel_id);
    }

    /**
     * The node a key names, and its identifier.
     *
     * @return array{0: DiagramNode, 1: int|null}
     */
    private static function parse(string $key): array
    {
        if ($key === DiagramNode::Internet->value) {
            return [DiagramNode::Internet, null];
        }

        [$node, $id] = explode('-', $key, 2);

        return [DiagramNode::from($node), (int) $id];
    }

    /**
     * The version of a layout: a digest of every position stored, so that
     * any change to one of them, any position added or taken away, gives
     * another version. A device is written by its identifier alone, as
     * before the other nodes could be placed.
     *
     * @param  Collection<int, DiagramPosition>  $rows
     */
    private static function version(Collection $rows): string
    {
        return hash('sha256', $rows->map(fn (DiagramPosition $position): string => ($position->node === DiagramNode::Device ? (string) $position->device_id : self::keyOf($position)).':'.$position->x.':'.$position->y)->implode(';'));
    }
}
