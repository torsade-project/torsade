<!-- torsade/resources/js/components/LogoMark.vue -->
<script setup lang="ts">
// The symbol of the application: the two strands of a twisted pair. One
// takes the colour of the text around it, the other is the orange of the
// second pair of a cable. It carries no text: the name is written next to it.
</script>

<template>
    <svg
        viewBox="0 0 32 32"
        fill="none"
        stroke-width="3"
        stroke-linecap="round"
        aria-hidden="true"
    >
        <path
            d="M3 16 C 8 5, 12 5, 16 16 S 24 27, 29 16"
            class="stroke-current"
        />
        <path
            d="M3 16 C 8 27, 12 27, 16 16 S 24 5, 29 16"
            class="stroke-pair-orange"
        />
    </svg>
</template>
