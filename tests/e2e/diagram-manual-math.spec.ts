// torsade/tests/e2e/diagram-manual-math.spec.ts
// No page is opened here: the plain test, without the fixtures that watch a
// browser.
import { expect, test } from '@playwright/test';
import { listedDeviations, withoutEndDevices } from '@/lib/diagram';
import type { LaidDiagram } from '@/lib/diagram';
import {
    facing,
    GRID,
    LIMIT,
    MANUAL,
    manualLayout,
    route,
    snap,
} from '@/lib/diagramManual';
import type {
    DiagramDevice,
    DiagramLink,
    DiagramPanel,
    PhysicalDiagramData,
} from '@/types';

// The layout placed by hand, worked out without a browser: positions on the
// grid, the row of the devices not placed, ports on the side that faces the
// other end, links in right angles, frames around their devices and their
// panels. Then what the page shows without the end devices, and the
// deviations it lists.

const device = (
    name: string,
    place: string | null,
    ports: string[],
): DiagramDevice => ({
    key: `device-${name}`,
    id: name.length,
    name,
    type: 'switch',
    type_label: 'Switch',
    end_device: false,
    planned: false,
    site: null,
    members: 1,
    place,
    ports: ports.map((port) => ({ key: `port-${name}-${port}`, name: port })),
    deviations: [],
});

const link = (
    from: string,
    fromPort: string,
    to: string,
    toPort: string,
    label: string | null = null,
): DiagramLink => ({
    key: `link-${from}-${to}`,
    connection: `link-${from}-${to}`,
    segment: 'direct',
    source: `device-${from}`,
    source_port: `port-${from}-${fromPort}`,
    target: `device-${to}`,
    target_port: `port-${to}-${toPort}`,
    ends: [`${from} › ${fromPort}`, `${to} › ${toPort}`],
    label,
    jack: null,
    through: null,
    paths: [],
    links_count: 1,
    medium: null,
    medium_label: null,
    fibre: false,
    planned: false,
    deviations: [],
});

// A firewall above two switches in a room, a third switch in another room
// of the same floor, and an access point without a room.
const data: PhysicalDiagramData = {
    site: { id: 1, name: 'Siège', code: 'SIE' },
    devices: [
        device('fw', 'room-1', ['p1', 'p2']),
        device('sw-a', 'room-1', ['1', '2']),
        device('sw-b', 'room-1', ['1']),
        device('sw-c', 'room-2', ['1']),
        device('ap', 'unplaced', ['1']),
    ],
    panels: [],
    places: [
        {
            key: 'building-1',
            kind: 'building',
            name: 'Bâtiment',
            technical: false,
            children: [
                {
                    key: 'floor-1',
                    kind: 'floor',
                    name: 'Rez-de-chaussée',
                    technical: false,
                    children: [
                        {
                            key: 'room-1',
                            kind: 'room',
                            name: 'Local technique',
                            technical: true,
                            children: [],
                        },
                        {
                            key: 'room-2',
                            kind: 'room',
                            name: 'Bureaux',
                            technical: false,
                            children: [],
                        },
                    ],
                },
            ],
        },
        {
            key: 'unplaced',
            kind: 'unplaced',
            name: 'Sans local',
            technical: false,
            children: [],
        },
    ],
    links: [
        link('fw', 'p1', 'sw-a', '1', 'Po1 ↔ Po1 · 2 liens'),
        link('fw', 'p2', 'sw-b', '1'),
        link('sw-a', '2', 'ap', '1'),
    ],
    summary: {
        devices: 5,
        end_devices: 0,
        undrawn: 0,
        links: 3,
        deviations: 0,
    },
};

// What the automatic layout gave: only the sizes matter here.
const auto: LaidDiagram = {
    x: 0,
    y: 0,
    width: 600,
    height: 400,
    places: [],
    devices: data.devices.map((one, index) => ({
        x: index * 200,
        y: 0,
        width: 160,
        height: 50,
        device: one,
        ports: one.ports.map((port) => ({
            key: port.key,
            name: port.name,
            x: 0,
            y: 0,
            width: 10,
            height: 10,
            label: { x: 0, y: 0, width: 30, height: 14 },
        })),
    })),
    panels: [],
    links: [
        {
            link: data.links[0],
            points: [],
            middle: { x: 0, y: 0 },
            label: { x: 0, y: 0, width: 120, height: 18 },
        },
    ],
};

