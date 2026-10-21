<!-- torsade/resources/js/components/diagram/DiagramNode.vue -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import DiagramWarning from '@/components/diagram/DiagramWarning.vue';
import { useDiagramContext } from '@/composables/useDiagramContext';
import { SIZES } from '@/lib/diagram';
import type { Box } from '@/lib/diagram';
import { MANUAL } from '@/lib/diagramManual';
import type { Deviation } from '@/types';

// A node of a diagram that leads to a page: a device, a subnet. It is a
// link, reached from the keyboard in the order of reading, with the ring of
// the focus; followed by the warning of its deviations, since a link holds
// nothing else that takes the focus. While the nodes are being placed, it
// is moved, not followed.
defineOptions({ inheritAttrs: false });

const props = defineProps<{
    // Unique in the diagram.
    nodeKey: string;
    box: Box;
    href: string;
    title: string;
    deviations: Deviation[];
    // What the warning says it belongs to, as data attributes.
    warning: Record<string, string>;
    // Not placed by hand: on the row below the others, and told so under
    // what the node shows, which is this high.
    unplaced: boolean;
    unplacedAt: number;
    unplacedWidth: number;
}>();

const context = useDiagramContext();

const visit = (event: MouseEvent): void => {
    // While the nodes are being placed, a node is moved, not followed.
    if (context.editing.value) {
        event.preventDefault();

        return;
    }

    // A click with a modifier opens the page as the browser does.
    if (
        event.ctrlKey ||
        event.metaKey ||
        event.shiftKey ||
        event.button !== 0
    ) {
        return;
    }

    event.preventDefault();
    router.visit(props.href);
};

const from = (): { x: number; y: number } => ({
    x: props.box.x,
    y: props.box.y,
});
</script>

<template>
    <a
        v-bind="$attrs"
        :href="href"
        class="group outline-none"
        :class="{ 'cursor-move': context.editing.value }"
        :data-x="box.x"
        :data-y="box.y"
        :data-unplaced="unplaced ? 'true' : undefined"
        :aria-describedby="
            context.editing.value ? context.editHelp.value : undefined
        "
        @click="visit"
        @pointerdown="context.startMove($event, nodeKey, from())"
        @keydown="context.onNodeKey($event, nodeKey, from())"
    >
        <title>{{ title }}</title>
        <rect
            :x="box.x - 4"
            :y="box.y - 4"
            :width="box.width + 8"
            :height="box.height + 8"
            rx="11"
            class="fill-none stroke-ring opacity-0 group-focus-visible:opacity-100"
            :class="{ 'opacity-100': context.seized.value === nodeKey }"
            stroke-width="2.5"
            data-export-skip
        />
        <slot />
        <!-- Not placed by hand: on the row below the others, and told so
             under what it shows. -->
        <g v-if="unplaced">
            <rect
                :x="box.x + SIZES.devicePaddingX - 4"
                :y="box.y + unplacedAt - 6"
                :width="unplacedWidth"
                :height="MANUAL.unplacedTag"
                rx="6"
                class="fill-none stroke-border-strong"
                stroke-width="1"
            />
            <text
                :x="box.x + SIZES.devicePaddingX + 2"
                :y="box.y + unplacedAt + 7"
                class="fill-muted-foreground font-sans text-[11px]"
                data-test="device-unplaced"
            >
                {{ $t('Not placed') }}
            </text>
        </g>
        <slot name="after" />
    </a>
    <DiagramWarning
        v-if="deviations.length > 0"
        v-bind="warning"
        variant="corner"
        :deviation-key="nodeKey"
        :deviations="deviations"
        :x="box.x + box.width"
        :y="box.y"
    />
</template>
