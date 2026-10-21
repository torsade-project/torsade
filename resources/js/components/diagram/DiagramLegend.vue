<!-- torsade/resources/js/components/diagram/DiagramLegend.vue -->
<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue';

// What the shapes of the physical diagram mean, drawn the same way: a
// device, planned or of another site, an end device, a patch panel, a
// port, a link in copper, in fibre, planned or with a deviation, the jack
// of a cabling, a place and a technical room.
</script>

<template>
    <ul
        class="flex flex-wrap gap-x-5 gap-y-2 text-sm text-muted-foreground"
        :aria-label="$t('Legend')"
        data-test="diagram-legend"
    >
        <li class="flex items-center gap-2">
            <svg width="28" height="16" aria-hidden="true">
                <rect
                    x="1"
                    y="1"
                    width="26"
                    height="14"
                    rx="4"
                    class="fill-card stroke-border-strong"
                    stroke-width="1.25"
                />
            </svg>
            {{ $t('Device') }}
        </li>
        <li class="flex items-center gap-2">
            <svg width="28" height="16" aria-hidden="true">
                <rect
                    x="1"
                    y="1"
                    width="26"
                    height="14"
                    rx="4"
                    class="fill-card stroke-border-strong"
                    stroke-width="1.25"
                    stroke-dasharray="5 4"
                />
            </svg>
            {{ $t('Planned, or of another site') }}
        </li>
        <li class="flex items-center gap-2">
            <svg width="28" height="16" aria-hidden="true">
                <rect
                    x="5"
                    y="3"
                    width="18"
                    height="10"
                    rx="3"
                    class="fill-card stroke-border-strong"
                    stroke-width="1.25"
                />
            </svg>
            {{ $t('End device') }}
        </li>
        <li class="flex items-center gap-2">
            <svg width="28" height="16" aria-hidden="true">
                <rect
                    x="1"
                    y="4"
                    width="26"
                    height="8"
                    rx="2"
                    class="fill-card stroke-border"
                    stroke-width="1"
                />
            </svg>
            {{ $t('Panel') }}
        </li>
        <li class="flex items-center gap-2">
            <svg width="16" height="16" aria-hidden="true">
                <rect
                    x="3"
                    y="3"
                    width="10"
                    height="10"
                    rx="2"
                    class="fill-card stroke-border-strong"
                    stroke-width="1.25"
                />
            </svg>
            {{ $t('Linked port') }}
        </li>
        <li class="flex items-center gap-2">
            <svg width="32" height="16" aria-hidden="true">
                <line
                    x1="1"
                    y1="8"
                    x2="31"
                    y2="8"
                    class="stroke-border-strong"
                    stroke-width="1.5"
                />
            </svg>
            {{ $t('Copper or other') }}
        </li>
        <li class="flex items-center gap-2">
            <svg width="32" height="16" aria-hidden="true">
                <line
                    x1="1"
                    y1="8"
                    x2="31"
                    y2="8"
                    class="stroke-border-strong"
                    stroke-width="3.5"
                />
            </svg>
            {{ $t('Fibre') }}
        </li>
        <li class="flex items-center gap-2">
            <svg width="32" height="16" aria-hidden="true">
                <line
                    x1="1"
                    y1="8"
                    x2="31"
                    y2="8"
                    class="stroke-border-strong"
                    stroke-width="1.5"
                    stroke-dasharray="7 5"
                />
            </svg>
            {{ $t('Planned link') }}
        </li>
        <li class="flex items-center gap-2">
            <svg width="32" height="16" aria-hidden="true">
                <line
                    x1="1"
                    y1="8"
                    x2="31"
                    y2="8"
                    class="stroke-border-strong"
                    stroke-width="1.5"
                />
                <rect
                    x="8"
                    y="3"
                    width="16"
                    height="10"
                    rx="2"
                    class="fill-card stroke-border"
                    stroke-width="1"
                />
            </svg>
            {{ $t('Jack') }}
        </li>
        <li class="flex items-center gap-2 text-pair-orange">
            <svg width="32" height="16" aria-hidden="true">
                <line
                    x1="1"
                    y1="8"
                    x2="31"
                    y2="8"
                    class="stroke-pair-orange"
                    stroke-width="1.5"
                />
                <TriangleAlert
                    x="9"
                    y="1"
                    :size="14"
                    width="14"
                    height="14"
                    class="fill-card text-pair-orange"
                />
            </svg>
            {{ $t('Deviation') }}
        </li>
        <li class="flex items-center gap-2">
            <svg width="28" height="16" aria-hidden="true">
                <rect
                    x="1"
                    y="1"
                    width="26"
                    height="14"
                    rx="5"
                    class="fill-none stroke-border"
                    stroke-width="1.25"
                />
            </svg>
            {{ $t('Building, floor or room') }}
        </li>
    </ul>
</template>
