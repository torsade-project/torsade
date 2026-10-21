<!-- torsade/resources/js/components/diagram/LogicalDiagram.vue -->
<script setup lang="ts">
import { computed, nextTick, onMounted, ref, shallowRef, watch } from 'vue';
import DiagramCanvas from '@/components/diagram/DiagramCanvas.vue';
import DiagramInternet from '@/components/diagram/DiagramInternet.vue';
import DiagramNode from '@/components/diagram/DiagramNode.vue';
import { useElkLayout } from '@/composables/useElkLayout';
import { useManualPlacing } from '@/composables/useManualPlacing';
import { canvasMeasure, SIZES } from '@/lib/diagram';
import type { Box, Measure } from '@/lib/diagram';
import {
    barLines,
    buildLogicalGraph,
    connectionLabel,
    LOGICAL,
    manualLogicalLayout,
    readLogicalLayout,
} from '@/lib/diagramLogical';
import type {
    LaidConnection,
    LaidLogical,
    LaidNode,
    Line,
    LogicalWords,
    ManualLogical,
} from '@/lib/diagramLogical';
import { snap } from '@/lib/diagramManual';
import type { Position } from '@/lib/diagramManual';
import { trans, transChoice } from '@/lib/i18n';
import { diagramFile, download } from '@/lib/svgExport';
import { show as showDevice } from '@/routes/devices';
import { show as showSubnet } from '@/routes/subnets';
import type { LogicalDevice, LogicalDiagramData, LogicalSubnet } from '@/types';

// The logical diagram of a site, laid out by ELK in a worker and drawn
// here, in SVG, with the colours of the theme, in the frame every diagram
// shares: the internet, the subnets as bars, and the devices plugged into
// them, each connection with the addresses of its device. Each subnet and
// each device is a link to its page, in the order of reading.
//
// Once a node is placed by hand, the diagram keeps the positions given and
// draws its connections itself. While the nodes are being placed, each
// one, the bars and the internet too, is moved with the mouse, a finger or
// the arrows, on a grid of 8.
const props = defineProps<{
    diagram: LogicalDiagramData;
    // Names what the diagram shows, for a screen reader.
    describedBy?: string;
    // Where nodes were placed by hand, by their key on the diagram; none
    // for the automatic layout.
    positions: Record<string, Position>;
    // Whether the nodes are being placed by hand.
    editing: boolean;
    // Tells how a node is moved from the keyboard, while they are.
    editHelp?: string;
}>();

const emit = defineEmits<{
    // Whether the diagram is drawn, for what acts on the drawing.
    ready: [ready: boolean];
    // Whether nodes were moved and not saved.
    dirty: [dirty: boolean];
}>();

const canvas = ref<InstanceType<typeof DiagramCanvas> | null>(null);

// --- Layout -----------------------------------------------------------------

// The layout ELK works out: the place of everything when nothing is placed
// by hand, the size of everything when something is.
const auto = shallowRef<LaidLogical | null>(null);

// The texts of the page, measured with its fonts.
let measure: Measure | null = null;

const elk = useElkLayout(() => {
    auto.value = null;
    emit('ready', false);
});

// The second line of a device: its type, then a stack, a device that is
// planned, or the site of a device of another site.
const secondary = (device: LogicalDevice): string =>
    [
        device.type_label,
        ...(device.members > 1
            ? [
                  transChoice(
                      'stack of :count member|stack of :count members',
                      device.members,
                  ),
              ]
            : []),
        ...(device.planned ? [trans('Planned')] : []),
        ...(device.site === null ? [] : [device.site.name]),
    ].join(' · ');

const words = (): LogicalWords => ({
    device: secondary,
    vlan: (vlan) => trans('VLAN :label', { label: `${vlan.vid} ${vlan.name}` }),
    gateway: trans('Gateway'),
    gatewayTag: trans('gateway'),
    planned: trans('Planned'),
});

const layOut = async (): Promise<void> => {
    emit('ready', false);

    const answer = await elk.layOut(() => {
        measure = canvasMeasure();

        return buildLogicalGraph(props.diagram, measure, words());
    });

    if (answer === null) {
        return;
    }

    auto.value = readLogicalLayout(answer, props.diagram);
    canvas.value?.fit();
    emit('ready', true);
};

onMounted(() => void layOut());

watch(
    () => props.diagram,
    () => void layOut(),
);