const title = (): number => 100;

// --- The cabling ---------------------------------------------------------------

// A segment of a cabling, through the port 12 of the panel PB-B.
const segment = (
    connection: string,
    kind: 'rack' | 'office',
    from: [string, string | null],
    to: [string, string | null],
    extra: Partial<DiagramLink> = {},
): DiagramLink => ({
    key: `${connection}-${kind}`,
    connection,
    segment: kind,
    source: from[0],
    source_port: from[1],
    target: to[0],
    target_port: to[1],
    ends: ['sw › 1', 'pc'],
    label: null,
    jack: kind === 'office' ? 'B.12' : null,
    through: 'PB-B 12 › B.12',
    paths: [],
    links_count: 0,
    medium: null,
    medium_label: null,
    fibre: false,
    planned: false,
    deviations: [],
    ...extra,
});

const endDevice = (name: string, ports: string[]): DiagramDevice => ({
    ...device(name, 'room-2', ports),
    type: 'workstation',
    type_label: 'Poste fixe',
    end_device: true,
});

const panel: DiagramPanel = {
    key: 'patch_panel-1',
    id: 1,
    name: 'PB-B',
    planned: false,
    place: 'room-1',
    ports: [
        { key: 'panel-port-12', name: '12' },
        { key: 'panel-port-13', name: '13' },
    ],
};

// A switch cabled through the panel to a workstation by its address, and
// to an access point by a jack cord; the access point linked to a
// telephone.
const cabled: PhysicalDiagramData = {
    ...data,
    devices: [
        device('sw', 'room-1', ['1', '2']),
        endDevice('pc', []),
        device('ap', 'room-2', ['eth0', 'eth1']),
        endDevice('phone', ['lan']),
    ],
    panels: [panel],
    links: [
        segment(
            'addressed-12',
            'rack',
            ['device-sw', 'port-sw-1'],
            ['patch_panel-1', 'panel-port-12'],
        ),
        segment(
            'addressed-12',
            'office',
            ['patch_panel-1', 'panel-port-12'],
            ['device-pc', null],
            { key: 'addressed-12-office-1' },
        ),
        segment(
            'cabling-13',
            'rack',
            ['device-sw', 'port-sw-2'],
            ['patch_panel-1', 'panel-port-13'],
            {
                deviations: [
                    { kind: 'cabling_without_link', reason: 'Sans liaison' },
                ],
            },
        ),
        segment(
            'cabling-13',
            'office',
            ['patch_panel-1', 'panel-port-13'],
            ['device-ap', 'port-ap-eth0'],
        ),
        link('ap', 'eth1', 'phone', 'lan'),
    ],
    summary: {
        devices: 2,
        end_devices: 2,
        undrawn: 0,
        links: 1,
        deviations: 1,
    },
};

// Every segment of a line, horizontal or vertical.
const rightAngles = (points: { x: number; y: number }[]): boolean =>
    points
        .slice(1)
        .every(
            (point, index) =>
                point.x === points[index].x || point.y === points[index].y,
        );

test('a position sits on the grid of 8, within its bounds', () => {
    expect(GRID).toBe(8);
    expect([snap(0), snap(3), snap(4), snap(13), snap(-13), snap(-4)]).toEqual([
        0, 0, 8, 16, -16, -0,
    ]);
    expect(snap(LIMIT + 100)).toBe(LIMIT);
    expect(snap(-LIMIT - 100)).toBe(-LIMIT);
});

