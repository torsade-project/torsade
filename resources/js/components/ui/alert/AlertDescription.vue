<!-- torsade/resources/js/components/ui/alert/AlertDescription.vue -->
<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <div
    data-slot="alert-description"
    :class="cn('text-muted-foreground col-start-2 text-base [&_p]:leading-relaxed', props.class)"
  >
    <slot />
  </div>
</template>