// --- Placed by hand ---------------------------------------------------------------

const placing = useManualPlacing({
    editing: () => props.editing,
    positions: () => props.positions,
    automatic: () =>
        (auto.value?.nodes ?? []).map((item) => ({
            key: item.node.key,
            x: item.x,
            y: item.y,
        })),
    drawn: () => (laid.value?.nodes ?? []).map((item) => item.node.key),
});

// What is drawn: the automatic layout, or the one placed by hand.
const laid = computed<LaidLogical | ManualLogical | null>(() => {
    if (auto.value === null || !placing.manual.value) {
        return auto.value;
    }

    return manualLogicalLayout(
        auto.value,
        props.diagram,
        placing.current.value,
    );
});

const bounds = computed<Box | null>(() =>
    laid.value === null
        ? null
        : {
              x: laid.value.x,
              y: laid.value.y,
              width: laid.value.width,
              height: laid.value.height,
          },
);

// The nodes without a position of their own, on the row below.
const unplaced = computed<Set<string>>(() => {
    const value = laid.value;

    return value !== null && 'unplaced' in value
        ? value.unplaced
        : new Set<string>();
});

watch(placing.dirty, (value) => emit('dirty', value));

// The nodes in the order they are drawn and reached from the keyboard.
const nodesDrawn = computed<LaidNode[]>(() =>
    placing.inOrder(laid.value?.nodes ?? [], (item) => item.node.key),
);

// The tag of a node not placed, as wide as its word.
const unplacedWidth = computed<number>(() => {
    void auto.value;

    return measure === null
        ? 64
        : Math.ceil(measure(trans('Not placed'), 'secondary')) + 12;
});

// --- Texts -----------------------------------------------------------------------

// The lines of each bar and the addresses of each connection, measured
// once the diagram is laid out.
const bars = computed<Map<string, { first: Line; second: Line }>>(() => {
    void auto.value;

    const measured = measure;

    return new Map(
        measured === null
            ? []
            : props.diagram.subnets.map((subnet) => [
                  subnet.key,
                  barLines(subnet, measured, words()),
              ]),
    );
});

const labels = computed<Map<string, Line>>(() => {
    void auto.value;

    const measured = measure;
    const lines = new Map<string, Line>();

    if (measured !== null) {
        for (const connection of props.diagram.connections) {
            const label = connectionLabel(connection, measured, words());

            if (label !== null) {
                lines.set(connection.key, label);
            }
        }
    }

    return lines;
});

const names = computed<Map<string, string>>(
    () =>
        new Map([
            ...props.diagram.subnets.map(
                (subnet) => [subnet.key, subnet.cidr] as const,
            ),
            ...props.diagram.devices.map(
                (device) => [device.key, device.name] as const,
            ),
        ]),
);

// --- Drawing -------------------------------------------------------------------

const points = (connection: LaidConnection): string =>
    connection.points.map((point) => `${point.x},${point.y}`).join(' ');

// The connections that carry addresses, written above every line.
const labelled = computed(() =>
    (laid.value?.connections ?? []).filter(
        (item) => item.label !== null && labels.value.has(item.connection.key),
    ),
);

const subnetTitle = (subnet: LogicalSubnet): string =>
    [
        [subnet.cidr, subnet.name].filter((part) => part !== null).join(' '),
        ...(subnet.vlan === null
            ? []
            : [
                  trans('VLAN :label', {
                      label: `${subnet.vlan.vid} ${subnet.vlan.name}`,
                  }),
              ]),
        subnet.kind_label,
        ...(subnet.gateway === null
            ? []
            : [`${trans('Gateway')} ${subnet.gateway}`]),
        ...(subnet.others_label === null ? [] : [subnet.others_label]),
        ...(subnet.planned ? [trans('Planned')] : []),
        ...subnet.deviations.map((deviation) => deviation.reason),
    ].join('\n');

const deviceTitle = (device: LogicalDevice): string =>
    [
        `${device.name} — ${secondary(device)}`,
        ...device.deviations.map((deviation) => deviation.reason),
    ].join('\n');

const connectionTitle = (item: LaidConnection): string => {
    const connection = item.connection;
    const subnet = names.value.get(connection.subnet) ?? '';

    return [
        `${connection.device === null ? trans('Internet') : (names.value.get(connection.device) ?? '')} — ${subnet}`,
        ...connection.addresses.map((address) =>
            address.gateway
                ? `${address.address} (${trans('gateway')})`
                : address.address,
        ),
        ...(connection.carried === null ? [] : [connection.carried]),
    ].join('\n');
};

