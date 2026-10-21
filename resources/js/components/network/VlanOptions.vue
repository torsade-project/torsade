<!-- torsade/resources/js/components/network/VlanOptions.vue -->
<script setup lang="ts">
import type { VlanGroup } from '@/types';

// The VLANs of a list with one choice, as options. With several sites, each
// site is a group of the list under its name; with one, the VLANs alone.
defineProps<{
    groups: VlanGroup[];
}>();

const label = (vid: number, name: string): string => `${vid} — ${name}`;
</script>

<template>
    <template v-if="groups.length > 1">
        <optgroup
            v-for="group in groups"
            :key="group.site.id"
            :label="group.site.name"
        >
            <option v-for="vlan in group.vlans" :key="vlan.id" :value="vlan.id">
                {{ label(vlan.vid, vlan.name) }}
            </option>
        </optgroup>
    </template>
    <template v-else>
        <template v-for="group in groups" :key="group.site.id">
            <option v-for="vlan in group.vlans" :key="vlan.id" :value="vlan.id">
                {{ label(vlan.vid, vlan.name) }}
            </option>
        </template>
    </template>
</template>