test('a port is on the side of its device that faces the other end', () => {
    const box = { x: 0, y: 0, width: 160, height: 50 };

    expect(facing(box, { x: 80, y: 300 })).toBe('bottom');
    expect(facing(box, { x: 80, y: -300 })).toBe('top');
    expect(facing(box, { x: 600, y: 30 })).toBe('right');
    expect(facing(box, { x: -600, y: 30 })).toBe('left');
    // Along the diagonal of the box, its shape decides.
    expect(facing(box, { x: 80 + 160, y: 25 + 40 })).toBe('right');
    expect(facing(box, { x: 80 + 100, y: 25 + 60 })).toBe('bottom');
});

test('a link goes in right angles, out of each port by a short straight part', () => {
    for (const [from, fromSide, to, toSide] of [
        [{ x: 0, y: 0 }, 'bottom', { x: 200, y: 300 }, 'top'],
        [{ x: 0, y: 0 }, 'right', { x: 400, y: 120 }, 'left'],
        [{ x: 0, y: 0 }, 'bottom', { x: 300, y: 200 }, 'left'],
        [{ x: 0, y: 0 }, 'right', { x: 300, y: 200 }, 'top'],
        [{ x: 0, y: 0 }, 'bottom', { x: 0, y: 300 }, 'top'],
    ] as const) {
        const points = route(from, fromSide, to, toSide);

        expect(points[0]).toEqual(from);
        expect(points.at(-1)).toEqual(to);
        expect(rightAngles(points)).toBe(true);
        // No point twice in a row, no point in the middle of a straight
        // line.
        expect(points.length).toBeGreaterThanOrEqual(2);
    }

    // Straight down, it is a single segment.
    expect(route({ x: 0, y: 0 }, 'bottom', { x: 0, y: 300 }, 'top')).toEqual([
        { x: 0, y: 0 },
        { x: 0, y: 300 },
    ]);
    // Out of the bottom of one, into the top of the other, across halfway.
    expect(route({ x: 0, y: 0 }, 'bottom', { x: 200, y: 300 }, 'top')).toEqual([
        { x: 0, y: 0 },
        { x: 0, y: 150 },
        { x: 200, y: 150 },
        { x: 200, y: 300 },
    ]);
});

test('the devices placed keep their place, the others line up below them, by name', () => {
    const laid = manualLayout(
        auto,
        data,
        {
            'device-fw': { x: 160, y: 0 },
            'device-sw-a': { x: 0, y: 160 },
            'device-sw-c': { x: 400, y: 160 },
        },
        true,
        title,
    );
    const at = Object.fromEntries(
        laid.devices.map((item) => [item.device.name, item]),
    );

    expect([at.fw.x, at.fw.y, at['sw-a'].x, at['sw-a'].y]).toEqual([
        160, 0, 0, 160,
    ]);

    // The row: under the lowest device placed, from the left of the
    // leftmost one, on the grid, by name; each marked and a little taller.
    const row = Math.ceil((160 + 50 + MANUAL.rowGap) / GRID) * GRID;

    expect([...laid.unplaced].sort()).toEqual(['device-ap', 'device-sw-b']);
    expect([at.ap.y, at['sw-b'].y]).toEqual([row, row]);
    expect(at.ap.x).toBe(0);
    expect(at['sw-b'].x).toBeGreaterThanOrEqual(at.ap.x + 160);
    expect(at['sw-b'].x % GRID).toBe(0);
    expect(at.ap.height).toBe(50 + MANUAL.unplacedTag);
    expect(at.fw.height).toBe(50);

    // In the order of reading.
    expect(laid.devices.map((item) => item.device.name)).toEqual([
        'fw',
        'sw-a',
        'sw-c',
        'ap',
        'sw-b',
    ]);
});

test('without any device placed, all of them line up from the origin', () => {
    const laid = manualLayout(auto, data, {}, true, title);

    expect(laid.unplaced.size).toBe(5);
    expect(new Set(laid.devices.map((item) => item.y))).toEqual(new Set([0]));
    expect(Math.min(...laid.devices.map((item) => item.x))).toBe(0);
});

