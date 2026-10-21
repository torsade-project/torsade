<!-- torsade/resources/js/components/ui/alert/AlertTitle.vue -->
<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <div
    data-slot="alert-title"
    :class="cn('col-start-2 min-h-4 font-semibold', props.class)"
  >
    <slot />
  </div>
</template>
