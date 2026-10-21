<!-- torsade/resources/js/components/diagram/PhysicalDiagram.vue -->
<script setup lang="ts">
import { computed, nextTick, onMounted, ref, shallowRef, watch } from 'vue';
import DiagramCanvas from '@/components/diagram/DiagramCanvas.vue';
import DiagramNode from '@/components/diagram/DiagramNode.vue';
import DiagramWarning from '@/components/diagram/DiagramWarning.vue';
import { useElkLayout } from '@/composables/useElkLayout';
import { useManualPlacing } from '@/composables/useManualPlacing';
import {
    badgeWidth,
    buildGraph,
    canvasMeasure,
    deviceShape,
    readLayout,
    SIZES,
} from '@/lib/diagram';
import type { Box, LaidDiagram, LaidLink, Measure } from '@/lib/diagram';
import { manualLayout, snap } from '@/lib/diagramManual';
import type { ManualDiagram, Position } from '@/lib/diagramManual';
import { trans, transChoice } from '@/lib/i18n';
import { diagramFile, download } from '@/lib/svgExport';
import { show as showDevice } from '@/routes/devices';
import { show as showPanel } from '@/routes/patch-panels';
import type {
    DiagramDevice,
    DiagramPanel,
    DiagramPlace,
    PhysicalDiagramData,
} from '@/types';

// The physical diagram of a site, laid out by ELK in a worker and drawn
// here, in SVG, with the colours of the theme, in the frame every diagram
// shares. Each device and each patch panel is a link to its page, in the
// order of reading.
//
// Once a device or a panel of the site is placed by hand, the diagram keeps
// the positions given and draws its links itself. While they are being
// placed, each one is moved with the mouse, a finger or the arrows, on a
// grid of 8.
const props = defineProps<{
    diagram: PhysicalDiagramData;
    // Each device in the frame of its room, its floor and its building.
    grouped: boolean;
    // The end devices hidden, by the key of each device drawn that was
    // joined to some of them: how many.
    hidden?: Record<string, number>;
    // Names what the diagram shows, for a screen reader.
    describedBy?: string;
    // Where devices and panels were placed by hand, by their key on the
    // diagram; none for the automatic layout.
    positions: Record<string, Position>;
    // Whether the devices are being placed by hand.
    editing: boolean;
    // Tells how a device is moved from the keyboard, while they are.
    editHelp?: string;
}>();

const emit = defineEmits<{
    // Whether the diagram is drawn, for what acts on the drawing.
    ready: [ready: boolean];
    // Whether devices were moved and not saved.
    dirty: [dirty: boolean];
}>();

const canvas = ref<InstanceType<typeof DiagramCanvas> | null>(null);

// --- Layout -----------------------------------------------------------------

// The layout ELK works out: the place of everything when nothing is placed
// by hand, the size of everything when something is.
const auto = shallowRef<LaidDiagram | null>(null);

// The texts of the page, measured with its fonts.
let measure: Measure | null = null;

const elk = useElkLayout(() => {
    auto.value = null;
    emit('ready', false);
});

// The second line of a device: its type, then a stack, a device that is
// planned, or the site of a device of another site.
const secondary = (device: DiagramDevice): string =>
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

// The badge of a device joined to end devices while they are hidden: how
// many.
const badge = (device: DiagramDevice): string | null => {
    const hidden = props.hidden?.[device.key] ?? 0;

    return hidden > 0
        ? transChoice(':count end device|:count end devices', hidden)
        : null;
};

// Where the badge sits: at the right of the line of the name, before the
// corner the warning of a deviation takes.
const badgeBox = (item: Box & { device: DiagramDevice }): Box => {
    const text = badge(item.device) ?? '';
    const width =
        measure === null ? text.length * 7 : badgeWidth(text, measure);

    return {
        x:
            item.x +
            item.width -
            SIZES.devicePaddingX -
            (item.device.deviations.length > 0 ? SIZES.deviationIcon : 0) -
            width,
        y: item.y + 8,
        width,
        height: SIZES.badgeHeight,
    };
};

const layOut = async (): Promise<void> => {
    emit('ready', false);

    const answer = await elk.layOut(() => {
        measure = canvasMeasure();

        return buildGraph(props.diagram, props.grouped, measure, {
            secondary,
            badge,
            technical: trans('Technical'),
            panel: trans('Panel'),
        });
    });

    if (answer === null) {
        return;
    }

    auto.value = readLayout(answer, props.diagram);
    canvas.value?.fit();
    emit('ready', true);
};

onMounted(() => void layOut());

watch(
    () => [props.diagram, props.grouped, props.hidden],
    () => void layOut(),
);

