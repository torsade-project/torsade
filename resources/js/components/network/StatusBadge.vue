<!-- torsade/resources/js/components/network/StatusBadge.vue -->
<script setup lang="ts">
import type { StatusValue } from '@/types';

// Planned, active or retired: a dot and its label. The colour never comes
// alone: active and retired are full dots, planned is an empty ring, and
// the label always says it in words. A dot that would not read on its
// background is hidden, keeping its place so that the label does not move.
withDefaults(
    defineProps<{
        status: StatusValue;
        label: string;
        hideDot?: boolean;
    }>(),
    {
        hideDot: false,
    },
);

const dots: Record<StatusValue, string> = {
    active: 'border-pair-green bg-pair-green',
    planned: 'border-link bg-transparent',
    retired: 'border-pair-brown bg-pair-brown',
};
</script>

<template>
    <span
        class="inline-flex items-center gap-2 whitespace-nowrap"
        :data-status="status"
    >
        <span
            class="size-2 shrink-0 rounded-full border-[1.5px]"
            :class="[dots[status], { invisible: hideDot }]"
            aria-hidden="true"
            data-test="status-dot"
        />
        {{ label }}
    </span>
</template>