test('each port faces the other end of its link, and each link goes in right angles from port to port', () => {
    const laid = manualLayout(
        auto,
        data,
        {
            'device-fw': { x: 160, y: 0 },
            'device-sw-a': { x: 0, y: 200 },
            'device-sw-b': { x: 400, y: 200 },
            'device-sw-c': { x: 800, y: 200 },
            'device-ap': { x: -400, y: 200 },
        },
        true,
        title,
    );
    const at = Object.fromEntries(
        laid.devices.map((item) => [item.device.name, item]),
    );
    const port = (name: string, key: string) =>
        at[name].ports.find((one) => one.key === key);

    // The firewall above: its ports at its bottom, those of the switches
    // at their top; the access point on the left of the first switch.
    expect(port('fw', 'port-fw-p1')?.y).toBe(0 + 50 - 5);
    expect(port('sw-a', 'port-sw-a-1')?.y).toBe(200 - 5);
    expect(port('sw-a', 'port-sw-a-2')?.x).toBe(0 - 5);
    expect(port('ap', 'port-ap-1')?.x).toBe(-400 + 160 - 5);

    // The two ports of the firewall in the order of what they face.
    expect(port('fw', 'port-fw-p1')?.x).toBeLessThan(
        port('fw', 'port-fw-p2')?.x ?? 0,
    );

    for (const line of laid.links) {
        const from = line.points[0];
        const to = line.points.at(-1);
        const source = at[line.link.source.replace('device-', '')].ports.find(
            (one) => one.key === line.link.source_port,
        );

        expect(rightAngles(line.points)).toBe(true);
        expect(from).toEqual({
            x: (source?.x ?? 0) + 5,
            y: (source?.y ?? 0) + 5,
        });
        expect(to).toBeDefined();
    }

    // The name of an aggregated link, centred halfway along it.
    const named = laid.links.find((line) => line.link.label !== null);

    expect(named?.label?.width).toBe(120);
    expect((named?.label?.x ?? 0) + 60).toBeCloseTo(named?.middle.x ?? 0);
});

test('the frames surround the devices they hold, nested, and go when the places are not grouped', () => {
    const positions = {
        'device-fw': { x: 160, y: 0 },
        'device-sw-a': { x: 0, y: 200 },
        'device-sw-b': { x: 400, y: 200 },
        'device-sw-c': { x: 800, y: 200 },
        'device-ap': { x: -400, y: 200 },
    };
    const laid = manualLayout(auto, data, positions, true, title);
    const frame = Object.fromEntries(
        laid.places.map((place) => [place.place.key, place]),
    );
    const inside = (
        outer: { x: number; y: number; width: number; height: number },
        inner: { x: number; y: number; width: number; height: number },
    ): boolean =>
        inner.x >= outer.x &&
        inner.y >= outer.y &&
        inner.x + inner.width <= outer.x + outer.width &&
        inner.y + inner.height <= outer.y + outer.height;

    expect(Object.keys(frame).sort()).toEqual([
        'building-1',
        'floor-1',
        'room-1',
        'room-2',
        'unplaced',
    ]);

    for (const item of laid.devices) {
        expect(inside(frame[item.device.place ?? ''], item)).toBe(true);
    }

    expect(inside(frame['floor-1'], frame['room-1'])).toBe(true);
    expect(inside(frame['floor-1'], frame['room-2'])).toBe(true);
    expect(inside(frame['building-1'], frame['floor-1'])).toBe(true);
    expect(frame['building-1'].depth).toBe(0);
    expect(frame['room-1'].depth).toBe(2);

    // Not grouped: no frame, and not a device moves.
    const flat = manualLayout(auto, data, positions, false, title);

    expect(flat.places).toEqual([]);
    expect(
        flat.devices.map((item) => [item.device.name, item.x, item.y]),
    ).toEqual(laid.devices.map((item) => [item.device.name, item.x, item.y]));

    // The drawing starts at the top left of what is drawn.
    expect(laid.x).toBeLessThanOrEqual(-400);
    expect(laid.y).toBeLessThan(0);
});

