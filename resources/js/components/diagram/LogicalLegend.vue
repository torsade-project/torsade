<!-- torsade/resources/js/components/diagram/LogicalLegend.vue -->
<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue';

// What the shapes of the logical diagram mean, drawn the same way: a
// subnet, planned or not, the internet, a device, planned or of another
// site, a device plugged into a subnet, the gateway among its addresses,
// and a deviation.
</script>

<template>
    <ul
        class="flex flex-wrap gap-x-5 gap-y-2 text-sm text-muted-foreground"
        :aria-label="$t('Legend')"
        data-test="diagram-legend"
    >
        <li class="flex items-center gap-2">
            <svg width="40" height="16" aria-hidden="true">
                <rect
                    x="1"
                    y="1"
                    width="38"
                    height="14"
                    rx="4"
                    class="fill-card stroke-border-strong"
                    stroke-width="1.5"
                />
            </svg>
            {{ $t('Subnet') }}
        </li>
        <li class="flex items-center gap-2">
            <svg width="40" height="16" aria-hidden="true">
                <rect
                    x="1"
                    y="1"
                    width="38"
                    height="14"
                    rx="4"
                    class="fill-card stroke-border-strong"
                    stroke-width="1.5"
                    stroke-dasharray="5 4"
                />
            </svg>
            {{ $t('Planned subnet') }}
        </li>
        <li class="flex items-center gap-2">
            <svg width="32" height="18" aria-hidden="true">
                <ellipse
                    cx="16"
                    cy="9"
                    rx="15"
                    ry="8"
                    class="fill-card stroke-border-strong"
                    stroke-width="1.5"
                />
            </svg>
            {{ $t('Internet') }}
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
            <svg width="32" height="16" aria-hidden="true">
                <line
                    x1="1"
                    y1="8"
                    x2="31"
                    y2="8"
                    class="stroke-border-strong"
                    stroke-width="1.25"
                />
            </svg>
            {{ $t('Connection to a subnet') }}
        </li>
        <li class="flex items-center gap-2">
            <svg width="70" height="18" aria-hidden="true">
                <rect
                    x="1"
                    y="1"
                    width="68"
                    height="16"
                    rx="4"
                    class="fill-success-surface stroke-pair-green"
                    stroke-width="1"
                />
                <text
                    x="35"
                    y="13"
                    text-anchor="middle"
                    class="fill-success font-sans text-[11px] font-semibold"
                >
                    {{ $t('gateway') }}
                </text>
            </svg>
            {{ $t('Gateway of the subnet') }}
        </li>
        <li class="flex items-center gap-2 text-pair-orange">
            <svg width="18" height="16" aria-hidden="true">
                <TriangleAlert
                    x="1"
                    y="1"
                    :size="14"
                    width="14"
                    height="14"
                    class="text-pair-orange"
                />
            </svg>
            {{ $t('Deviation') }}
        </li>
    </ul>
</template>
