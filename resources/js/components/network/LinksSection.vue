<!-- torsade/resources/js/components/network/LinksSection.vue -->
<script setup lang="ts">
import { computed } from 'vue';
import LinksTable from '@/components/network/LinksTable.vue';
import { formatCount } from '@/lib/numbers';
import type { LinkLine, Option } from '@/types';

// The links of a device: each of its linked ports with the device and the
// port it faces. The links between the ports of two aggregates are one
// aggregated link: one line, which opens on the links it is made of.
const props = defineProps<{
    device: { id: number; name: string; site_id: number };
    lines: LinkLine[];
    media: Option[];
    statuses: Option[];
    canEdit: boolean;
}>();

const count = computed<number>(() =>
    props.lines.reduce((total, line) => total + line.links.length, 0),
);
</script>

<template>
    <section
        id="device-links"
        class="flex min-w-0 scroll-mt-4 flex-col gap-3"
        :aria-label="$t('Links')"
        data-test="device-links"
    >
        <h2 class="text-lg font-semibold">
            {{ $t('Links') }}
            <span class="font-normal text-muted-foreground">{{
                formatCount(count)
            }}</span>
        </h2>

        <p class="max-w-prose text-sm text-muted-foreground">
            {{
                $t(
                    'What the ports of this device are plugged into: the device and the port at the other end. A link is declared from the line of a port, below.',
                )
            }}
        </p>

        <p
            v-if="lines.length === 0"
            class="rounded-lg border border-dashed p-4 text-muted-foreground"
        >
            {{ $t('No port of this device is linked yet.') }}
        </p>

        <LinksTable
            v-else
            :lines="lines"
            :media="media"
            :statuses="statuses"
            :can-edit="canEdit"
            :device="device"
        />
    </section>
</template>