test('a panel is placed as a device, its port takes both segments once, and a device cabled by its address is reached on its edge', () => {
    const laidCabling: LaidDiagram = {
        ...auto,
        devices: cabled.devices.map((one, index) => ({
            x: index * 200,
            y: 0,
            width: 120,
            height: one.end_device ? 40 : 50,
            device: one,
            ports: [],
        })),
        panels: [
            {
                x: 0,
                y: 0,
                width: 96,
                height: 40,
                panel,
                ports: [],
            },
        ],
        // The size of the jack, as the automatic layout measured it.
        links: [
            {
                link: cabled.links[1],
                points: [],
                middle: { x: 0, y: 0 },
                label: { x: 0, y: 0, width: 40, height: 18 },
            },
        ],
    };

    const laid = manualLayout(
        laidCabling,
        cabled,
        {
            'device-sw': { x: 0, y: 0 },
            'patch_panel-1': { x: 0, y: 160 },
            'device-pc': { x: 400, y: 200 },
        },
        false,
        title,
    );

    // The panel keeps its place; the access point and the telephone line up
    // below, not placed.
    expect(laid.panels.map((one) => [one.panel.key, one.x, one.y])).toEqual([
        ['patch_panel-1', 0, 160],
    ]);
    expect([...laid.unplaced].sort()).toEqual(['device-ap', 'device-phone']);

    // The port 12 of the panel is placed once, on the side that faces the
    // middle of the switch above and of the workstation on the right: its
    // right side.
    const port12 = laid.panels[0].ports.filter(
        (port) => port.key === 'panel-port-12',
    );

    expect(port12).toHaveLength(1);
    expect(port12[0].x).toBe(96 - 5);

    // The segment to the workstation ends in the middle of the edge that
    // faces the panel, its left one.
    const office = laid.links.find(
        (one) => one.link.key === 'addressed-12-office-1',
    );

    expect(office?.points.at(-1)).toEqual({ x: 400, y: 220 });
    expect(rightAngles(office?.points ?? [])).toBe(true);

    // The jack is the label of the office segment, halfway along it.
    expect(office?.label?.width).toBe(40);
});

test('without the end devices, their connections go, and each device joined to some of them tells how many', () => {
    const { diagram, counts } = withoutEndDevices(cabled);

    expect(diagram.devices.map((one) => one.key)).toEqual([
        'device-sw',
        'device-ap',
    ]);
    // The cabling to the access point stays; the one to the workstation
    // goes with the port 12 of the panel, and the link to the telephone
    // with the port of the access point.
    expect(diagram.links.map((one) => one.key)).toEqual([
        'cabling-13-rack',
        'cabling-13-office',
    ]);
    expect(diagram.panels[0].ports.map((port) => port.name)).toEqual(['13']);
    expect(diagram.devices[0].ports.map((port) => port.name)).toEqual(['2']);
    expect(diagram.devices[1].ports.map((port) => port.name)).toEqual(['eth0']);
    expect(counts).toEqual({ 'device-sw': 1, 'device-ap': 1 });

    // A panel that only served end devices goes as well.
    const alone = withoutEndDevices({
        ...cabled,
        links: cabled.links.slice(0, 2),
    });

    expect(alone.diagram.panels).toEqual([]);
    expect(alone.counts).toEqual({ 'device-sw': 1 });

    // Without end devices, nothing changes.
    expect(withoutEndDevices(data).diagram).toBe(data);
});

test('a cabling that tells what the direct line of its link tells is listed once', () => {
    const contradiction = {
        kind: 'link_contradicted_by_cabling',
        reason: 'Le câblage de sw 1 mène à pc ; la liaison, à ap',
    };
    const lines = [
        link('sw', '1', 'ap', 'eth0'),
        segment(
            'cabling-12',
            'rack',
            ['device-sw', 'port-sw-1'],
            ['patch_panel-1', 'panel-port-12'],
        ),
    ];

    lines[0].deviations = [contradiction];
    lines[1].deviations = [
        contradiction,
        { kind: 'cabling_without_link', reason: 'Autre' },
    ];

    expect(
        listedDeviations(lines).map((item) => [
            item.link.key,
            item.deviations.map((one) => one.kind),
        ]),
    ).toEqual([
        ['link-sw-ap', ['link_contradicted_by_cabling']],
        ['cabling-12-rack', ['cabling_without_link']],
    ]);
});
