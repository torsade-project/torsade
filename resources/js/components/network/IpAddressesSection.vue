<!-- torsade/resources/js/components/network/IpAddressesSection.vue -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataTable from '@/components/DataTable.vue';
import Deviation from '@/components/network/Deviation.vue';
import IpAddressDialog from '@/components/network/IpAddressDialog.vue';
import IpQuickEntry from '@/components/network/IpQuickEntry.vue';
import JackPath from '@/components/network/JackPath.vue';
import StatusBadge from '@/components/network/StatusBadge.vue';
import RowActions from '@/components/RowActions.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { trans } from '@/lib/i18n';
import { compareIpv4 } from '@/lib/ipv4';
import { macContains } from '@/lib/mac';
import { formatCount } from '@/lib/numbers';
import { show as showDevice } from '@/routes/devices';
import { destroy } from '@/routes/ip-addresses';
import type {
    DataColumn,
    DeviceOption,
    FreeInterval,
    IpAddress,
    JackChoices,
    Option,
    Subnet,
} from '@/types';

// The documented addresses of a subnet: a dense table that can be searched
// and sorted, a window for one address and a quick entry for several.
const props = defineProps<{
    subnet: Subnet;
    addresses: IpAddress[];
    freeIntervals: FreeInterval[];
    deviceTypes: Option[];
    assignments: Option[];
    statuses: Option[];
    // The devices an address can be attached to.
    devices: DeviceOption[];
    maxBulkLines: number;
    // The jacks the window of an address offers, asked for by it.
    jackChoices?: JackChoices;
    canEdit: boolean;
}>();

const search = ref<string>('');

const columns = computed<DataColumn<IpAddress>[]>(() => [
    {
        id: 'address',
        header: trans('Address'),
        value: (address) => address.address,
        compare: (a, b) => compareIpv4(a.address, b.address),
        searchable: true,
        class: 'font-mono text-sm whitespace-nowrap',
    },
    {
        id: 'hostname',
        header: trans('Host name'),
        value: (address) => address.hostname,
        searchable: true,
        // The device of the address is written under its host name: it is
        // found by the same search.
        matches: (address, query) =>
            (address.hostname ?? '').toLowerCase().includes(query) ||
            (address.device?.name ?? '').toLowerCase().includes(query),
    },
    {
        id: 'mac_address',
        header: trans('MAC address'),
        value: (address) => address.mac_address,
        searchable: true,
        // A MAC address is found however it is typed, with colons,
        // hyphens, dots or nothing between its digits. A text that is not
        // made of such digits is not looked for in this column at all.
        matches: (address, query) => macContains(address.mac_address, query),
        class: 'font-mono text-note whitespace-nowrap text-muted-foreground',
    },
    {
        id: 'device_type',
        header: trans('Type'),
        value: (address) => address.device_type_label,
        class: 'whitespace-nowrap',
    },
    {
        id: 'assignment',
        header: trans('Assignment'),
        // "DHCP reservation" may take two lines: the column stays as narrow
        // as its heading.
        value: (address) => address.assignment_label,
    },
    {
        id: 'status',
        header: trans('Status'),
        value: (address) => address.status_label,
    },
    {
        id: 'description',
        header: trans('Description'),
        value: (address) => address.description,
        // A sentence is read on a few lines, in a column that never
        // shrinks to one word; on a wider screen it takes the room left.
        class: 'min-w-28 text-muted-foreground',
    },
    ...(props.canEdit
        ? [
              {
                  id: 'actions',
                  header: trans('Actions'),
                  value: () => null,
                  sortable: false,
                  align: 'right',
                  class: 'py-1',
              } satisfies DataColumn<IpAddress>,
          ]
        : []),
]);

const quickEntryOpen = ref<boolean>(false);
const dialogOpen = ref<boolean>(false);
const editedAddress = ref<IpAddress | null>(null);
// The address a new record starts with, when one was picked on the page.
const suggestedAddress = ref<string | null>(null);
const addressToDelete = ref<IpAddress | null>(null);

const openDialog = (
    address: IpAddress | null,
    suggested: string | null = null,
): void => {
    editedAddress.value = address;
    suggestedAddress.value = suggested;
    dialogOpen.value = true;
};

defineExpose({
    // Opens the window of a new address, filled in with the given one.
    addAddress: (address: string): void => openDialog(null, address),
});

const confirmDeletion = (): void => {
    const address = addressToDelete.value;

    addressToDelete.value = null;

    if (!address) {
        return;
    }

    router.delete(destroy(address.id).url, {
        preserveScroll: true,
        onError: (errors) =>
            toast.error(
                Object.values(errors)[0] ?? trans('Something went wrong.'),
            ),
    });
};
</script>

