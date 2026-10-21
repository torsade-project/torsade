<!-- torsade/resources/js/components/network/VlanTag.vue -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';

// The label of a VLAN, in the orange of the pair that stands for VLANs:
// "VLAN 30 Bureautique". It leads to the page of the VLAN when it is given
// its address.
withDefaults(
    defineProps<{
        vid: number;
        name: string;
        href?: NonNullable<InertiaLinkProps['href']>;
        size?: 'default' | 'sm';
    }>(),
    {
        href: undefined,
        size: 'default',
    },
);
</script>

<template>
    <component
        :is="href ? Link : 'span'"
        :href="href"
        class="inline-flex max-w-full items-center rounded-sm border border-pair-orange bg-vlan-surface font-semibold whitespace-nowrap text-vlan"
        :class="[
            size === 'sm' ? 'h-6 px-2 text-sm' : 'h-7.5 px-3',
            href ? 'hover:underline' : '',
        ]"
        data-test="vlan-tag"
    >
        <span class="truncate">{{
            $t('VLAN :label', { label: `${vid} ${name}` })
        }}</span>
    </component>
</template>
