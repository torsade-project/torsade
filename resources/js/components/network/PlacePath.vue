<!-- torsade/resources/js/components/network/PlacePath.vue -->
<script setup lang="ts">
import { Badge } from '@/components/ui/badge';

// A place, always written with its path: "Main building › Ground floor ›
// Technical room". A technical room bears a label that says so.
defineProps<{
    path: string;
    technical?: boolean;
}>();
</script>

<template>
    <span
        class="inline-flex flex-wrap items-center gap-x-2 gap-y-1"
        data-test="place-path"
    >
        <span>{{ path }}</span>
        <Badge
            v-if="technical"
            variant="outline"
            class="font-sans"
            data-test="technical-label"
        >
            {{ $t('Technical') }}
        </Badge>
    </span>
</template>
