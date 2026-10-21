<!-- torsade/resources/js/components/diagram/DiagramCanvas.vue -->
<script setup lang="ts">
import { Maximize, Minus, Plus, TriangleAlert } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    provide,
    reactive,
    ref,
    shallowRef,
    useId,
    watch,
} from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { DIAGRAM_CONTEXT } from '@/composables/useDiagramContext';
import type { Box } from '@/lib/diagram';
import { GRID } from '@/lib/diagramManual';
import type { Position } from '@/lib/diagramManual';
import type { Deviation } from '@/types';

// The frame of a diagram, whatever it draws: an SVG that moves by dragging
// its background, and zooms with its buttons, Ctrl and the wheel, a pinch,
// or + and − when it has the focus; the wheel alone scrolls the page. While
// its nodes are placed by hand, a grid of 8 behind them, and each one moves
// with the mouse, a finger or the arrows. The warnings of the deviations
// open their reasons in a tooltip.
const props = defineProps<{
    // What is drawn, from its top left; null until it is laid out.
    bounds: Box | null;
    working: boolean;
    failed: boolean;
    // Whether the nodes are being placed by hand.
    editing: boolean;
    // Tells how a node is moved from the keyboard, while they are.
    editHelp?: string;
    // Names what the diagram shows, for a screen reader.
    describedBy?: string;
}>();

const emit = defineEmits<{
    // A node moved, by the pointer or the arrows: where its top left is
    // now, before it is put on the grid.
    move: [key: string, position: Position];
}>();

// --- View ----------------------------------------------------------------------

// The diagram fills the frame, all of it in sight: then it is moved and
// zoomed from there, in the units of the drawing.
const MARGIN = 24;
const MIN_ZOOM = 0.2;
const MAX_ZOOM = 8;

const view = reactive<{ x: number; y: number; zoom: number }>({
    x: 0,
    y: 0,
    zoom: 1,
});

// Still while a node is being moved, so that the drawing does not shift
// under the pointer.
const frozen = shallowRef<Box | null>(null);

const shown = computed<Box>(
    () => frozen.value ?? props.bounds ?? { x: 0, y: 0, width: 0, height: 0 },
);

const viewBox = computed<string>(() => {
    const { x, y, width, height } = shown.value;

    return `${x - MARGIN} ${y - MARGIN} ${width + MARGIN * 2} ${height + MARGIN * 2}`;
});

// The frame takes the shape of the diagram, within limits: a wide diagram
// on a telephone leaves no empty band below it.
const shape = computed<string>(() => {
    const width = shown.value.width + MARGIN * 2;
    const height = shown.value.height + MARGIN * 2;

    return `${Math.max(width, 1)} / ${Math.max(height, 1)}`;
});

const transform = computed<string>(
    () => `translate(${view.x} ${view.y}) scale(${view.zoom})`,
);

const svg = ref<SVGSVGElement | null>(null);

// "Fit": the whole diagram in sight.
const fit = (): void => {
    view.x = 0;
    view.y = 0;
    view.zoom = 1;
};

// A point of the screen, in the units of the drawing.
const toDrawing = (clientX: number, clientY: number): DOMPoint | null => {
    const matrix = svg.value?.getScreenCTM();

    return matrix
        ? new DOMPoint(clientX, clientY).matrixTransform(matrix.inverse())
        : null;
};

// Zoom by a factor, keeping a point of the drawing where it is.
const zoomAt = (factor: number, at: { x: number; y: number }): void => {
    const zoom = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, view.zoom * factor));
    const ratio = zoom / view.zoom;

    view.x = at.x - (at.x - view.x) * ratio;
    view.y = at.y - (at.y - view.y) * ratio;
    view.zoom = zoom;
};

const centre = (): { x: number; y: number } => ({
    x: shown.value.x + shown.value.width / 2,
    y: shown.value.y + shown.value.height / 2,
});

const zoomIn = (): void => zoomAt(1.25, centre());
const zoomOut = (): void => zoomAt(0.8, centre());

// With Ctrl, the wheel zooms where the pointer is; alone, it scrolls the
// page.
const onWheel = (event: WheelEvent): void => {
    if (!event.ctrlKey && !event.metaKey) {
        return;
    }

    event.preventDefault();

    const at = toDrawing(event.clientX, event.clientY);

    if (at !== null) {
        zoomAt(Math.exp(-event.deltaY * 0.0015), at);
    }
};

