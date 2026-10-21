<!-- torsade/resources/js/components/NativeSelect.vue -->
<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

// The browser's own list, dressed like the other fields. In a form filled in
// from the keyboard it is quicker than a custom list: typing the first
// letters of a choice selects it without opening anything.
const model = defineModel<string | number | null>();

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <select
        v-model="model"
        :class="
            cn(
                'h-11 w-full min-w-0 rounded-md border border-input bg-sunken px-3 text-base transition-colors disabled:cursor-not-allowed disabled:opacity-50 in-data-[dense]:h-9.5 in-data-[dense]:px-2 in-data-[dense]:text-table aria-invalid:border-destructive',
                props.class,
            )
        "
    >
        <slot />
    </select>
</template>