<template>
    <section
        class="flex min-w-0 flex-col gap-3.5"
        aria-labelledby="ip-addresses-title"
        data-test="ip-addresses"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="ip-addresses-title" class="text-lg font-semibold">
                {{ $t('IP addresses') }}
                <span class="font-normal text-muted-foreground">{{
                    formatCount(addresses.length)
                }}</span>
            </h2>
            <div class="flex flex-wrap items-center gap-2.5">
                <div v-if="addresses.length > 0" class="w-full sm:w-70">
                    <Label for="ip-search" class="sr-only">{{
                        $t('Search by address, host name or MAC address')
                    }}</Label>
                    <Input
                        id="ip-search"
                        v-model="search"
                        type="search"
                        name="search"
                        autocomplete="off"
                        class="h-9.5"
                        :placeholder="$t('Address, host name or MAC')"
                        data-test="ip-search"
                    />
                </div>
                <template v-if="canEdit">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        data-test="create-ip-address"
                        @click="openDialog(null)"
                    >
                        {{ $t('Add an address') }}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        :disabled="quickEntryOpen"
                        data-test="open-quick-entry"
                        @click="quickEntryOpen = true"
                    >
                        {{ $t('Add several addresses') }}
                    </Button>
                </template>
            </div>
        </div>

        <p class="text-sm text-muted-foreground">
            {{
                $t(
                    'The addresses documented in this subnet. Only the address is required.',
                )
            }}
        </p>

        <IpQuickEntry
            v-if="quickEntryOpen"
            :subnet="subnet"
            :free-intervals="freeIntervals"
            :device-types="deviceTypes"
            :assignments="assignments"
            :statuses="statuses"
            :max-lines="maxBulkLines"
            @close="quickEntryOpen = false"
        />

        <DataTable
            :rows="addresses"
            :columns="columns"
            :search="search"
            :label="$t('IP addresses')"
            sort-by="address"
            row-test="ip-address"
            :row-anchor="(address) => `address-${address.id}`"
            :empty-text="$t('No address is documented in this subnet yet.')"
            :no-match-text="$t('No address matches this search.')"
        >
            <template #cell-address="{ row }">
                <div class="font-medium">{{ row.address }}</div>
                <!-- Under the address, not beside it: the column stays as
                     narrow as an address. -->
                <Badge
                    v-if="row.is_gateway"
                    variant="success"
                    class="mt-1 font-sans"
                    data-test="gateway-badge"
                >
                    {{ $t('Gateway') }}
                </Badge>
            </template>
            <template #cell-hostname="{ row }">
                <div class="font-medium whitespace-nowrap">
                    {{ row.hostname }}
                </div>
                <!-- The device the address is an interface of, under its
                     host name: a column of its own would push the actions
                     of the line out of sight. -->
                <div
                    v-if="row.device"
                    class="text-note whitespace-nowrap text-muted-foreground"
                    data-test="address-device"
                >
                    <span class="sr-only">{{ $t('Device') }}</span>
                    <Link :href="showDevice(row.device.id)" class="link">{{
                        row.device.name
                    }}</Link>
                </div>
                <!-- The jack the machine is plugged into, with its path
                     to the port of a switch. -->
                <div v-if="row.jack" class="text-note" data-test="address-jack">
                    <span class="sr-only">{{ $t('Jack') }}</span>
                    <JackPath :path="row.jack.path" />
                </div>
                <!-- An interface on the network of another site than its
                     device. -->
                <Deviation
                    class="mt-1 min-w-36"
                    :deviations="row.deviations"
                    compact
                />
            </template>
            <template #cell-status="{ row }">
                <StatusBadge :status="row.status" :label="row.status_label" />
            </template>
            <template #cell-actions="{ row }">
                <RowActions
                    :edit-test="`edit-ip-address-${row.id}`"
                    :delete-test="`delete-ip-address-${row.id}`"
                    @edit="openDialog(row)"
                    @delete="addressToDelete = row"
                />
            </template>
        </DataTable>
    </section>

    <IpAddressDialog
        :open="dialogOpen"
        :subnet="subnet"
        :site-id="subnet.site?.id ?? null"
        :devices="devices"
        :address="editedAddress"
        :suggested-address="suggestedAddress"
        :free-intervals="freeIntervals"
        :device-types="deviceTypes"
        :assignments="assignments"
        :statuses="statuses"
        :jack-choices="jackChoices"
        @close="dialogOpen = false"
    />

    <ConfirmDialog
        :open="addressToDelete !== null"
        :title="$t('Delete this address?')"
        :description="
            $t(
                'The record of :address will be deleted and the address becomes free again. The activity log keeps the trace.',
                { address: addressToDelete?.address ?? '' },
            )
        "
        :confirm-label="$t('Delete')"
        destructive
        @confirm="confirmDeletion"
        @cancel="addressToDelete = null"
    />
</template>
