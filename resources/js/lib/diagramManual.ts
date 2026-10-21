// torsade/resources/js/lib/diagramManual.ts
//
// The layout placed by hand: each device or panel placed keeps its
// position, the others line up on a row below, marked as not placed. ELK
// cannot route links between positions it is given, so the links are drawn
// here, in simple right angles from one port to the other, each port on the
// side of its node that faces the other end; a line to a device cabled by
// its address ends on the middle of that side. The frames of the places
// are the rectangles around the nodes they hold.
import type { ElkPoint } from 'elkjs/lib/elk-api';
import { deviceShape, halfway, lineLabel, SIZES } from '@/lib/diagram';
import type {
    Box,
    LaidDevice,
    LaidDiagram,
    LaidLink,
    LaidPanel,
    LaidPlace,
    LaidPort,
} from '@/lib/diagram';
import type { DiagramPlace, PhysicalDiagramData } from '@/types';

// The step of the grid every position sits on.
export const GRID = 8;

// The bounds of a position, either way.
export const LIMIT = 1_000_000;

export type Position = { x: number; y: number };

// The nearest point of the grid, within the bounds.
export const snap = (value: number): number =>
    Math.max(-LIMIT, Math.min(LIMIT, Math.round(value / GRID) * GRID));

// The next point of the grid at or after a value.
export const ceilToGrid = (value: number): number =>
    Math.ceil(value / GRID) * GRID;

// The sizes the manual layout adds to the ones the drawing shares.
export const MANUAL = {
    // Between the lowest device placed and the row of the others, and
    // between two devices of that row.
    rowGap: 64,
    rowSpacing: 32,
    // The tag of a device not placed, under its type.
    unplacedTag: 18,
    // The straight part of a link as it leaves its port.
    stub: 16,
    // Between a port and its name.
    portLabelGap: 3,
} as const;

export type Side = 'top' | 'bottom' | 'left' | 'right';

export const vertical = (side: Side): boolean =>
    side === 'top' || side === 'bottom';

// The way out of a side.
const NORMALS: Record<Side, ElkPoint> = {
    top: { x: 0, y: -1 },
    bottom: { x: 0, y: 1 },
    left: { x: -1, y: 0 },
    right: { x: 1, y: 0 },
};

export const centre = (box: Box): ElkPoint => ({
    x: box.x + box.width / 2,
    y: box.y + box.height / 2,
});

// The side of a box a line towards a point leaves from: the one it
// crosses, by the shape of the box.
export const facing = (box: Box, towards: ElkPoint): Side => {
    const from = centre(box);
    const dx = towards.x - from.x;
    const dy = towards.y - from.y;

    if (dx === 0 && dy === 0) {
        return 'bottom';
    }

    if (Math.abs(dx) * box.height > Math.abs(dy) * box.width) {
        return dx > 0 ? 'right' : 'left';
    }

    return dy > 0 ? 'bottom' : 'top';
};

// Right angles only, without a point that repeats another or that lies on
// a straight line between its two neighbours.
const tidy = (points: ElkPoint[]): ElkPoint[] => {
    const kept: ElkPoint[] = [];

    for (const point of points) {
        const last = kept.at(-1);

        if (last !== undefined && last.x === point.x && last.y === point.y) {
            continue;
        }

        const before = kept.at(-2);

        if (
            last !== undefined &&
            before !== undefined &&
            ((before.x === last.x && last.x === point.x) ||
                (before.y === last.y && last.y === point.y))
        ) {
            kept[kept.length - 1] = point;

            continue;
        }

        kept.push(point);
    }

    return kept;
};

