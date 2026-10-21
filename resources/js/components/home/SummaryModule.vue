<!-- torsade/resources/js/components/home/SummaryModule.vue -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import HomePanel from '@/components/home/HomePanel.vue';
import { formatCount } from '@/lib/numbers';
import type { SummaryCounter } from '@/types';

// How much is documented, in a row of counters: each one leads to its
// list, except the addresses, which have none.
defineProps<{
    counters: SummaryCounter[];
}>();
</script>

<template>
    <HomePanel :title="$t('Summary')" test-id="summary">
        <ul
            class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-7"
        >
            <li v-for="counter in counters" :key="counter.key">
                <component
                    :is="counter.url ? Link : 'div'"
                    :href="counter.url ?? undefined"
                    class="flex h-full flex-col gap-0.5 rounded-md border bg-background px-3.5 py-3"
                    :class="{
                        'transition-colors hover:bg-accent': counter.url,
                    }"
                    :data-test="`counter-${counter.key}`"
                >
                    <span
                        class="text-title font-semibold tabular-nums"
                        data-test="counter-count"
                        >{{ formatCount(counter.count) }}</span
                    >
                    <span>{{ counter.label }}</span>
                    <span
                        v-if="counter.planned_label"
                        class="text-note text-muted-foreground"
                        data-test="counter-planned"
                        >{{ counter.planned_label }}</span
                    >
                </component>
            </li>
        </ul>
    </HomePanel>
</template>
