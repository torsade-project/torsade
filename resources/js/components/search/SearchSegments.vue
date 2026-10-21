<!-- torsade/resources/js/components/search/SearchSegments.vue -->
<script setup lang="ts">
import type { SearchSegment } from '@/types';

// A value cut by the server into the parts that match what was typed and
// those that do not. A match is told by its weight and an underline, never
// by its colour alone. The text is written by Vue, never as HTML.
defineProps<{
    segments: SearchSegment[];
}>();
</script>

<template>
    <template v-for="(segment, position) in segments" :key="position">
        <mark
            v-if="segment.match"
            class="bg-transparent font-semibold text-inherit underline decoration-1 underline-offset-2"
            :class="{ 'font-mono': segment.mono }"
            >{{ segment.text }}</mark
        ><span v-else :class="{ 'font-mono': segment.mono }">{{
            segment.text
        }}</span>
    </template>
</template>
