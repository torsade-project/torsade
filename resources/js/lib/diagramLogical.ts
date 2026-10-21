// torsade/resources/js/lib/diagramLogical.ts
//
// From the logical diagram of a site, as the server works it out, to the
// graph ELK lays out, then from the graph laid out to what the page draws;
// and the same diagram placed by hand. Its nodes are the internet, the
// subnets drawn as bars and the devices, each on the row of its rank from
// the top down. A connection carries the addresses of its device, written
// near the device.
import type { ElkExtendedEdge, ElkNode, ElkPoint } from 'elkjs/lib/elk-api';
import { FONTS, ROOT_OPTIONS, SIZES } from '@/lib/diagram';
import type { Box, Measure } from '@/lib/diagram';
import {
    ceilToGrid,
    centre,
    facing,
    MANUAL,
    route,
    snap,
    union,
    vertical,
} from '@/lib/diagramManual';
import type { Position, Side } from '@/lib/diagramManual';
import type {
    LogicalConnection,
    LogicalDevice,
    LogicalDiagramData,
    LogicalSubnet,
} from '@/types';

// The sizes the logical drawing adds to the ones every diagram shares, in
// pixels.
export const LOGICAL = {
    // A subnet: its address, its name and its VLAN, then a line of
    // secondary text; as wide as the devices plugged into it need.
    barHeight: 56,
    barMinWidth: 280,
    barPaddingX: 14,
    barGap: 10,
    barPerConnection: 64,
    // The tag of a VLAN, on the first line of a bar.
    vlanHeight: 20,
    vlanPaddingX: 7,
    internetWidth: 128,
    internetHeight: 56,
    // The addresses of a connection, and the tag of the gateway.
    labelHeight: 20,
    labelPaddingX: 6,
    tagHeight: 16,
    tagPaddingX: 5,
    tagGap: 6,
    // Around the dot between the pieces of a line, and after the comma
    // between two addresses: an SVG text drops the spaces at its ends.
    space: 5,
    // Between the end of a connection and its addresses.
    labelOffset: 4,
} as const;

// The words the drawing needs, in the language of the page.
export type LogicalWords = {
    // The second line of a device: its type, a stack, planned, its site.
    device: (device: LogicalDevice) => string;
    // "VLAN 10 Bureautique".
    vlan: (vlan: { vid: number; name: string }) => string;
    // Before the gateway of a subnet, on its second line: "Gateway".
    gateway: string;
    // The tag of the gateway among the addresses of a connection.
    gatewayTag: string;
    planned: string;
};

// A piece of a line of text, from the start of the line.
export type Piece = {
    kind:
        | 'cidr'
        | 'name'
        | 'vlan'
        | 'text'
        | 'mono'
        | 'separator'
        | 'address'
        | 'gateway';
    text: string;
    x: number;
    width: number;
};

export type Line = { pieces: Piece[]; width: number };

// The font each piece is set in, and the room around its text.
const FONT_OF: Record<Piece['kind'], keyof typeof FONTS> = {
    cidr: 'cidr',
    name: 'name',
    vlan: 'tag',
    text: 'secondary',
    mono: 'address',
    separator: 'secondary',
    address: 'address',
    gateway: 'tag',
};

const PADDING_OF: Partial<Record<Piece['kind'], number>> = {
    vlan: LOGICAL.vlanPaddingX,
    gateway: LOGICAL.tagPaddingX,
};

// The pieces side by side, each one after the gap it asks for.
const line = (
    parts: { kind: Piece['kind']; text: string; gap?: number }[],
    measure: Measure,
): Line => {
    const pieces: Piece[] = [];
    let x = 0;

    for (const part of parts) {
        x += pieces.length === 0 ? 0 : (part.gap ?? 0);

        const width =
            measure(part.text, FONT_OF[part.kind]) +
            (PADDING_OF[part.kind] ?? 0) * 2;

        pieces.push({ kind: part.kind, text: part.text, x, width });
        x += width;
    }

    return { pieces, width: x };
};