// The classes of each piece of text, by its kind.
const TEXT_CLASSES: Record<string, string> = {
    cidr: 'fill-foreground font-mono text-[14px] font-semibold',
    name: 'fill-foreground font-sans text-[14px] font-semibold',
    text: 'fill-muted-foreground font-sans text-[12px]',
    separator: 'fill-muted-foreground font-sans text-[12px]',
    mono: 'fill-foreground font-mono text-[12px]',
    address: 'fill-foreground font-mono text-[12px]',
};

defineExpose({
    fit: (): void => canvas.value?.fit(),
    // Where every node drawn is, as it is saved: all of them at once.
    layout: (): { node: string; x: number; y: number }[] =>
        (laid.value?.nodes ?? []).map((item) => ({
            node: item.node.key,
            x: snap(item.x),
            y: snap(item.y),
        })),
    // The whole diagram, saved as an SVG file.
    save: async (fileName: string, title: string): Promise<void> => {
        await nextTick();

        const svg = canvas.value?.element() ?? null;

        if (svg !== null) {
            download(diagramFile(svg, title), fileName, 'image/svg+xml');
        }
    },
});
</script>

<template>
    <DiagramCanvas
        ref="canvas"
        :bounds="bounds"
        :working="elk.working.value"
        :failed="elk.failed.value"
        :editing="editing"
        :edit-help="editHelp"
        :described-by="describedBy"
        @move="placing.moveTo"
    >
        <template v-if="laid !== null">
            <!-- The connections, under the nodes. -->
            <g
                v-for="item in laid.connections"
                :key="item.connection.key"
                :data-connection="item.connection.key"
            >
                <title>{{ connectionTitle(item) }}</title>
                <!-- Wide and invisible: what the pointer finds. -->
                <polyline
                    :points="points(item)"
                    class="fill-none stroke-transparent"
                    stroke-width="12"
                    data-export-skip
                />
                <polyline
                    :points="points(item)"
                    class="fill-none stroke-border-strong"
                    stroke-width="1.25"
                    stroke-linejoin="round"
                />
            </g>

            <!-- The addresses of each device, near it, the gateway tagged. -->
            <g
                v-for="item in labelled"
                :key="`label-${item.connection.key}`"
                :data-label-of="
                    item.connection.device === null
                        ? undefined
                        : names.get(item.connection.device)
                "
                data-test="connection-label"
            >
                <title>{{ connectionTitle(item) }}</title>
                <rect
                    v-if="item.label !== null"
                    :x="item.label.x"
                    :y="item.label.y"
                    :width="item.label.width"
                    :height="item.label.height"
                    rx="4"
                    class="fill-card stroke-border"
                    stroke-width="1"
                />
                <template
                    v-for="(piece, index) in labels.get(item.connection.key)
                        ?.pieces ?? []"
                    :key="index"
                >
                    <g v-if="piece.kind === 'gateway' && item.label !== null">
                        <rect
                            :x="item.label.x + LOGICAL.labelPaddingX + piece.x"
                            :y="
                                item.label.y +
                                (item.label.height - LOGICAL.tagHeight) / 2
                            "
                            :width="piece.width"
                            :height="LOGICAL.tagHeight"
                            rx="4"
                            class="fill-success-surface stroke-pair-green"
                            stroke-width="1"
                        />
                        <text
                            :x="
                                item.label.x +
                                LOGICAL.labelPaddingX +
                                piece.x +
                                LOGICAL.tagPaddingX
                            "
                            :y="item.label.y + item.label.height / 2 + 4"
                            class="fill-success font-sans text-[12px] font-semibold"
                            data-test="gateway-tag"
                        >
                            {{ piece.text }}
                        </text>
                    </g>
                    <text
                        v-else-if="item.label !== null"
                        :x="item.label.x + LOGICAL.labelPaddingX + piece.x"
                        :y="item.label.y + item.label.height / 2 + 4"
                        :class="TEXT_CLASSES[piece.kind]"
                    >
                        {{ piece.text }}
                    </text>
                </template>
            </g>

            <!-- The nodes, in the order of reading: each subnet and each
                 device a link to its page, followed by the warning of its
                 deviations. -->
            <template v-for="item in nodesDrawn" :key="item.node.key">
                <DiagramNode
                    v-if="item.node.kind === 'subnet'"
                    :node-key="item.node.key"
                    :box="item"
                    :href="showSubnet(item.node.subnet.id).url"
                    :title="subnetTitle(item.node.subnet)"
                    :deviations="item.node.subnet.deviations"
                    :warning="{
                        'data-subnet-warning': item.node.subnet.cidr,
                        'data-test': 'subnet-deviation',
                    }"
                    :unplaced="unplaced.has(item.node.key)"
                    :unplaced-at="LOGICAL.barHeight"
                    :unplaced-width="unplacedWidth"
                    :data-subnet="item.node.subnet.cidr"
                >
                    <rect
                        :x="item.x"
                        :y="item.y"
                        :width="item.width"
                        :height="LOGICAL.barHeight"
                        rx="8"
                        class="fill-card stroke-border-strong group-hover:stroke-link"
                        stroke-width="1.5"
                        :stroke-dasharray="
                            item.node.subnet.planned ? '5 4' : undefined
                        "
                    />
                    <template
                        v-for="(piece, index) in bars.get(item.node.key)?.first
                            .pieces ?? []"
                        :key="`first-${index}`"
                    >
                        <g v-if="piece.kind === 'vlan'">
                            <rect
                                :x="item.x + LOGICAL.barPaddingX + piece.x"
                                :y="item.y + 9"
                                :width="piece.width"
                                :height="LOGICAL.vlanHeight"
                                rx="4"
                                class="fill-vlan-surface stroke-pair-orange"
                                stroke-width="1"
                            />
                            <text
                                :x="
                                    item.x +
                                    LOGICAL.barPaddingX +
                                    piece.x +
                                    LOGICAL.vlanPaddingX
                                "
                                :y="item.y + 23"
                                class="fill-vlan font-sans text-[12px] font-semibold"
                            >
                                {{ piece.text }}
                            </text>
                        </g>
                        <text
                            v-else
                            :x="item.x + LOGICAL.barPaddingX + piece.x"
                            :y="item.y + 24"
                            :class="TEXT_CLASSES[piece.kind]"
                        >
                            {{ piece.text }}
                        </text>
                    </template>
                    <text
                        v-for="(piece, index) in bars.get(item.node.key)?.second
                            .pieces ?? []"
                        :key="`second-${index}`"
                        :x="item.x + LOGICAL.barPaddingX + piece.x"
                        :y="item.y + 44"
                        :class="TEXT_CLASSES[piece.kind]"
                    >
                        {{ piece.text }}
                    </text>
                </DiagramNode>

                <DiagramNode
                    v-else-if="item.node.kind === 'device'"
                    :node-key="item.node.key"
                    :box="item"
                    :href="showDevice(item.node.device.id).url"
                    :title="deviceTitle(item.node.device)"
                    :deviations="item.node.device.deviations"
                    :warning="{
                        'data-device-warning': item.node.device.name,
                        'data-test': 'device-deviation',
                    }"
                    :unplaced="unplaced.has(item.node.key)"
                    :unplaced-at="SIZES.deviceHeight"
                    :unplaced-width="unplacedWidth"
                    :data-device="item.node.device.name"
                >
                    <rect
                        :x="item.x"
                        :y="item.y"
                        :width="item.width"
                        :height="SIZES.deviceHeight"
                        rx="8"
                        class="fill-card stroke-border-strong group-hover:stroke-link"
                        stroke-width="1.25"
                        :stroke-dasharray="
                            item.node.device.planned ||
                            item.node.device.site !== null
                                ? '5 4'
                                : undefined
                        "
                    />
                    <text
                        :x="item.x + SIZES.devicePaddingX"
                        :y="item.y + 21"
                        class="fill-foreground font-sans text-[14px] font-semibold"
                    >
                        {{ item.node.device.name }}
                    </text>
                    <text
                        :x="item.x + SIZES.devicePaddingX"
                        :y="item.y + 38"
                        class="fill-muted-foreground font-sans text-[12px]"
                    >
                        {{ secondary(item.node.device) }}
                    </text>
                </DiagramNode>

                <DiagramInternet
                    v-else
                    :box="item"
                    :node-key="item.node.key"
                    :unplaced="unplaced.has(item.node.key)"
                    :unplaced-width="unplacedWidth"
                />
            </template>
        </template>
    </DiagramCanvas>
</template>
