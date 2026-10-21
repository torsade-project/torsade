<!-- torsade/resources/js/components/ui/input/Input.vue -->
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
  <input
    v-model="modelValue"
    data-slot="input"
    :class="cn(
      'file:text-foreground placeholder:text-subtle-foreground selection:bg-primary selection:text-primary-foreground border-input bg-sunken h-11 w-full min-w-0 rounded-md border px-3.5 py-1 text-base transition-colors file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50',
      'aria-invalid:border-destructive',
      // Inside a grid of fields, as in the quick entry of addresses.
      'in-data-[dense]:h-9.5 in-data-[dense]:px-2.5 in-data-[dense]:text-table',
      props.class,
    )"
  >
</template>