// The two lines of a bar: its address, its name and its VLAN; then its
// kind, its gateway, how many other addresses it has, and whether it is
// planned.
export const barLines = (
    subnet: LogicalSubnet,
    measure: Measure,
    words: LogicalWords,
): { first: Line; second: Line } => {
    const first = line(
        [
            { kind: 'cidr', text: subnet.cidr },
            ...(subnet.name === null
                ? []
                : [
                      {
                          kind: 'name' as const,
                          text: subnet.name,
                          gap: LOGICAL.barGap,
                      },
                  ]),
            ...(subnet.vlan === null
                ? []
                : [
                      {
                          kind: 'vlan' as const,
                          text: words.vlan(subnet.vlan),
                          gap: LOGICAL.barGap,
                      },
                  ]),
        ],
        measure,
    );

    const segments: { kind: Piece['kind']; text: string; gap?: number }[][] = [
        [{ kind: 'text', text: subnet.kind_label }],
        ...(subnet.gateway === null
            ? []
            : [
                  [
                      { kind: 'text' as const, text: words.gateway },
                      {
                          kind: 'mono' as const,
                          text: subnet.gateway,
                          gap: LOGICAL.space,
                      },
                  ],
              ]),
        ...(subnet.others_label === null
            ? []
            : [[{ kind: 'text' as const, text: subnet.others_label }]]),
        ...(subnet.planned
            ? [[{ kind: 'text' as const, text: words.planned }]]
            : []),
    ];

    const second = line(
        segments.flatMap((segment, index) =>
            index === 0
                ? segment
                : [
                      {
                          kind: 'separator' as const,
                          text: '·',
                          gap: LOGICAL.space,
                      },
                      { ...segment[0], gap: LOGICAL.space },
                      ...segment.slice(1),
                  ],
        ),
        measure,
    );

    return { first, second };
};

// The addresses of a device in a subnet, the gateway tagged; null without
// any address.
export const connectionLabel = (
    connection: LogicalConnection,
    measure: Measure,
    words: LogicalWords,
): Line | null => {
    if (connection.addresses.length === 0) {
        return null;
    }

    return line(
        connection.addresses.flatMap((address, index) => [
            ...(index === 0 ? [] : [{ kind: 'separator' as const, text: ',' }]),
            {
                kind: 'address' as const,
                text: address.address,
                gap: index === 0 ? 0 : LOGICAL.space,
            },
            ...(address.gateway
                ? [
                      {
                          kind: 'gateway' as const,
                          text: words.gatewayTag,
                          gap: LOGICAL.tagGap,
                      },
                  ]
                : []),
        ]),
        measure,
    );
};

export type LogicalNode =
    | { kind: 'internet'; key: string; rank: number }
    | { kind: 'subnet'; key: string; rank: number; subnet: LogicalSubnet }
    | { kind: 'device'; key: string; rank: number; device: LogicalDevice };

// The nodes of the diagram in the order the server gives: the internet,
// the subnets by address, the devices by name.
export const nodesOf = (data: LogicalDiagramData): LogicalNode[] => [
    ...(data.internet === null
        ? []
        : [
              {
                  kind: 'internet' as const,
                  key: data.internet.key,
                  rank: data.internet.rank,
              },
          ]),
    ...data.subnets.map((subnet) => ({
        kind: 'subnet' as const,
        key: subnet.key,
        rank: subnet.rank,
        subnet,
    })),
    ...data.devices.map((device) => ({
        kind: 'device' as const,
        key: device.key,
        rank: device.rank,
        device,
    })),
];

// The size of a node, from the texts it shows.
const sizeOf = (
    node: LogicalNode,
    data: LogicalDiagramData,
    measure: Measure,
    words: LogicalWords,
): { width: number; height: number } => {
    if (node.kind === 'internet') {
        return { width: LOGICAL.internetWidth, height: LOGICAL.internetHeight };
    }

    if (node.kind === 'subnet') {
        const { first, second } = barLines(node.subnet, measure, words);
        const plugged = data.connections.filter(
            (connection) => connection.subnet === node.key,
        ).length;

        return {
            width: Math.max(
                LOGICAL.barMinWidth,
                Math.max(first.width, second.width) +
                    LOGICAL.barPaddingX * 2 +
                    (node.subnet.deviations.length > 0
                        ? SIZES.deviationIcon
                        : 0),
                plugged * LOGICAL.barPerConnection,
            ),
            height: LOGICAL.barHeight,
        };
    }

    return {
        width: Math.max(
            SIZES.deviceMinWidth,
            Math.max(
                measure(node.device.name, 'name'),
                measure(words.device(node.device), 'secondary'),
            ) +
                SIZES.devicePaddingX * 2 +
                (node.device.deviations.length > 0 ? SIZES.deviationIcon : 0),
        ),
        height: SIZES.deviceHeight,
    };
};

// What ELK is asked for, besides what every diagram asks: each node on the
// row of its rank, and room for the addresses on the connections.
const LOGICAL_OPTIONS: Record<string, string> = {
    ...ROOT_OPTIONS,
    'elk.partitioning.activate': 'true',
    'elk.spacing.nodeNode': '40',
    'elk.layered.spacing.nodeNodeBetweenLayers': '72',
};

