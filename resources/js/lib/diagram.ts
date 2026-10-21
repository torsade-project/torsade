// torsade/resources/js/lib/diagram.ts
//
// From the physical diagram of a site, as the server works it out, to the
// graph ELK lays out, then from the graph laid out to what the page draws.
// ELK knows nothing of text: the boxes are given the size of what they
// will show, measured here with the fonts of the page.
import type {
    ElkExtendedEdge,
    ElkLabel,
    ElkNode,
    ElkPoint,
} from 'elkjs/lib/elk-api';
import type {
    DiagramDevice,
    DiagramLink,
    DiagramPanel,
    DiagramPlace,
    PhysicalDiagramData,
} from '@/types';

// The sizes the drawing and the layout share, in pixels.
export const SIZES = {
    // A device: its name, then a line of secondary text.
    deviceHeight: 50,
    deviceMinWidth: 128,
    devicePaddingX: 12,
    // An end device, smaller, and a patch panel, lower than a device.
    endDeviceHeight: 40,
    endDeviceMinWidth: 96,
    panelHeight: 40,
    panelMinWidth: 96,
    // The warning drawn in the corner of a device that has a deviation.
    deviationIcon: 22,
    // The badge beside the name of a device that tells how many end
    // devices are hidden.
    badgeHeight: 16,
    badgePaddingX: 6,
    badgeGap: 8,
    port: 10,
    portLabelHeight: 14,
    // A frame: its title above what it holds.
    framePaddingTop: 34,
    framePadding: 14,
    edgeLabelHeight: 18,
    edgeLabelPaddingX: 8,
    // Between the title of a frame and the tag of a technical room.
    frameTagGap: 8,
} as const;

// The text the boxes hold, as the drawing sets it: a weight, a size, and
// the family of the theme, its interface one or its technical one.
export const FONTS = {
    name: { weight: 600, size: 14, family: 'font-sans' },
    frame: { weight: 500, size: 12, family: 'font-sans' },
    tag: { weight: 600, size: 12, family: 'font-sans' },
    secondary: { weight: 400, size: 12, family: 'font-sans' },
    port: { weight: 400, size: 11, family: 'font-mono' },
    label: { weight: 400, size: 12, family: 'font-sans' },
    // An end device and a patch panel, smaller; the jack of a cabling.
    smallName: { weight: 600, size: 13, family: 'font-sans' },
    smallSecondary: { weight: 400, size: 11, family: 'font-sans' },
    panel: { weight: 600, size: 13, family: 'font-mono' },
    jack: { weight: 400, size: 11, family: 'font-mono' },
    // The logical diagram: the address of a subnet, and an address.
    cidr: { weight: 600, size: 14, family: 'font-mono' },
    address: { weight: 400, size: 12, family: 'font-mono' },
} as const;

export type Measure = (text: string, font: keyof typeof FONTS) => number;

// The family a class of the theme gives, as the page works it out: the
// drawing takes its fonts from these classes, the measures must take the
// same ones.
const themeFamily = (className: string): string => {
    const probe = document.createElement('span');

    probe.className = className;
    document.body.append(probe);

    const family = getComputedStyle(probe).fontFamily;

    probe.remove();

    return family;
};

// The fonts of the drawing, loaded before they are measured: a weight the
// page has not shown yet would be measured in another font, and the same
// data would not always give the same diagram.
export const loadFonts = async (): Promise<void> => {
    const families = {
        'font-sans': themeFamily('font-sans'),
        'font-mono': themeFamily('font-mono'),
    };

    await Promise.all(
        Object.values(FONTS).map(({ weight, size, family }) =>
            document.fonts
                .load(`${weight} ${size}px ${families[family]}`)
                .catch(() => []),
        ),
    );
    await document.fonts.ready;
};

