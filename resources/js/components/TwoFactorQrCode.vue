<!-- torsade/resources/js/components/TwoFactorQrCode.vue -->
<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';

// The QR code of a two-factor secret, as the server draws it. The
// applications that read it expect dark modules on a light background and
// an empty margin of four modules around the code: it is shown that way in
// both themes, whatever the colours the drawing came with.
const props = defineProps<{
    // Server-rendered SVG of the secret: no user-entered text.
    svg: string;
}>();

// Width of the empty margin the standard asks for, in modules.
const QUIET_ZONE = 4;

const frame = ref<HTMLElement | null>(null);

// The margin, as a share of the width of the frame. Until the code is
// measured, a margin wide enough for the smallest code that can hold a
// secret and the name of an account (33 modules).
const margin = ref<string>('10%');

// The drawing gives the position of its modules in modules: the width of
// what is drawn is the number of modules of the code. With a margin of four
// modules on each side, a module is one (modules + 8)th of the frame.
const measure = async (): Promise<void> => {
    await nextTick();

    const modules = frame.value?.querySelector('path')?.getBBox().width ?? 0;

    if (modules > 0) {
        margin.value = `${(QUIET_ZONE / (modules + 2 * QUIET_ZONE)) * 100}%`;
    }
};

watch(() => props.svg, measure, { immediate: true });
</script>

<template>
    <div
        ref="frame"
        class="aspect-square w-full bg-qr-background [&_path]:fill-qr-module [&_rect]:fill-qr-background [&_svg]:block [&_svg]:size-full"
        :style="{ padding: margin }"
        role="img"
        :aria-label="$t('Two-factor QR code')"
        data-test="two-factor-qr-code"
        v-html="svg"
    />
</template>