// --- Nodes placed by hand ------------------------------------------------------

// With the mouse or a finger: the node seized follows the pointer, and
// keeps the focus. The view stays still meanwhile.
let moving: {
    key: string;
    pointerId: number;
    point: DOMPoint;
    from: Position;
} | null = null;

const seized = ref<string | null>(null);

const startMove = (event: PointerEvent, key: string, from: Position): void => {
    if (!props.editing || event.button !== 0) {
        return;
    }

    const point = toDrawing(event.clientX, event.clientY);

    if (point === null) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();
    (event.currentTarget as SVGElement).focus({ preventScroll: true });
    svg.value?.setPointerCapture(event.pointerId);
    frozen.value = { ...shown.value };
    seized.value = key;
    moving = { key, pointerId: event.pointerId, point, from };
};

// From the keyboard: the arrows move the node that has the focus by a step
// of the grid, by five of them with Shift.
const ARROWS: Record<string, Position> = {
    ArrowLeft: { x: -1, y: 0 },
    ArrowRight: { x: 1, y: 0 },
    ArrowUp: { x: 0, y: -1 },
    ArrowDown: { x: 0, y: 1 },
};

const onNodeKey = (event: KeyboardEvent, key: string, from: Position): void => {
    const arrow = ARROWS[event.key];

    if (!props.editing || arrow === undefined) {
        return;
    }

    event.preventDefault();

    const step = event.shiftKey ? GRID * 5 : GRID;

    emit('move', key, {
        x: from.x + arrow.x * step,
        y: from.y + arrow.y * step,
    });
};

// --- Moving the view -------------------------------------------------------------

// Dragging the background moves the diagram; two fingers pinch it.
const pointers = new Map<number, { x: number; y: number }>();
let dragFrom: { point: DOMPoint; x: number; y: number } | null = null;
let pinchFrom: { distance: number; zoom: number } | null = null;

const distance = (): number => {
    const [a, b] = [...pointers.values()];

    return a && b ? Math.hypot(a.x - b.x, a.y - b.y) : 0;
};

const onPointerDown = (event: PointerEvent): void => {
    // A node is a link: it is followed, not dragged.
    if ((event.target as Element).closest('a') !== null || event.button !== 0) {
        return;
    }

    svg.value?.setPointerCapture(event.pointerId);
    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });

    if (pointers.size === 2) {
        dragFrom = null;
        pinchFrom = { distance: distance(), zoom: view.zoom };

        return;
    }

    const point = toDrawing(event.clientX, event.clientY);

    dragFrom = point === null ? null : { point, x: view.x, y: view.y };
};

const onPointerMove = (event: PointerEvent): void => {
    if (moving !== null && event.pointerId === moving.pointerId) {
        const point = toDrawing(event.clientX, event.clientY);

        if (point !== null) {
            emit('move', moving.key, {
                x: moving.from.x + (point.x - moving.point.x) / view.zoom,
                y: moving.from.y + (point.y - moving.point.y) / view.zoom,
            });
        }

        return;
    }

    if (!pointers.has(event.pointerId)) {
        return;
    }

    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });

    if (pinchFrom !== null && pointers.size === 2) {
        const [a, b] = [...pointers.values()];
        const at = toDrawing((a.x + b.x) / 2, (a.y + b.y) / 2);

        if (at !== null && pinchFrom.distance > 0) {
            zoomAt(
                (pinchFrom.zoom * distance()) / pinchFrom.distance / view.zoom,
                at,
            );
        }

        return;
    }

    if (dragFrom !== null) {
        const point = toDrawing(event.clientX, event.clientY);

        if (point !== null) {
            view.x = dragFrom.x + point.x - dragFrom.point.x;
            view.y = dragFrom.y + point.y - dragFrom.point.y;
        }
    }
};

const onPointerUp = (event: PointerEvent): void => {
    if (moving !== null && event.pointerId === moving.pointerId) {
        moving = null;
        frozen.value = null;
        seized.value = null;

        return;
    }

    pointers.delete(event.pointerId);

    if (pointers.size < 2) {
        pinchFrom = null;
    }

    if (pointers.size === 0) {
        dragFrom = null;
    }
};

