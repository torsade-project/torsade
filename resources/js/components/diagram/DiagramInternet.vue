<!-- torsade/resources/js/components/diagram/DiagramInternet.vue -->
<script setup lang="ts">
import { useDiagramContext } from '@/composables/useDiagramContext';
import type { Box } from '@/lib/diagram';
import { LOGICAL } from '@/lib/diagramLogical';
import { MANUAL } from '@/lib/diagramManual';

// The internet on the logical diagram: an ellipse, with no page to lead
// to. While the nodes are being placed, it is reached from the keyboard
// and moved like any node.
const props = defineProps<{
    nodeKey: string;
    box: Box;
    // Not placed by hand: on the row below the others, and told so under
    // the ellipse.
    unplaced: boolean;
    unplacedWidth: number;
}>();

const context = useDiagramContext();

const from = (): { x: number; y: number } => ({
    x: props.box.x,
    y: props.box.y,
});
</script>

<template>
    <g
        class="group outline-none"
        :class="{ 'cursor-move': context.editing.value }"
        role="img"
        :aria-label="$t('Internet')"
        :tabindex="context.editing.value ? 0 : undefined"
        :aria-describedby="
            context.editing.value ? context.editHelp.value : undefined
        "
        :data-x="box.x"
        :data-y="box.y"
        :data-unplaced="unplaced ? 'true' : undefined"
        data-internet
        @pointerdown="context.startMove($event, nodeKey, from())"
        @keydown="context.onNodeKey($event, nodeKey, from())"
    >
        <ellipse
            :cx="box.x + LOGICAL.internetWidth / 2"
            :cy="box.y + LOGICAL.internetHeight / 2"
            :rx="LOGICAL.internetWidth / 2 + 4"
            :ry="LOGICAL.internetHeight / 2 + 4"
            class="fill-none stroke-ring opacity-0 group-focus-visible:opacity-100"
            :class="{ 'opacity-100': context.seized.value === nodeKey }"
            stroke-width="2.5"
            data-export-skip
        />
        <ellipse
            :cx="box.x + LOGICAL.internetWidth / 2"
            :cy="box.y + LOGICAL.internetHeight / 2"
            :rx="LOGICAL.internetWidth / 2"
            :ry="LOGICAL.internetHeight / 2"
            class="fill-card stroke-border-strong"
            stroke-width="1.5"
        />
        <text
            :x="box.x + LOGICAL.internetWidth / 2"
            :y="box.y + LOGICAL.internetHeight / 2 + 5"
            text-anchor="middle"
            class="fill-foreground font-sans text-[14px] font-semibold"
        >
            {{ $t('Internet') }}
        </text>
        <g v-if="unplaced">
            <rect
                :x="box.x + (LOGICAL.internetWidth - unplacedWidth) / 2"
                :y="box.y + LOGICAL.internetHeight - 6"
                :width="unplacedWidth"
                :height="MANUAL.unplacedTag"
                rx="6"
                class="fill-card stroke-border-strong"
                stroke-width="1"
            />
            <text
                :x="box.x + LOGICAL.internetWidth / 2"
                :y="box.y + LOGICAL.internetHeight + 7"
                text-anchor="middle"
                class="fill-muted-foreground font-sans text-[11px]"
                data-test="device-unplaced"
            >
                {{ $t('Not placed') }}
            </text>
        </g>
    </g>
</template>