// --- Placed by hand ---------------------------------------------------------------

// A frame is at least as wide as its title, as in the automatic layout.
const titleWidth = (place: DiagramPlace): number =>
    measure === null
        ? 0
        : measure(place.name, 'frame') +
          (place.technical
              ? SIZES.frameTagGap + measure(trans('Technical'), 'tag')
              : 0) +
          SIZES.framePadding * 2;

const placing = useManualPlacing({
    editing: () => props.editing,
    positions: () => props.positions,
    automatic: () => [
        ...(auto.value?.devices ?? []).map((item) => ({
            key: item.device.key,
            x: item.x,
            y: item.y,
        })),
        ...(auto.value?.panels ?? []).map((item) => ({
            key: item.panel.key,
            x: item.x,
            y: item.y,
        })),
    ],
    drawn: () => nodesInReading.value.map((node) => node.key),
});

// What is drawn: the automatic layout, or the one placed by hand.
const laid = computed<LaidDiagram | ManualDiagram | null>(() => {
    if (auto.value === null || !placing.manual.value) {
        return auto.value;
    }

    return manualLayout(
        auto.value,
        props.diagram,
        placing.current.value,
        props.grouped,
        titleWidth,
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

// The devices and the panels, in the order of reading: top to bottom, by
// their middles, as the boxes of a row differ in height, then left to
// right; while they are being placed, in the order they had when it began,
// so that the one that moves keeps the focus.
type DrawnNode =
    | (Box & {
          key: string;
          device: DiagramDevice;
          ports: LaidDiagram['devices'][number]['ports'];
      })
    | (Box & {
          key: string;
          panel: DiagramPanel;
          ports: LaidDiagram['panels'][number]['ports'];
      });

const nodesInReading = computed<DrawnNode[]>(() =>
    [
        ...(laid.value?.devices ?? []).map((item) => ({
            ...item,
            key: item.device.key,
        })),
        ...(laid.value?.panels ?? []).map((item) => ({
            ...item,
            key: item.panel.key,
        })),
    ].sort((a, b) => a.y + a.height / 2 - (b.y + b.height / 2) || a.x - b.x),
);

const nodesDrawn = computed<DrawnNode[]>(() =>
    placing.inOrder(nodesInReading.value, (node) => node.key),
);

// The tag of a node not placed, as wide as its word.
const unplacedWidth = computed<number>(() => {
    void auto.value;

    return measure === null
        ? 64
        : Math.ceil(measure(trans('Not placed'), 'secondary')) + 12;
});

// --- Drawing -------------------------------------------------------------------

// The frames from the outside in, so that the inner ones are drawn above.
const places = computed(() =>
    [...(laid.value?.places ?? [])].sort((a, b) => a.depth - b.depth),
);

const points = (link: LaidLink): string =>
    link.points.map((point) => `${point.x},${point.y}`).join(' ');

// The warnings of the links, drawn above the devices and reached from the
// keyboard in the order of reading: top to bottom, then left to right.
const linkWarnings = computed<LaidLink[]>(() =>
    (laid.value?.links ?? [])
        .filter((link) => link.link.deviations.length > 0)
        .sort((a, b) => a.middle.y - b.middle.y || a.middle.x - b.middle.x),
);

const deviceTitle = (device: DiagramDevice): string =>
    [
        `${device.name} — ${secondary(device)}`,
        ...(badge(device) === null ? [] : [badge(device) as string]),
        ...device.deviations.map((deviation) => deviation.reason),
    ].join('\n');

const panelTitle = (panel: DiagramPanel): string =>
    [
        `${panel.name} — ${trans('Patch panel')}`,
        ...(panel.planned ? [trans('Planned')] : []),
    ].join('\n');

const linkTitle = (link: LaidLink): string =>
    [
        `${link.link.ends[0]} ↔ ${link.link.ends[1]}`,
        ...(link.link.label === null ? [] : [link.link.label]),
        ...(link.link.through === null
            ? []
            : [`${trans('through')} ${link.link.through}`]),
        ...link.link.paths,
        ...(link.link.medium_label === null ? [] : [link.link.medium_label]),
        ...(link.link.planned ? [trans('Planned')] : []),
        ...link.link.deviations.map((deviation) => deviation.reason),
    ].join('\n');

// Where the name and the second line of a node sit: lower in a device,
// higher in the smaller boxes of an end device and of a panel.
const baselines = (height: number): { name: number; secondary: number } =>
    height === SIZES.deviceHeight
        ? { name: 21, secondary: 38 }
        : { name: 17, secondary: 32 };

defineExpose({
    fit: (): void => canvas.value?.fit(),
    // Where every device and panel drawn is, as it is saved: all of them
    // at once.
    layout: (): { node: string; x: number; y: number }[] =>
        nodesDrawn.value.map((node) => ({
            node: node.key,
            x: snap(node.x),
            y: snap(node.y),
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
            <!-- The places: a frame each, titled by its name. -->
            <g
                v-for="frame in places"
                :key="frame.place.key"
                :data-place="frame.place.kind"
            >
                <rect
                    :x="frame.x"
                    :y="frame.y"
                    :width="frame.width"
                    :height="frame.height"
                    rx="10"
                    class="fill-none stroke-border"
                    :class="
                        frame.place.kind === 'building'
                            ? 'stroke-border-strong'
                            : ''
                    "
                    stroke-width="1.25"
                    :stroke-dasharray="
                        frame.place.kind === 'unplaced' ? '6 4' : undefined
                    "
                />
                <text
                    :x="frame.x + 12"
                    :y="frame.y + 21"
                    class="fill-muted-foreground font-sans text-[12px] font-medium"
                >
                    {{ frame.place.name }}
                    <tspan
                        v-if="frame.place.technical"
                        class="fill-foreground font-semibold"
                        :dx="SIZES.frameTagGap"
                    >
                        {{ $t('Technical') }}
                    </tspan>
                </text>
            </g>

            <!-- The links and the segments of the cabling, under the
                 devices. -->
            <g
                v-for="link in laid.links"
                :key="link.link.key"
                :data-link="link.link.key"
                :data-segment="link.link.segment"
                :data-jack="link.link.jack ?? undefined"
                :data-deviation="
                    link.link.deviations.length > 0 ? 'true' : undefined
                "
            >
                <title>{{ linkTitle(link) }}</title>
                <!-- Wide and invisible: what the pointer finds. -->
                <polyline
                    :points="points(link)"
                    class="fill-none stroke-transparent"
                    stroke-width="12"
                    data-export-skip
                />
                <polyline
                    :points="points(link)"
                    class="fill-none"
                    :class="
                        link.link.deviations.length > 0
                            ? 'stroke-pair-orange'
                            : 'stroke-border-strong'
                    "
                    :stroke-width="link.link.fibre ? 3.5 : 1.5"
                    :stroke-dasharray="link.link.planned ? '7 5' : undefined"
                    stroke-linejoin="round"
                />
                <g v-if="link.label !== null">
                    <rect
                        :x="link.label.x"
                        :y="link.label.y"
                        :width="link.label.width"
                        :height="link.label.height"
                        rx="4"
                        class="fill-card stroke-border"
                        stroke-width="1"
                    />
                    <!-- The jack, in the technical font, or the aggregates
                         of the link. -->
                    <text
                        :x="link.label.x + link.label.width / 2"
                        :y="link.label.y + link.label.height / 2 + 4"
                        text-anchor="middle"
                        class="fill-foreground"
                        :class="
                            link.link.label === null
                                ? 'font-mono text-[11px]'
                                : 'font-sans text-[12px]'
                        "
                        :data-test="
                            link.link.label === null ? 'jack-label' : undefined
                        "
                    >
                        {{ link.link.label ?? link.link.jack }}
                    </text>
                </g>
            </g>

            <!-- The devices and the panels, each a link to its page, in the
                 order of reading, each followed by the warning of its
                 deviations. -->
            <template v-for="item in nodesDrawn" :key="item.key">
                <DiagramNode
                    v-if="'device' in item"
                    :node-key="item.key"
                    :box="item"
                    :href="showDevice(item.device.id).url"
                    :title="deviceTitle(item.device)"
                    :deviations="item.device.deviations"
                    :warning="{
                        'data-device-warning': item.device.name,
                        'data-test': 'device-deviation',
                    }"
                    :unplaced="unplaced.has(item.key)"
                    :unplaced-at="deviceShape(item.device).height"
                    :unplaced-width="unplacedWidth"
                    :data-device="item.device.name"
                    :data-end-device="
                        item.device.end_device ? 'true' : undefined
                    "
                    :data-hidden-end-devices="hidden?.[item.key] ?? undefined"
                >
                    <rect
                        :x="item.x"
                        :y="item.y"
                        :width="item.width"
                        :height="item.height"
                        rx="8"
                        class="fill-card stroke-border-strong group-hover:stroke-link"
                        stroke-width="1.25"
                        :stroke-dasharray="
                            item.device.planned || item.device.site !== null
                                ? '5 4'
                                : undefined
                        "
                    />
                    <text
                        :x="item.x + SIZES.devicePaddingX"
                        :y="
                            item.y +
                            baselines(deviceShape(item.device).height).name
                        "
                        class="fill-foreground font-sans font-semibold"
                        :class="
                            item.device.end_device
                                ? 'text-[13px]'
                                : 'text-[14px]'
                        "
                    >
                        {{ item.device.name }}
                    </text>
                    <!-- The end devices hidden, in a badge beside the name. -->
                    <g
                        v-if="badge(item.device) !== null"
                        data-test="hidden-end-devices"
                    >
                        <rect
                            :x="badgeBox(item).x"
                            :y="badgeBox(item).y"
                            :width="badgeBox(item).width"
                            :height="badgeBox(item).height"
                            rx="8"
                            class="fill-none stroke-border-strong"
                            stroke-width="1"
                        />
                        <text
                            :x="badgeBox(item).x + SIZES.badgePaddingX"
                            :y="badgeBox(item).y + 12"
                            class="fill-muted-foreground font-sans text-[11px]"
                        >
                            {{ badge(item.device) }}
                        </text>
                    </g>
                    <text
                        :x="item.x + SIZES.devicePaddingX"
                        :y="
                            item.y +
                            baselines(deviceShape(item.device).height).secondary
                        "
                        class="fill-muted-foreground font-sans"
                        :class="
                            item.device.end_device
                                ? 'text-[11px]'
                                : 'text-[12px]'
                        "
                    >
                        {{ secondary(item.device) }}
                    </text>
                    <template #after>
                        <!-- Its ports that are linked or cabled. -->
                        <g v-for="port in item.ports" :key="port.key">
                            <rect
                                :x="port.x"
                                :y="port.y"
                                :width="port.width"
                                :height="port.height"
                                rx="2"
                                class="fill-card stroke-border-strong"
                                stroke-width="1.25"
                            />
                            <text
                                :x="port.label.x"
                                :y="port.label.y + 11"
                                class="fill-muted-foreground font-mono text-[11px]"
                            >
                                {{ port.name }}
                            </text>
                        </g>
                    </template>
                </DiagramNode>

                <!-- A panel: lower than a device, its name in the technical
                     font, its ports used numbered. -->
                <DiagramNode
                    v-else
                    :node-key="item.key"
                    :box="item"
                    :href="showPanel(item.panel.id).url"
                    :title="panelTitle(item.panel)"
                    :deviations="[]"
                    :warning="{}"
                    :unplaced="unplaced.has(item.key)"
                    :unplaced-at="SIZES.panelHeight"
                    :unplaced-width="unplacedWidth"
                    :data-panel="item.panel.name"
                >
                    <rect
                        :x="item.x"
                        :y="item.y"
                        :width="item.width"
                        :height="item.height"
                        rx="4"
                        class="fill-card stroke-border group-hover:stroke-link"
                        stroke-width="1"
                        :stroke-dasharray="
                            item.panel.planned ? '5 4' : undefined
                        "
                    />
                    <text
                        :x="item.x + SIZES.devicePaddingX"
                        :y="item.y + baselines(SIZES.panelHeight).name"
                        class="fill-foreground font-mono text-[13px] font-semibold"
                    >
                        {{ item.panel.name }}
                    </text>
                    <text
                        :x="item.x + SIZES.devicePaddingX"
                        :y="item.y + baselines(SIZES.panelHeight).secondary"
                        class="fill-muted-foreground font-sans text-[11px]"
                    >
                        {{ $t('Panel') }}
                    </text>
                    <template #after>
                        <g
                            v-for="port in item.ports"
                            :key="port.key"
                            :data-panel-port="port.name"
                        >
                            <rect
                                :x="port.x"
                                :y="port.y"
                                :width="port.width"
                                :height="port.height"
                                rx="1"
                                class="fill-card stroke-border-strong"
                                stroke-width="1.25"
                            />
                            <text
                                :x="port.label.x"
                                :y="port.label.y + 11"
                                class="fill-muted-foreground font-mono text-[11px]"
                            >
                                {{ port.name }}
                            </text>
                        </g>
                    </template>
                </DiagramNode>
            </template>

            <!-- The warnings of the links, halfway along each, above the
                 devices. -->
            <DiagramWarning
                v-for="link in linkWarnings"
                :key="`warning-${link.link.key}`"
                variant="badge"
                :deviation-key="link.link.key"
                :deviations="link.link.deviations"
                :x="link.middle.x"
                :y="link.middle.y"
                :data-link-warning="link.link.key"
                data-test="link-deviation"
            />
        </template>
    </DiagramCanvas>
</template>