// From one port to the other, in right angles: out of each port by a short
// straight part, then across.
export const route = (
    from: ElkPoint,
    fromSide: Side,
    to: ElkPoint,
    toSide: Side,
): ElkPoint[] => {
    const out = {
        x: from.x + NORMALS[fromSide].x * MANUAL.stub,
        y: from.y + NORMALS[fromSide].y * MANUAL.stub,
    };
    const into = {
        x: to.x + NORMALS[toSide].x * MANUAL.stub,
        y: to.y + NORMALS[toSide].y * MANUAL.stub,
    };
    let across: ElkPoint[];

    if (vertical(fromSide) && vertical(toSide)) {
        const middle = (out.y + into.y) / 2;

        across = [
            { x: out.x, y: middle },
            { x: into.x, y: middle },
        ];
    } else if (!vertical(fromSide) && !vertical(toSide)) {
        const middle = (out.x + into.x) / 2;

        across = [
            { x: middle, y: out.y },
            { x: middle, y: into.y },
        ];
    } else if (vertical(fromSide)) {
        across = [{ x: out.x, y: into.y }];
    } else {
        across = [{ x: into.x, y: out.y }];
    }

    return tidy([from, out, ...across, into, to]);
};

export const union = (boxes: Box[]): Box | null => {
    if (boxes.length === 0) {
        return null;
    }

    const left = Math.min(...boxes.map((box) => box.x));
    const top = Math.min(...boxes.map((box) => box.y));
    const right = Math.max(...boxes.map((box) => box.x + box.width));
    const bottom = Math.max(...boxes.map((box) => box.y + box.height));

    return { x: left, y: top, width: right - left, height: bottom - top };
};

export type ManualDiagram = LaidDiagram & {
    // The nodes without a position of their own, on the row below.
    unplaced: Set<string>;
};

// A node placed by hand: a device or a panel, with the size the automatic
// layout gave it, or the one it is drawn at.
type Node = { key: string; name: string; width: number; height: number };

/**
 * The diagram laid out by hand. The automatic layout gives the size of
 * each device and panel and of the names of its ports and its links; the
 * positions give where each node placed sits, by its key on the diagram.
 *
 * @param titleWidth How wide a frame is at least, for its title to fit.
 */
