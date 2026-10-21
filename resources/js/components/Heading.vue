<!-- torsade/resources/js/components/Heading.vue -->
<script setup lang="ts">
// The title of a page, or of a section of a page with the "small" variant,
// and the sentence that may explain it.
type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
};

withDefaults(defineProps<Props>(), {
    variant: 'default',
});
</script>

<template>
    <header class="flex min-w-0 flex-col gap-1">
        <h2 v-if="variant === 'small'" class="text-lg font-semibold">
            {{ title }}
        </h2>
        <h1 v-else class="text-title font-semibold">{{ title }}</h1>
        <p v-if="description" class="max-w-prose text-muted-foreground">
            {{ description }}
        </p>
    </header>
</template>
