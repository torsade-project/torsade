<!-- torsade/resources/js/components/ui/breadcrumb/BreadcrumbLink.vue -->
<script lang="ts" setup>
import type { PrimitiveProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { Primitive } from "reka-ui"
import { cn } from "@/lib/utils"

const props = withDefaults(defineProps<PrimitiveProps & { class?: HTMLAttributes["class"] }>(), {
  as: "a",
})
</script>

<template>
  <Primitive
    data-slot="breadcrumb-link"
    :as="as"
    :as-child="asChild"
    :class="cn('text-link underline-offset-4 hover:underline', props.class)"
  >
    <slot />
  </Primitive>
</template>
