<!-- torsade/resources/js/components/network/Fact.vue -->
<script setup lang="ts">
// One labelled value of a band of facts: "Mask, 255.255.255.0".
defineProps<{
    label: string;
}>();
</script>

<template>
    <div class="flex min-w-0 flex-col gap-1 border-r border-b px-4.5 py-3.5">
        <dt class="text-note text-muted-foreground">{{ label }}</dt>
        <dd class="min-w-0"><slot /></dd>
    </div>
</template>