// The graph of the diagram. The addresses of a connection are written at
// the end of its device.
export const buildLogicalGraph = (
    data: LogicalDiagramData,
    measure: Measure,
    words: LogicalWords,
): ElkNode => {
    const children: ElkNode[] = nodesOf(data).map((node) => ({
        id: node.key,
        ...sizeOf(node, data, measure, words),
        layoutOptions: { 'elk.partitioning.partition': String(node.rank) },
    }));

    const edges: ElkExtendedEdge[] = data.connections.map((connection) => {
        const label = connectionLabel(connection, measure, words);

        return {
            id: connection.key,
            sources: [connection.source],
            targets: [connection.target],
            labels:
                label === null
                    ? []
                    : [
                          {
                              id: `${connection.key}-label`,
                              text: label.pieces
                                  .map((piece) => piece.text)
                                  .join(''),
                              width: label.width + LOGICAL.labelPaddingX * 2,
                              height: LOGICAL.labelHeight,
                              layoutOptions: {
                                  'elk.edgeLabels.placement':
                                      connection.device === connection.target
                                          ? 'HEAD'
                                          : 'TAIL',
                              },
                          },
                      ],
        };
    });

    return { id: 'root', layoutOptions: LOGICAL_OPTIONS, children, edges };
};

export type LaidNode = Box & { node: LogicalNode };

export type LaidConnection = {
    connection: LogicalConnection;
    points: ElkPoint[];
    label: Box | null;
};

export type LaidLogical = {
    // Where the drawing starts: the origin of the automatic layout, the
    // top left of what is placed by hand.
    x: number;
    y: number;
    width: number;
    height: number;
    // In the order a reader goes through them: top to bottom, then left to
    // right.
    nodes: LaidNode[];
    connections: LaidConnection[];
};

const box = (shape: {
    x?: number;
    y?: number;
    width?: number;
    height?: number;
}): Box => ({
    x: shape.x ?? 0,
    y: shape.y ?? 0,
    width: shape.width ?? 0,
    height: shape.height ?? 0,
});

const byReading = (a: Box, b: Box): number => a.y - b.y || a.x - b.x;

// What the page draws, read from the graph ELK laid out.
export const readLogicalLayout = (
    laid: ElkNode,
    data: LogicalDiagramData,
): LaidLogical => {
    const nodes = new Map(nodesOf(data).map((node) => [node.key, node]));
    const connections = new Map(
        data.connections.map((connection) => [connection.key, connection]),
    );

    const result: LaidLogical = {
        x: 0,
        y: 0,
        width: laid.width ?? 0,
        height: laid.height ?? 0,
        nodes: [],
        connections: [],
    };

    for (const child of laid.children ?? []) {
        const node = nodes.get(child.id);

        if (node !== undefined) {
            result.nodes.push({ ...box(child), node });
        }
    }

    for (const edge of laid.edges ?? []) {
        const connection = connections.get(edge.id);
        const section = (edge as ElkExtendedEdge).sections?.[0];

        if (connection === undefined || section === undefined) {
            continue;
        }

        const label = edge.labels?.[0];

        result.connections.push({
            connection,
            points: [
                section.startPoint,
                ...(section.bendPoints ?? []),
                section.endPoint,
            ],
            label: label === undefined ? null : box(label),
        });
    }

    result.nodes.sort(byReading);

    return result;
};

export type ManualLogical = LaidLogical & {
    // The nodes without a position of their own, on the row below.
    unplaced: Set<string>;
};

// The addresses of a connection beside its end on a node, on the side the
// connection leaves from, clear of its line.
const labelBeside = (
    at: ElkPoint,
    side: Side,
    size: { width: number; height: number },
): Box => {
    const gap = LOGICAL.labelOffset;

    if (side === 'left') {
        return {
            x: at.x - size.width - gap,
            y: at.y - size.height - gap,
            ...size,
        };
    }

    return {
        x: at.x + gap,
        y: side === 'bottom' ? at.y + gap : at.y - size.height - gap,
        ...size,
    };
};

/**
 * The diagram laid out by hand. The automatic layout gives the size of
 * each node and of the addresses of each connection; the positions give
 * where each node placed sits, by its key on the diagram.
 */