// The width of a text in a font, measured by the browser.
export const canvasMeasure = (): Measure => {
    const context = document.createElement('canvas').getContext('2d');
    const families = {
        'font-sans': themeFamily('font-sans'),
        'font-mono': themeFamily('font-mono'),
    };

    return (text, font) => {
        if (context === null) {
            return text.length * 8;
        }

        const { weight, size, family } = FONTS[font];

        context.font = `${weight} ${size}px ${families[family]}`;

        return Math.ceil(context.measureText(text).width);
    };
};

// What ELK is asked for: layers from top to bottom, links drawn at right
// angles from the ports, the frames of the places nested, every position
// relative to the whole diagram, and the order the server gives kept as far
// as it can be, so that the same data gives the same diagram.
export const ROOT_OPTIONS: Record<string, string> = {
    'elk.algorithm': 'layered',
    'elk.direction': 'DOWN',
    'elk.edgeRouting': 'ORTHOGONAL',
    'elk.hierarchyHandling': 'INCLUDE_CHILDREN',
    'elk.json.shapeCoords': 'ROOT',
    'elk.json.edgeCoords': 'ROOT',
    'elk.layered.considerModelOrder.strategy': 'NODES_AND_EDGES',
    'elk.spacing.nodeNode': '32',
    'elk.layered.spacing.nodeNodeBetweenLayers': '56',
    'elk.spacing.edgeNode': '20',
    'elk.spacing.edgeEdge': '12',
    'elk.edgeLabels.placement': 'CENTER',
    'elk.padding': '[top=16,left=16,bottom=16,right=16]',
};

// A frame is at least as wide as its title.
const frameOptions = (width: number): Record<string, string> => ({
    'elk.padding': `[top=${SIZES.framePaddingTop},left=${SIZES.framePadding},bottom=${SIZES.framePadding},right=${SIZES.framePadding}]`,
    'elk.nodeSize.constraints': '[MINIMUM_SIZE]',
    'elk.nodeSize.minimum': `(${width}, ${SIZES.framePaddingTop + SIZES.framePadding})`,
});

// A port sits across the edge of its node, half in, half out, its name
// beside it. The node grows when its ports need more room than its text,
// so that two names never overlap.
const nodeOptions = (
    width: number,
    height: number,
): Record<string, string> => ({
    'elk.portConstraints': 'FREE',
    'elk.portLabels.placement': 'OUTSIDE',
    'elk.port.borderOffset': String(-SIZES.port / 2),
    'elk.spacing.portPort': '14',
    'elk.spacing.labelPortHorizontal': '3',
    'elk.nodeSize.constraints': '[PORTS, PORT_LABELS, MINIMUM_SIZE]',
    'elk.nodeSize.minimum': `(${width}, ${height})`,
});

// How high a device is drawn, and how wide at least: an end device is
// smaller.
export const deviceShape = (
    device: DiagramDevice,
): { height: number; minWidth: number } =>
    device.end_device
        ? { height: SIZES.endDeviceHeight, minWidth: SIZES.endDeviceMinWidth }
        : { height: SIZES.deviceHeight, minWidth: SIZES.deviceMinWidth };

// The ports of a node as ELK places them, each with its name.
const portsOf = (
    ports: { key: string; name: string }[],
    measure: Measure,
): ElkNode['ports'] =>
    ports.map((port) => ({
        id: port.key,
        width: SIZES.port,
        height: SIZES.port,
        labels: [
            {
                id: `${port.key}-label`,
                text: port.name,
                width: measure(port.name, 'port'),
                height: SIZES.portLabelHeight,
            },
        ],
    }));

// The label of a line: the aggregates of an aggregated link, or the jack
// of the segment from a panel to the device plugged in.
export const lineLabel = (
    link: DiagramLink,
): { text: string; font: 'label' | 'jack' } | null => {
    if (link.label !== null) {
        return { text: link.label, font: 'label' };
    }

    return link.jack === null ? null : { text: link.jack, font: 'jack' };
};

// How wide the badge of a text is drawn.
export const badgeWidth = (text: string, measure: Measure): number =>
    measure(text, 'smallSecondary') + SIZES.badgePaddingX * 2;

