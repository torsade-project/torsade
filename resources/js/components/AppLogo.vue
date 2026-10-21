<!-- torsade/resources/js/components/AppLogo.vue -->
<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import LogoMark from '@/components/LogoMark.vue';

// The symbol, which stays visible when the sidebar is collapsed, then the
// name of the application.
const name = usePage().props.name;
</script>

<template>
    <LogoMark class="size-7 shrink-0 text-foreground" />
    <span
        class="truncate text-brand font-semibold text-foreground group-data-[collapsible=icon]:hidden"
        >{{ name }}</span
    >
</template>
