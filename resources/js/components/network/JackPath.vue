<!-- torsade/resources/js/components/network/JackPath.vue -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { show as showDevice } from '@/routes/devices';
import { show as showPanel } from '@/routes/patch-panels';
import type { JackPathData } from '@/types';

// The path of a jack on one line, its steps separated by "›": the jack, the
// port of a panel its horizontal cable ends on, the device and the port at
// the other end of the cord, and what that port carries. "B.15 › PB-A 15 ›
// sw-etage-1 1/0/15 › Accès 10". The device plugged into the jack, on the
// side of the desk, comes first: "Branché : ap-salle-reunion 1 › B.14 › …".
// It stops where nothing is declared, and says so. On a narrow screen, the
// steps go to the next line.
withDefaults(
    defineProps<{
        path: JackPathData;
        // Without the jack, when the line already names it.
        withJack?: boolean;
    }>(),
    { withJack: true },
);
</script>

<template>
    <span
        class="inline-flex min-w-0 flex-wrap items-baseline gap-x-1.5 gap-y-0.5"
        data-test="jack-path"
    >
        <template v-if="path.plugged">
            <span
                class="inline-flex items-baseline gap-1.5 whitespace-nowrap"
                data-test="jack-path-plugged"
            >
                <span class="text-muted-foreground">{{ $t('Plugged:') }}</span>
                <Link :href="showDevice(path.plugged.device.id)" class="link">{{
                    path.plugged.device.name
                }}</Link>
                <span class="font-mono">{{ path.plugged.port.name }}</span>
            </span>
            <span class="text-muted-foreground">›</span>
        </template>
        <template v-if="withJack">
            <span class="font-mono font-medium">{{ path.jack.label }}</span>
            <span class="text-muted-foreground">›</span>
        </template>
        <template v-if="path.panel_port">
            <Link
                :href="showPanel(path.panel_port.panel_id)"
                class="link font-mono"
                data-test="jack-path-panel"
                >{{ path.panel_port.name }}</Link
            >
            <span class="text-muted-foreground">›</span>
            <template v-if="path.device && path.port">
                <span
                    class="inline-flex items-baseline gap-1.5 whitespace-nowrap"
                    data-test="jack-path-device"
                >
                    <Link :href="showDevice(path.device.id)" class="link">{{
                        path.device.name
                    }}</Link>
                    <span class="font-mono">{{ path.port.name }}</span>
                </span>
                <span class="text-muted-foreground">›</span>
                <span
                    v-if="path.configuration"
                    data-test="jack-path-configuration"
                    >{{ path.configuration }}</span
                >
                <span v-else class="text-muted-foreground">{{
                    $t('No mode')
                }}</span>
            </template>
            <span
                v-else
                class="text-muted-foreground"
                data-test="jack-path-missing"
                >{{ $t('not patched (feminine)') }}</span
            >
        </template>
        <span
            v-else
            class="text-muted-foreground"
            data-test="jack-path-missing"
            >{{ $t('not cabled to a panel') }}</span
        >
    </span>
</template>
