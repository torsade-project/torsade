<!-- torsade/resources/js/components/diagram/DiagramWarning.vue -->
<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue';
import { SIZES } from '@/lib/diagram';
import { useDiagramContext } from '@/composables/useDiagramContext';
import type { Deviation } from '@/types';

// The warning of the deviations of a node or of a link: in the top right
// corner of a box, or as a badge halfway along a line. It is reached from
// the keyboard and by touch, and opens the reasons in the tooltip of the
// diagram; the mouse has its title too.
const props = defineProps<{
    // Unique in the diagram: the key of what has the deviations.
    deviationKey: string;
    deviations: Deviation[];
    variant: 'corner' | 'badge';
    // The top right corner of the box, or the centre of the badge.
    x: number;
    y: number;
}>();

const context = useDiagramContext();

const reasons = (): string =>
    props.deviations.map((deviation) => deviation.reason).join('\n');
</script>

<template>
    <g
        class="group cursor-help outline-none"
        role="button"
        tabindex="0"
        :aria-label="$t('Deviation')"
        :aria-describedby="
            context.tipKey.value === deviationKey ? context.tipId : undefined
        "
        :data-deviation-key="deviationKey"
        @focus="context.onWarningFocus($event, deviationKey, deviations)"
        @blur="context.hideTip(deviationKey)"
        @pointerdown.stop="context.toggleTip($event, deviationKey, deviations)"
        @keydown="context.onWarningKey($event, deviationKey, deviations)"
    >
        <title>{{ reasons() }}</title>
        <template v-if="variant === 'corner'">
            <rect
                :x="x - SIZES.deviationIcon - 4"
                :y="y + 2"
                width="23"
                height="23"
                rx="6"
                class="fill-none stroke-ring opacity-0 group-focus-visible:opacity-100"
                stroke-width="2.5"
                data-export-skip
            />
            <TriangleAlert
                :x="x - SIZES.deviationIcon"
                :y="y + 6"
                width="15"
                height="15"
                class="text-pair-orange"
                aria-hidden="true"
            />
        </template>
        <template v-else>
            <circle
                :cx="x"
                :cy="y"
                r="15"
                class="fill-none stroke-ring opacity-0 group-focus-visible:opacity-100"
                stroke-width="2.5"
                data-export-skip
            />
            <circle
                :cx="x"
                :cy="y"
                r="11"
                class="fill-card stroke-pair-orange"
                stroke-width="1.5"
            />
            <TriangleAlert
                :x="x - 7"
                :y="y - 7.5"
                width="14"
                height="14"
                class="text-pair-orange"
                aria-hidden="true"
            />
        </template>
    </g>
</template>
