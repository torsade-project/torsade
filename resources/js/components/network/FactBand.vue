<!-- torsade/resources/js/components/network/FactBand.vue -->
<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

// A band of facts about an object, in a frame with separated cells. Each
// cell draws its right and bottom borders; the frame hides those of the
// last column and of the last line.
const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <div class="overflow-hidden rounded-lg border">
        <dl
            :class="
                cn(
                    '-mr-px -mb-px grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-5',
                    props.class,
                )
            "
        >
            <slot />
        </dl>
    </div>
</template>
