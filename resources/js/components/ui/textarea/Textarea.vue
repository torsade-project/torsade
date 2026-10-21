<!-- torsade/resources/js/components/ui/textarea/Textarea.vue -->
<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { useVModel } from "@vueuse/core"
import { cn } from "@/lib/utils"

const props = defineProps<{
  defaultValue?: string | number
  modelValue?: string | number
  class?: HTMLAttributes["class"]
}>()

const emits = defineEmits<{
  (e: "update:modelValue", payload: string | number): void
}>()

const modelValue = useVModel(props, "modelValue", emits, {
  passive: true,
  defaultValue: props.defaultValue,
})
</script>

<template>
  <textarea
    v-model="modelValue"
    data-slot="textarea"
    :class="cn(
      'placeholder:text-subtle-foreground selection:bg-primary selection:text-primary-foreground border-input bg-sunken min-h-20 w-full min-w-0 rounded-md border px-3.5 py-2.5 text-base transition-colors disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50',
      'aria-invalid:border-destructive',
      props.class,
    )"
  />
</template>