// The graph of the diagram, its devices and its panels in the frames of
// their places when they are grouped by place, side by side otherwise. The
// texts are given as they will be drawn, to be measured: the second line of
// a device and its badge, the word under the name of a panel, and the tag
// of a technical room.
export const buildGraph = (
    data: PhysicalDiagramData,
    grouped: boolean,
    measure: Measure,
    texts: {
        secondary: (device: DiagramDevice) => string;
        badge?: (device: DiagramDevice) => string | null;
        technical: string;
        panel: string;
    },
): ElkNode => {
    const { secondary } = texts;

    const deviceNode = (device: DiagramDevice): ElkNode => {
        const shape = deviceShape(device);
        const small = device.end_device;
        const badge = texts.badge?.(device) ?? null;
        const width = Math.max(
            shape.minWidth,
            Math.max(
                measure(device.name, small ? 'smallName' : 'name') +
                    (badge === null
                        ? 0
                        : SIZES.badgeGap + badgeWidth(badge, measure)),
                measure(
                    secondary(device),
                    small ? 'smallSecondary' : 'secondary',
                ),
            ) +
                SIZES.devicePaddingX * 2 +
                (device.deviations.length > 0 ? SIZES.deviationIcon : 0),
        );

        return {
            id: device.key,
            width,
            height: shape.height,
            layoutOptions: nodeOptions(width, shape.height),
            ports: portsOf(device.ports, measure),
        };
    };

    const panelNode = (panel: DiagramPanel): ElkNode => {
        const width = Math.max(
            SIZES.panelMinWidth,
            Math.max(
                measure(panel.name, 'panel'),
                measure(texts.panel, 'smallSecondary'),
            ) +
                SIZES.devicePaddingX * 2,
        );

        return {
            id: panel.key,
            width,
            height: SIZES.panelHeight,
            layoutOptions: nodeOptions(width, SIZES.panelHeight),
            ports: portsOf(panel.ports, measure),
        };
    };

    const nodesIn = (key: string): ElkNode[] => [
        ...data.devices
            .filter((device) => device.place === key)
            .map(deviceNode),
        ...data.panels.filter((panel) => panel.place === key).map(panelNode),
    ];

    const placeNode = (place: DiagramPlace): ElkNode => ({
        id: place.key,
        layoutOptions: frameOptions(
            measure(place.name, 'frame') +
                (place.technical
                    ? SIZES.frameTagGap + measure(texts.technical, 'tag')
                    : 0) +
                SIZES.framePadding * 2,
        ),
        children: [...place.children.map(placeNode), ...nodesIn(place.key)],
    });

    const children = grouped
        ? [
              ...data.places.map(placeNode),
              // The devices of other sites, at the edge.
              ...data.devices
                  .filter((device) => device.place === null)
                  .map(deviceNode),
          ]
        : [...data.devices.map(deviceNode), ...data.panels.map(panelNode)];

    // A line leaves from a port, or from the edge of a device cabled by its
    // address.
    const edges: ElkExtendedEdge[] = data.links.map((link) => {
        const label = lineLabel(link);

        return {
            id: link.key,
            sources: [link.source_port ?? link.source],
            targets: [link.target_port ?? link.target],
            labels:
                label === null
                    ? []
                    : [
                          {
                              id: `${link.key}-label`,
                              text: label.text,
                              width:
                                  measure(label.text, label.font) +
                                  SIZES.edgeLabelPaddingX * 2,
                              height: SIZES.edgeLabelHeight,
                          },
                      ],
        };
    });

    return { id: 'root', layoutOptions: ROOT_OPTIONS, children, edges };
};

export type Box = { x: number; y: number; width: number; height: number };

export type LaidPlace = Box & { place: DiagramPlace; depth: number };

export type LaidPort = Box & { key: string; name: string; label: Box };

export type LaidDevice = Box & { device: DiagramDevice; ports: LaidPort[] };

