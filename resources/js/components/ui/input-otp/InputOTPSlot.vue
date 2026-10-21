<!-- torsade/resources/js/components/ui/input-otp/InputOTPSlot.vue -->
<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { useForwardProps } from "reka-ui"
import { computed } from "vue"
import { useVueOTPContext } from "vue-input-otp"
import { cn } from "@/lib/utils"

const props = defineProps<{ index: number, class?: HTMLAttributes["class"] }>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardProps(delegatedProps)

const context = useVueOTPContext()

const slot = computed(() => context?.value.slots[props.index])
</script>

<template>
  <div
    v-bind="forwarded"
    data-slot="input-otp-slot"
    :data-active="slot?.isActive"
    :class="cn('data-[active=true]:outline-ring aria-invalid:border-destructive data-[active=true]:aria-invalid:border-destructive border-input bg-sunken relative flex h-11 w-11 items-center justify-center border-y border-r font-mono text-base transition-colors first:rounded-l-md first:border-l last:rounded-r-md data-[active=true]:z-10 data-[active=true]:outline-2 data-[active=true]:outline-offset-2', props.class)"
  >
    {{ slot?.char }}
    <div v-if="slot?.hasFakeCaret" class="pointer-events-none absolute inset-0 flex items-center justify-center">
      <div class="animate-caret-blink bg-foreground h-4 w-px duration-1000" />
    </div>
  </div>
</template>
