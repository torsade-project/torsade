<!-- torsade/resources/js/components/network/DeviceTable.vue -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import DataTable from '@/components/DataTable.vue';
import PlacePath from '@/components/network/PlacePath.vue';
import StatusBadge from '@/components/network/StatusBadge.vue';
import { trans } from '@/lib/i18n';
import { show } from '@/routes/devices';
import { show as showSite } from '@/routes/sites';
import type { DataColumn, Device } from '@/types';

// The table of devices: the one of the list of all of them, and the one of
// the page of a site.
defineProps<{
    devices: Device[];
    emptyText: string;
}>();

const columns: DataColumn<Device>[] = [
    {
        id: 'name',
        header: trans('Name'),
        value: (device) => device.name,
        class: 'whitespace-nowrap',
    },
    {
        id: 'type',
        header: trans('Type'),
        value: (device) => device.type_label,
        class: 'whitespace-nowrap',
    },
    {
        id: 'site',
        header: trans('Site'),
        value: (device) => device.site.name,
        class: 'whitespace-nowrap',
    },
    {
        id: 'place',
        header: trans('Place'),
        // The devices whose room is not known come last.
        value: (device) => device.room?.path ?? null,
        // A path is read on a line or two: on a narrow screen the table
        // scrolls rather than squeezing it into a column of words.
        class: 'min-w-56',
    },
    {
        id: 'addresses',
        header: trans('Addresses'),
        value: (device) => device.addresses_count,
        align: 'right',
    },
    {
        id: 'status',
        header: trans('Status'),
        value: (device) => device.status_label,
    },
];
</script>

<template>
    <DataTable
        :rows="devices"
        :columns="columns"
        :label="$t('Devices')"
        row-test="device-row"
        :empty-text="emptyText"
    >
        <template #cell-name="{ row }">
            <Link :href="show(row.id)" class="link font-medium">{{
                row.name
            }}</Link>
        </template>
        <template #cell-site="{ row }">
            <Link :href="showSite(row.site.id)" class="link">{{
                row.site.name
            }}</Link>
        </template>
        <template #cell-place="{ row }">
            <PlacePath
                v-if="row.room"
                :path="row.room.path"
                :technical="row.room.is_technical"
            />
            <span v-else class="text-muted-foreground">{{
                $t('Not specified')
            }}</span>
        </template>
        <template #cell-status="{ row }">
            <StatusBadge :status="row.status" :label="row.status_label" />
        </template>
    </DataTable>
</template>