// + and − zoom while the diagram has the focus; Escape closes the reasons
// of a deviation.
const onKeyDown = (event: KeyboardEvent): void => {
    if (event.key === 'Escape' && tip.value !== null) {
        event.preventDefault();
        hideTip();

        return;
    }

    if (event.ctrlKey || event.metaKey || event.altKey) {
        return;
    }

    if (event.key === '+' || event.key === '=') {
        event.preventDefault();
        zoomIn();
    } else if (event.key === '-' || event.key === '_') {
        event.preventDefault();
        zoomOut();
    }
};

// --- Reasons of a deviation ------------------------------------------------------

// The warning of a deviation is reached from the keyboard and by touch: its
// reasons open in a tooltip when it has the focus or is touched, and close
// with Escape or a touch elsewhere. The mouse has the title of the warning
// too.
const tipId = useId();
const frame = ref<HTMLElement | null>(null);
const tipBox = ref<HTMLElement | null>(null);
const tip = ref<{ key: string; reasons: string[] } | null>(null);
const tipAt = reactive<{ left: number; top: number }>({ left: 0, top: 0 });
let tipAnchor: Element | null = null;

// Above the warning, or below it near the top of the frame, and always
// within the frame.
const placeTip = async (): Promise<void> => {
    await nextTick();

    if (tipAnchor === null || frame.value === null) {
        return;
    }

    const anchor = tipAnchor.getBoundingClientRect();
    const area = frame.value.getBoundingClientRect();
    const width = tipBox.value?.offsetWidth ?? 0;
    const height = tipBox.value?.offsetHeight ?? 0;
    const middle = anchor.left + anchor.width / 2 - area.left;
    const above = anchor.top - area.top - height - 6;

    tipAt.left = Math.max(
        8,
        Math.min(middle - width / 2, area.width - width - 8),
    );
    tipAt.top = above >= 8 ? above : anchor.bottom - area.top + 6;
};

const showTip = (
    key: string,
    deviations: Deviation[],
    anchor: Element,
): void => {
    tip.value = {
        key,
        reasons: deviations.map((deviation) => deviation.reason),
    };
    tipAnchor = anchor;
    void placeTip();
};

const hideTip = (key?: string): void => {
    if (key === undefined || tip.value?.key === key) {
        tip.value = null;
        tipAnchor = null;
    }
};

const onWarningFocus = (
    event: FocusEvent,
    key: string,
    deviations: Deviation[],
): void => showTip(key, deviations, event.currentTarget as Element);

// A touch, or a click, opens the reasons, and closes them when they are
// already open. The background is not dragged from a warning.
const toggleTip = (
    event: Event,
    key: string,
    deviations: Deviation[],
): void => {
    if (tip.value?.key === key) {
        hideTip();
    } else {
        showTip(key, deviations, event.currentTarget as Element);
    }
};

const onWarningKey = (
    event: KeyboardEvent,
    key: string,
    deviations: Deviation[],
): void => {
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        toggleTip(event, key, deviations);
    }
};

const onDocumentPointerDown = (event: PointerEvent): void => {
    const target = event.target as Element | null;

    if (tip.value !== null && target?.closest('[data-deviation-key]') == null) {
        hideTip();
    }
};

onMounted(() => {
    document.addEventListener('pointerdown', onDocumentPointerDown, true);
});

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onDocumentPointerDown, true);
});

// The reasons follow their warning when the diagram moves.
watch(transform, () => {
    if (tip.value !== null) {
        void placeTip();
    }
});

provide(DIAGRAM_CONTEXT, {
    editing: computed<boolean>(() => props.editing),
    editHelp: computed<string | undefined>(() => props.editHelp),
    seized,
    startMove,
    onNodeKey,
    tipKey: computed<string | null>(() => tip.value?.key ?? null),
    tipId,
    onWarningFocus,
    hideTip,
    toggleTip,
    onWarningKey,
});

// The grid shown while the nodes are being placed.
const gridId = useId();

defineExpose({
    fit,
    // The drawing itself, to be saved as a file.
    element: (): SVGSVGElement | null => svg.value,
});
</script>