export const manualLayout = (
    auto: LaidDiagram,
    data: PhysicalDiagramData,
    positions: Record<string, Position>,
    grouped: boolean,
    titleWidth: (place: DiagramPlace) => number,
): ManualDiagram => {
    const sizes = new Map<string, Box>([
        ...auto.devices.map((item) => [item.device.key, item] as const),
        ...auto.panels.map((item) => [item.panel.key, item] as const),
    ]);
    const labels = new Map(
        [...auto.devices, ...auto.panels].flatMap((item) =>
            item.ports.map((port) => [port.key, port.label] as const),
        ),
    );
    const linkLabels = new Map(
        auto.links.map((link) => [link.link.key, link.label]),
    );

    // --- Nodes ---------------------------------------------------------------

    const nodes: Node[] = [
        ...data.devices.map((device) => {
            const shape = deviceShape(device);

            return {
                key: device.key,
                name: device.name,
                width: sizes.get(device.key)?.width ?? shape.minWidth,
                height: sizes.get(device.key)?.height ?? shape.height,
            };
        }),
        ...data.panels.map((panel) => ({
            key: panel.key,
            name: panel.name,
            width: sizes.get(panel.key)?.width ?? SIZES.panelMinWidth,
            height: sizes.get(panel.key)?.height ?? SIZES.panelHeight,
        })),
    ];

    const placed = nodes.filter((node) => positions[node.key] !== undefined);
    const unplaced = nodes
        .filter((node) => positions[node.key] === undefined)
        .sort((a, b) =>
            a.name.localeCompare(b.name, undefined, { numeric: true }),
        );

    const boxes = new Map<string, Box>();

    for (const node of placed) {
        const at = positions[node.key];

        boxes.set(node.key, {
            x: at.x,
            y: at.y,
            width: node.width,
            height: node.height,
        });
    }

    // The others on a row under the lowest node placed, from the left of
    // the leftmost one, in the order of their names.
    const above = union([...boxes.values()]);
    let x = snap(above?.x ?? 0);
    const y =
        above === null ? 0 : ceilToGrid(above.y + above.height + MANUAL.rowGap);

    for (const node of unplaced) {
        boxes.set(node.key, {
            x,
            y,
            width: node.width,
            height: node.height + MANUAL.unplacedTag,
        });
        x = ceilToGrid(x + node.width + MANUAL.rowSpacing);
    }

    // --- Ports -----------------------------------------------------------------

    // Each end of each link, on the side of its node that faces the other
    // end; an end without a port is the edge of a device cabled by its
    // address, which the line reaches in the middle of that side.
    type End = { node: string; port: string; side: Side; towards: ElkPoint };

    const ends: End[] = [];
    const edgeOf = (key: string, line: string): string => `${key}@${line}`;

    for (const link of data.links) {
        const source = boxes.get(link.source);
        const target = boxes.get(link.target);

        if (source === undefined || target === undefined) {
            continue;
        }

        ends.push(
            {
                node: link.source,
                port: link.source_port ?? edgeOf(link.source, link.key),
                side: facing(source, centre(target)),
                towards: centre(target),
            },
            {
                node: link.target,
                port: link.target_port ?? edgeOf(link.target, link.key),
                side: facing(target, centre(source)),
                towards: centre(source),
            },
        );
    }

    const ports = new Map<string, LaidPort & { side: Side }>();
    const names = new Map(
        [...data.devices, ...data.panels].flatMap((node) =>
            node.ports.map((port) => [port.key, port.name] as const),
        ),
    );

    // Where a line reaches the edge of a device without a port.
    const edges = new Map<string, { point: ElkPoint; side: Side }>();

    for (const [key, box] of boxes) {
        // A port of a panel takes both segments of its cabling: it is placed
        // once, on the side that faces the middle of their other ends.
        const own: End[] = [];

        for (const end of ends) {
            if (
                end.node !== key ||
                !names.has(end.port) ||
                own.some((one) => one.port === end.port)
            ) {
                continue;
            }

            const all = ends.filter(
                (one) => one.node === key && one.port === end.port,
            );
            const towards = {
                x:
                    all.reduce((sum, one) => sum + one.towards.x, 0) /
                    all.length,
                y:
                    all.reduce((sum, one) => sum + one.towards.y, 0) /
                    all.length,
            };

            own.push({ ...end, side: facing(box, towards), towards });
        }

        for (const end of ends.filter(
            (one) => one.node === key && !names.has(one.port),
        )) {
            const middle = centre(box);
            const point = {
                top: { x: middle.x, y: box.y },
                bottom: { x: middle.x, y: box.y + box.height },
                left: { x: box.x, y: middle.y },
                right: { x: box.x + box.width, y: middle.y },
            }[end.side];

            edges.set(end.port, { point, side: end.side });
        }

        for (const side of ['top', 'bottom', 'left', 'right'] as Side[]) {
            // Along the side, in the order of what they face, so that their
            // links do not cross on the way out.
            const along = own
                .filter((end) => end.side === side)
                .sort((a, b) =>
                    vertical(side)
                        ? a.towards.x - b.towards.x
                        : a.towards.y - b.towards.y,
                );

            along.forEach((end, index) => {
                const share = (index + 1) / (along.length + 1);
                const half = SIZES.port / 2;
                const portX = vertical(side)
                    ? box.x + box.width * share - half
                    : side === 'left'
                      ? box.x - half
                      : box.x + box.width - half;
                const portY = vertical(side)
                    ? side === 'top'
                        ? box.y - half
                        : box.y + box.height - half
                    : box.y + box.height * share - half;
                const label = labels.get(end.port);
                const labelWidth = label?.width ?? 0;
                const labelHeight = label?.height ?? SIZES.portLabelHeight;
                const labelBox: Box = {
                    x:
                        side === 'left'
                            ? portX - labelWidth - MANUAL.portLabelGap
                            : portX + SIZES.port + MANUAL.portLabelGap,
                    y:
                        side === 'bottom'
                            ? portY + SIZES.port
                            : portY - labelHeight,
                    width: labelWidth,
                    height: labelHeight,
                };

                ports.set(end.port, {
                    key: end.port,
                    name: names.get(end.port) ?? '',
                    x: portX,
                    y: portY,
                    width: SIZES.port,
                    height: SIZES.port,
                    label: labelBox,
                    side,
                });
            });
        }
    }

    const laidPorts = (keys: { key: string }[]): LaidPort[] =>
        keys.flatMap((port) => {
            const laid = ports.get(port.key);

            return laid === undefined ? [] : [laid];
        });

    const devices: LaidDevice[] = data.devices
        .map((device) => ({
            ...(boxes.get(device.key) as Box),
            device,
            ports: laidPorts(device.ports),
        }))
        .sort((a, b) => a.y - b.y || a.x - b.x);

    const panels: LaidPanel[] = data.panels
        .map((panel) => ({
            ...(boxes.get(panel.key) as Box),
            panel,
            ports: laidPorts(panel.ports),
        }))
        .sort((a, b) => a.y - b.y || a.x - b.x);

    // --- Links -----------------------------------------------------------------

    // Where a line leaves: the centre of a port, or the edge of a device.
    const anchor = (
        port: string | null,
        node: string,
        line: string,
    ): { point: ElkPoint; side: Side } | undefined => {
        if (port !== null) {
            const laid = ports.get(port);

            return laid === undefined
                ? undefined
                : { point: centre(laid), side: laid.side };
        }

        return edges.get(edgeOf(node, line));
    };

    const links: LaidLink[] = data.links.flatMap((link) => {
        const from = anchor(link.source_port, link.source, link.key);
        const to = anchor(link.target_port, link.target, link.key);

        if (from === undefined || to === undefined) {
            return [];
        }

        const points = route(from.point, from.side, to.point, to.side);
        const middle = halfway(points);
        const size = linkLabels.get(link.key) ?? null;
        // The warning of a deviation sits halfway: the name of the link
        // goes under it.
        const shift = link.deviations.length > 0 ? 24 : 0;

        return [
            {
                link,
                points,
                middle,
                label:
                    size === null || lineLabel(link) === null
                        ? null
                        : {
                              x: middle.x - size.width / 2,
                              y: middle.y - size.height / 2 + shift,
                              width: size.width,
                              height: size.height,
                          },
            },
        ];
    });

    // --- Places ----------------------------------------------------------------

    const places: LaidPlace[] = [];

    // A frame around what a place holds: its own devices and panels, and
    // the frames of the places inside it. An empty place has no frame.
    const frame = (place: DiagramPlace, depth: number): Box | null => {
        const inside = [
            ...place.children.flatMap((child) => {
                const box = frame(child, depth + 1);

                return box === null ? [] : [box];
            }),
            ...[...data.devices, ...data.panels]
                .filter((node) => node.place === place.key)
                .map((node) => boxes.get(node.key) as Box),
        ];
        const around = union(inside);

        if (around === null) {
            return null;
        }

        const x = around.x - SIZES.framePadding;
        const box: Box = {
            x,
            y: around.y - SIZES.framePaddingTop,
            width: Math.max(
                around.width + SIZES.framePadding * 2,
                titleWidth(place),
            ),
            height: around.height + SIZES.framePaddingTop + SIZES.framePadding,
        };

        places.push({ ...box, place, depth });

        return box;
    };

    if (grouped) {
        data.places.forEach((place) => frame(place, 0));
    }

    // --- Bounds ----------------------------------------------------------------

    const bounds = union([
        ...devices,
        ...panels,
        ...[...ports.values()].flatMap((port) => [port, port.label]),
        ...places,
        ...links.flatMap((link) => [
            ...link.points.map((point) => ({ ...point, width: 0, height: 0 })),
            ...(link.label === null ? [] : [link.label]),
        ]),
    ]) ?? { x: 0, y: 0, width: 0, height: 0 };

    return {
        x: bounds.x,
        y: bounds.y,
        width: bounds.width,
        height: bounds.height,
        places,
        devices,
        panels,
        links,
        unplaced: new Set(unplaced.map((node) => node.key)),
    };
};
