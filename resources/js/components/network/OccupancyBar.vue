<!-- torsade/resources/js/components/network/OccupancyBar.vue -->
<script setup lang="ts">
import { computed } from 'vue';
import { formatCount, formatShare } from '@/lib/numbers';

// Occupancy of a subnet: documented addresses out of usable addresses.
const props = defineProps<{
    documented: number;
    usable: number;
}>();

const percentage = computed<number>(() =>
    props.usable > 0
        ? Math.min(100, (props.documented / props.usable) * 100)
        : 0,
);
</script>

<template>
    <div class="flex min-w-32 flex-col gap-1" data-test="occupancy">
        <div
            class="h-1.5 w-full overflow-hidden rounded-full bg-sunken"
            role="progressbar"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-valuenow="Math.round(percentage)"
            :aria-label="$t('Occupancy')"
        >
            <!-- At least a sliver as soon as one address is documented. -->
            <div
                class="h-full rounded-full bg-pair-blue"
                :style="{
                    width: `${documented > 0 ? Math.max(percentage, 2) : 0}%`,
                }"
            />
        </div>
        <span class="text-note whitespace-nowrap text-muted-foreground">
            {{
                $t(':documented of :usable (:share)', {
                    documented: formatCount(documented),
                    usable: formatCount(usable),
                    share: formatShare(documented, usable),
                })
            }}
        </span>
    </div>
</template>