<template>
    <div
        ref="frame"
        class="relative min-w-0 overflow-hidden rounded-lg border bg-sunken"
        data-test="diagram-frame"
        @keydown="onKeyDown"
    >
        <!-- Zoom and fit, in the corner of the frame. -->
        <div
            v-if="bounds !== null"
            class="absolute top-2 right-2 z-10 flex gap-1 print:hidden"
            data-test="diagram-controls"
        >
            <Button
                type="button"
                variant="outline"
                size="icon"
                :aria-label="$t('Zoom in')"
                :title="$t('Zoom in')"
                data-test="zoom-in"
                @click="zoomIn"
            >
                <Plus aria-hidden="true" />
            </Button>
            <Button
                type="button"
                variant="outline"
                size="icon"
                :aria-label="$t('Zoom out')"
                :title="$t('Zoom out')"
                data-test="zoom-out"
                @click="zoomOut"
            >
                <Minus aria-hidden="true" />
            </Button>
            <Button
                type="button"
                variant="outline"
                size="sm"
                data-test="fit"
                @click="fit"
            >
                <Maximize aria-hidden="true" />
                {{ $t('Fit') }}
            </Button>
        </div>

        <div
            v-if="working"
            class="flex h-[min(70vh,48rem)] min-h-80 items-center justify-center gap-2 text-muted-foreground"
            role="status"
            data-test="diagram-working"
        >
            <Spinner />
            {{ $t('Laying the diagram out…') }}
        </div>

        <div
            v-else-if="failed"
            class="flex h-[min(70vh,48rem)] min-h-80 flex-col items-center justify-center gap-2 p-6 text-center"
            role="alert"
            data-test="diagram-failed"
        >
            <p class="flex items-start gap-2 text-error">
                <TriangleAlert
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                {{ $t('The diagram could not be laid out.') }}
            </p>
            <p class="max-w-prose text-sm text-muted-foreground">
                {{
                    $t(
                        'The data of the site are not affected. Reload the page to try again.',
                    )
                }}
            </p>
        </div>

        <svg
            v-show="bounds !== null && !working"
            ref="svg"
            :viewBox="viewBox"
            preserveAspectRatio="xMidYMid meet"
            class="block max-h-[min(70vh,48rem)] min-h-64 w-full cursor-grab touch-none select-none active:cursor-grabbing print:max-h-[150mm] print:min-h-0"
            :style="{ aspectRatio: shape }"
            role="group"
            :aria-describedby="describedBy"
            data-test="diagram"
            @wheel="onWheel"
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="onPointerUp"
            @pointercancel="onPointerUp"
        >
            <g v-if="bounds !== null" :transform="transform" data-viewport>
                <!-- While the nodes are being placed: dots every 8 pixels,
                     the grid they sit on. -->
                <template v-if="editing">
                    <defs>
                        <pattern
                            :id="gridId"
                            :width="GRID"
                            :height="GRID"
                            patternUnits="userSpaceOnUse"
                        >
                            <circle
                                cx="0.75"
                                cy="0.75"
                                r="0.75"
                                class="fill-muted-foreground"
                                fill-opacity="0.5"
                            />
                        </pattern>
                    </defs>
                    <rect
                        :x="shown.x - 4000"
                        :y="shown.y - 4000"
                        :width="shown.width + 8000"
                        :height="shown.height + 8000"
                        :fill="`url(#${gridId})`"
                        data-export-skip
                        data-test="diagram-grid"
                    />
                </template>

                <slot />
            </g>
        </svg>

        <!-- The reasons of the deviation whose warning has the focus or was
             touched. -->
        <div
            v-if="tip !== null"
            :id="tipId"
            ref="tipBox"
            role="tooltip"
            class="pointer-events-none absolute z-20 w-max max-w-[min(18rem,calc(100%-16px))] rounded-sm bg-foreground px-2.5 py-1.5 text-note text-background"
            :style="{ left: `${tipAt.left}px`, top: `${tipAt.top}px` }"
            data-test="deviation-tooltip"
            data-export-skip
        >
            <p v-for="(reason, index) in tip.reasons" :key="index">
                {{ reason }}
            </p>
        </div>
    </div>
</template>
