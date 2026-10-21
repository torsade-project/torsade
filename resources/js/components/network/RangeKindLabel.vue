<!-- torsade/resources/js/components/network/RangeKindLabel.vue -->
<script setup lang="ts">
import type { RangeKindValue } from '@/types';

// What a range is set aside for, in a word: "DHCP", "Fixe", "Réservée". The
// DHCP range keeps the orange of the pair; the other two are neutral. The
// word carries the information, never the colour alone.
defineProps<{
    kind: RangeKindValue;
    label: string;
}>();
</script>

<template>
    <span
        class="inline-flex h-6 items-center rounded-sm border px-2 text-sm font-semibold whitespace-nowrap"
        :class="
            kind === 'dhcp'
                ? 'border-pair-orange bg-vlan-surface text-vlan'
                : 'border-border-strong text-muted-foreground'
        "
        data-test="range-kind"
        :data-kind="kind"
    >
        {{ label }}
    </span>
</template>
