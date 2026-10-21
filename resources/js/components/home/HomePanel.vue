<!-- torsade/resources/js/components/home/HomePanel.vue -->
<script setup lang="ts">
// A module of the home page: a panel titled as a section, with the way to
// the whole list beside its title when it has one.
defineProps<{
    title: string;
    testId: string;
}>();
</script>

<template>
    <section
        class="panel flex min-w-0 flex-col gap-4 p-5"
        :aria-labelledby="`home-${testId}`"
        :data-test="`home-${testId}`"
    >
        <header
            class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1"
        >
            <h2 :id="`home-${testId}`" class="text-lg font-semibold">
                {{ title }}
            </h2>
            <slot name="action" />
        </header>
        <slot />
    </section>
</template>