export const manualLogicalLayout = (
    auto: LaidLogical,
    data: LogicalDiagramData,
    positions: Record<string, Position>,
): ManualLogical => {
    const sizes = new Map(auto.nodes.map((item) => [item.node.key, item]));
    const labelSizes = new Map(
        auto.connections.map((item) => [item.connection.key, item.label]),
    );
    const nodes = nodesOf(data);

    // --- Nodes ---------------------------------------------------------------

    const placed = nodes.filter((node) => positions[node.key] !== undefined);
    const unplaced = nodes.filter((node) => positions[node.key] === undefined);
    const boxes = new Map<string, Box>();

    for (const node of placed) {
        const size = sizes.get(node.key);
        const at = positions[node.key];

        boxes.set(node.key, {
            x: at.x,
            y: at.y,
            width: size?.width ?? SIZES.deviceMinWidth,
            height: size?.height ?? SIZES.deviceHeight,
        });
    }

    // The others on a row under the lowest node placed, from the left of
    // the leftmost one, in the order of the diagram.
    const above = union([...boxes.values()]);
    let x = snap(above?.x ?? 0);
    const y =
        above === null ? 0 : ceilToGrid(above.y + above.height + MANUAL.rowGap);

    for (const node of unplaced) {
        const size = sizes.get(node.key);
        const width = size?.width ?? SIZES.deviceMinWidth;

        boxes.set(node.key, {
            x,
            y,
            width,
            height: (size?.height ?? SIZES.deviceHeight) + MANUAL.unplacedTag,
        });
        x = ceilToGrid(x + width + MANUAL.rowSpacing);
    }

    // --- Ends of the connections ----------------------------------------------

    // Each end of each connection, on the side of its node that faces the
    // other end, spread along that side in the order of what they face.
    type End = {
        connection: string;
        node: string;
        side: Side;
        towards: ElkPoint;
    };

    const ends: End[] = [];

    for (const connection of data.connections) {
        const source = boxes.get(connection.source);
        const target = boxes.get(connection.target);

        if (source === undefined || target === undefined) {
            continue;
        }

        ends.push(
            {
                connection: connection.key,
                node: connection.source,
                side: facing(source, centre(target)),
                towards: centre(target),
            },
            {
                connection: connection.key,
                node: connection.target,
                side: facing(target, centre(source)),
                towards: centre(source),
            },
        );
    }

    const points = new Map<string, { at: ElkPoint; side: Side }>();

    for (const [key, around] of boxes) {
        const own = ends.filter((end) => end.node === key);

        for (const side of ['top', 'bottom', 'left', 'right'] as Side[]) {
            const along = own
                .filter((end) => end.side === side)
                .sort((a, b) =>
                    vertical(side)
                        ? a.towards.x - b.towards.x
                        : a.towards.y - b.towards.y,
                );

            along.forEach((end, index) => {
                const share = (index + 1) / (along.length + 1);
                // The tag of a node not placed is not part of its shape.
                const height =
                    around.height -
                    (positions[key] === undefined ? MANUAL.unplacedTag : 0);

                points.set(`${end.connection}:${key}`, {
                    side,
                    at: vertical(side)
                        ? {
                              x: around.x + around.width * share,
                              y: side === 'top' ? around.y : around.y + height,
                          }
                        : {
                              x:
                                  side === 'left'
                                      ? around.x
                                      : around.x + around.width,
                              y: around.y + height * share,
                          },
                });
            });
        }
    }

    // --- Connections ------------------------------------------------------------

    const connections: LaidConnection[] = data.connections.flatMap(
        (connection) => {
            const from = points.get(`${connection.key}:${connection.source}`);
            const to = points.get(`${connection.key}:${connection.target}`);

            if (from === undefined || to === undefined) {
                return [];
            }

            const size = labelSizes.get(connection.key) ?? null;
            const near = connection.device === connection.target ? to : from;

            return [
                {
                    connection,
                    points: route(from.at, from.side, to.at, to.side),
                    label:
                        size === null
                            ? null
                            : labelBeside(near.at, near.side, {
                                  width: size.width,
                                  height: size.height,
                              }),
                },
            ];
        },
    );

    // --- Bounds ----------------------------------------------------------------

    const laidNodes: LaidNode[] = nodes
        .map((node) => ({ ...(boxes.get(node.key) as Box), node }))
        .sort(byReading);

    const bounds = union([
        ...laidNodes,
        ...connections.flatMap((item) => [
            ...item.points.map((point) => ({ ...point, width: 0, height: 0 })),
            ...(item.label === null ? [] : [item.label]),
        ]),
    ]) ?? { x: 0, y: 0, width: 0, height: 0 };

    return {
        ...bounds,
        nodes: laidNodes,
        connections,
        unplaced: new Set(unplaced.map((node) => node.key)),
    };
};