export type LaidPanel = Box & { panel: DiagramPanel; ports: LaidPort[] };

export type LaidLink = {
    link: DiagramLink;
    points: ElkPoint[];
    // Halfway along the link: where its warning goes.
    middle: ElkPoint;
    label: Box | null;
};

export type LaidDiagram = {
    // Where the drawing starts: the origin of the automatic layout, the
    // top left of what is placed by hand.
    x: number;
    y: number;
    width: number;
    height: number;
    places: LaidPlace[];
    // In the order a reader goes through them: top to bottom, then left to
    // right.
    devices: LaidDevice[];
    panels: LaidPanel[];
    links: LaidLink[];
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

// The point halfway along a line of segments.
export const halfway = (points: ElkPoint[]): ElkPoint => {
    const lengths = points
        .slice(1)
        .map((point, index) =>
            Math.hypot(point.x - points[index].x, point.y - points[index].y),
        );
    let left = lengths.reduce((sum, length) => sum + length, 0) / 2;

    for (const [index, length] of lengths.entries()) {
        if (left <= length && length > 0) {
            const from = points[index];
            const to = points[index + 1];

            return {
                x: from.x + ((to.x - from.x) * left) / length,
                y: from.y + ((to.y - from.y) * left) / length,
            };
        }

        left -= length;
    }

    return points[0] ?? { x: 0, y: 0 };
};

const laidPorts = (node: ElkNode): LaidPort[] =>
    (node.ports ?? []).map((port) => {
        const label: ElkLabel | undefined = port.labels?.[0];

        return {
            ...box(port),
            key: port.id,
            name: label?.text ?? '',
            label: box(label ?? {}),
        };
    });

// What the page draws, read from the graph ELK laid out: every position is
// relative to the whole diagram.
export const readLayout = (
    laid: ElkNode,
    data: PhysicalDiagramData,
): LaidDiagram => {
    const places = new Map<string, DiagramPlace>();
    const flatten = (place: DiagramPlace): void => {
        places.set(place.key, place);
        place.children.forEach(flatten);
    };

    data.places.forEach(flatten);

    const devices = new Map(data.devices.map((device) => [device.key, device]));
    const panels = new Map(data.panels.map((panel) => [panel.key, panel]));
    const links = new Map(data.links.map((link) => [link.key, link]));

    const result: LaidDiagram = {
        x: 0,
        y: 0,
        width: laid.width ?? 0,
        height: laid.height ?? 0,
        places: [],
        devices: [],
        panels: [],
        links: [],
    };

    const walk = (node: ElkNode, depth: number): void => {
        const place = places.get(node.id);
        const device = devices.get(node.id);
        const panel = panels.get(node.id);

        if (place !== undefined) {
            result.places.push({ ...box(node), place, depth });
        }

        if (device !== undefined) {
            result.devices.push({
                ...box(node),
                device,
                ports: laidPorts(node),
            });
        }

        if (panel !== undefined) {
            result.panels.push({ ...box(node), panel, ports: laidPorts(node) });
        }

        (node.children ?? []).forEach((child) => walk(child, depth + 1));
    };

    (laid.children ?? []).forEach((child) => walk(child, 0));

    for (const edge of laid.edges ?? []) {
        const link = links.get(edge.id);
        const section = (edge as ElkExtendedEdge).sections?.[0];

        if (link === undefined || section === undefined) {
            continue;
        }

        const points = [
            section.startPoint,
            ...(section.bendPoints ?? []),
            section.endPoint,
        ];
        const label = edge.labels?.[0];

        result.links.push({
            link,
            points,
            middle: halfway(points),
            label: label === undefined ? null : box(label),
        });
    }

    result.devices.sort((a, b) => a.y - b.y || a.x - b.x);
    result.panels.sort((a, b) => a.y - b.y || a.x - b.x);

    return result;
};

// The diagram without its end devices, as the page shows it when they are
// hidden: they go with their connections, the panels and the ports that
// only served them as well, and each device drawn that was joined to some
// of them tells how many, by its key.
export const withoutEndDevices = (
    data: PhysicalDiagramData,
): { diagram: PhysicalDiagramData; counts: Record<string, number> } => {
    const hidden = new Set(
        data.devices
            .filter((device) => device.end_device)
            .map((device) => device.key),
    );

    if (hidden.size === 0) {
        return { diagram: data, counts: {} };
    }

    const panelKeys = new Set(data.panels.map((panel) => panel.key));
    const joined = new Map<string, Set<string>>();
    const join = (shown: string, end: string): void => {
        if (!joined.has(shown)) {
            joined.set(shown, new Set());
        }

        joined.get(shown)?.add(end);
    };

    // The end of a segment that is a device, not a panel.
    const deviceEnd = (link: DiagramLink): string =>
        panelKeys.has(link.source) ? link.target : link.source;

    const connections = new Map<string, DiagramLink[]>();

    for (const link of data.links) {
        connections.set(link.connection, [
            ...(connections.get(link.connection) ?? []),
            link,
        ]);
    }

    const kept: DiagramLink[] = [];

    for (const lines of connections.values()) {
        const rack = lines.find((line) => line.segment === 'rack');

        if (rack === undefined) {
            // Direct lines, from one device to the other.
            for (const line of lines) {
                const ends = [line.source, line.target];
                const gone = ends.filter((end) => hidden.has(end));

                if (gone.length === 0) {
                    kept.push(line);

                    continue;
                }

                for (const end of ends) {
                    if (!hidden.has(end)) {
                        gone.forEach((other) => join(end, other));
                    }
                }
            }

            continue;
        }

        const patched = deviceEnd(rack);
        const offices = lines.filter((line) => line.segment === 'office');

        if (hidden.has(patched)) {
            for (const office of offices) {
                const plugged = deviceEnd(office);

                if (!hidden.has(plugged)) {
                    join(plugged, patched);
                }
            }

            continue;
        }

        const shown = offices.filter(
            (office) => !hidden.has(deviceEnd(office)),
        );

        offices
            .filter((office) => hidden.has(deviceEnd(office)))
            .forEach((office) => join(patched, deviceEnd(office)));

        if (shown.length > 0) {
            kept.push(rack, ...shown);
        }
    }

    // The ports still used, of the devices and of the panels.
    const used = new Set(
        kept.flatMap((line) =>
            [line.source_port, line.target_port].filter(
                (port): port is string => port !== null,
            ),
        ),
    );

    return {
        diagram: {
            ...data,
            devices: data.devices
                .filter((device) => !hidden.has(device.key))
                .map((device) => ({
                    ...device,
                    ports: device.ports.filter((port) => used.has(port.key)),
                })),
            panels: data.panels
                .map((panel) => ({
                    ...panel,
                    ports: panel.ports.filter((port) => used.has(port.key)),
                }))
                .filter((panel) => panel.ports.length > 0),
            links: kept,
        },
        counts: Object.fromEntries(
            [...joined].map(([key, ends]) => [key, ends.size]),
        ),
    };
};

// The deviations the lines of a diagram tell, each line with those it
// adds: a cabling that contradicts a link tells what the direct line of
// the link already tells, and only that line lists it. The server counts
// them the same way.
export const listedDeviations = (
    links: DiagramLink[],
): { link: DiagramLink; deviations: DiagramLink['deviations'] }[] => {
    const told = new Set(
        links
            .filter((link) => link.segment === 'direct')
            .flatMap((link) =>
                link.deviations.map(
                    (deviation) => `${deviation.kind}|${deviation.reason}`,
                ),
            ),
    );

    return links
        .map((link) => ({
            link,
            deviations:
                link.segment === 'direct'
                    ? link.deviations
                    : link.deviations.filter(
                          (deviation) =>
                              !told.has(
                                  `${deviation.kind}|${deviation.reason}`,
                              ),
                      ),
        }))
        .filter((item) => item.deviations.length > 0);
};
