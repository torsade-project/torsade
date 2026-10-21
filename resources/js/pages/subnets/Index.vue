<!-- torsade/resources/js/pages/subnets/Index.vue -->
<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import DataTable from '@/components/DataTable.vue';
import Heading from '@/components/Heading.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import Deviation from '@/components/network/Deviation.vue';
import OccupancyBar from '@/components/network/OccupancyBar.vue';
import StatusBadge from '@/components/network/StatusBadge.vue';
import VlanTag from '@/components/network/VlanTag.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { trans } from '@/lib/i18n';
import { compareIpv4 } from '@/lib/ipv4';
import { create, index, show } from '@/routes/subnets';
import { show as showVlan } from '@/routes/vlans';
import type {
    DataColumn,
    PlacedSubnet,
    SiteOption,
    SubnetFilters,
    SubnetKindOption,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: trans('Subnets'),
                href: index(),
            },
        ],
    },
});

const props = defineProps<{
    subnets: PlacedSubnet[];
    filters: SubnetFilters;
    sites: SiteOption[];
    kinds: SubnetKindOption[];
}>();

const page = usePage();

// Only decides which buttons are shown: the server checks every action.
const canEdit = computed<boolean>(() => page.props.auth.can.editNetwork);

const filters = reactive<SubnetFilters>({ ...props.filters });

// Filtering is a plain GET request: the address of the page keeps the
// filters, so that a filtered list can be bookmarked or shared.
const applyFilters = (): void => {
    const query = Object.fromEntries(
        Object.entries(filters).filter(([, value]) => value !== ''),
    );

    router.get(index().url, query, { preserveState: true, replace: true });
};

const hasFilters = computed<boolean>(
    () => props.filters.site !== '' || props.filters.kind !== '',
);

const columns: DataColumn<PlacedSubnet>[] = [
    {
        id: 'cidr',
        header: trans('Subnet'),
        value: (subnet) => subnet.cidr,
        // Numeric order of the addresses, then the shortest prefix first.
        compare: (a, b) =>
            compareIpv4(a.network, b.network) ||
            a.prefix_length - b.prefix_length,
        class: 'font-mono text-sm whitespace-nowrap',
    },
    {
        id: 'mask',
        header: trans('Mask'),
        value: (subnet) => subnet.mask,
        compare: (a, b) => a.prefix_length - b.prefix_length,
        class: 'font-mono text-sm',
    },
    {
        id: 'kind',
        header: trans('Type'),
        value: (subnet) => subnet.kind_label,
        class: 'whitespace-nowrap',
    },
    {
        id: 'site',
        header: trans('Site'),
        value: (subnet) => subnet.site?.name ?? null,
    },
    {
        id: 'vlan',
        header: trans('VLAN'),
        value: (subnet) => subnet.vlan?.vid ?? null,
        class: 'whitespace-nowrap',
    },
    {
        id: 'gateway',
        header: trans('Gateway'),
        value: (subnet) => subnet.gateway,
        compare: (a, b) => compareIpv4(a.gateway ?? '', b.gateway ?? ''),
        class: 'font-mono text-sm',
    },
    {
        id: 'occupancy',
        header: trans('Occupancy'),
        value: (subnet) => subnet.documented_count / subnet.usable_count,
    },
    {
        id: 'status',
        header: trans('Status'),
        value: (subnet) => subnet.status_label,
    },
];
</script>

<template>
    <Head :title="$t('Subnets')" />

    <div class="flex flex-col gap-7">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <Heading
                :title="$t('Subnets')"
                :description="
                    $t(
                        'Every subnet of the addressing plan, with or without a VLAN. No two subnets overlap.',
                    )
                "
            />
            <Button v-if="canEdit" as-child data-test="create-subnet-button">
                <Link :href="create()">{{ $t('New subnet') }}</Link>
            </Button>
        </div>

        <form
            class="flex flex-wrap gap-4"
            data-test="subnet-filters"
            @submit.prevent="applyFilters"
        >
            <div class="grid w-full gap-2 sm:w-56">
                <Label for="filter-site">{{ $t('Site') }}</Label>
                <NativeSelect
                    id="filter-site"
                    v-model="filters.site"
                    name="site"
                    class="h-9.5"
                    @change="applyFilters"
                >
                    <option value="">{{ $t('All sites') }}</option>
                    <option value="none">{{ $t('No site') }}</option>
                    <option
                        v-for="site in sites"
                        :key="site.id"
                        :value="String(site.id)"
                    >
                        {{ site.name }}
                    </option>
                </NativeSelect>
            </div>

            <div class="grid w-full gap-2 sm:w-56">
                <Label for="filter-kind">{{ $t('Type') }}</Label>
                <NativeSelect
                    id="filter-kind"
                    v-model="filters.kind"
                    name="kind"
                    class="h-9.5"
                    @change="applyFilters"
                >
                    <option value="">{{ $t('All types') }}</option>
                    <option
                        v-for="kind in kinds"
                        :key="kind.value"
                        :value="kind.value"
                    >
                        {{ kind.label }}
                    </option>
                </NativeSelect>
            </div>
        </form>

        <DataTable
            :rows="subnets"
            :columns="columns"
            :label="$t('Subnets')"
            sort-by="cidr"
            row-test="subnet-row"
            :empty-text="
                hasFilters
                    ? $t('No subnet matches these filters.')
                    : $t('No subnet yet.')
            "
        >
            <template #cell-cidr="{ row }">
                <Link :href="show(row.id)" class="link font-medium">{{
                    row.cidr
                }}</Link>
                <div
                    v-if="row.name"
                    class="font-sans text-note text-muted-foreground"
                >
                    {{ row.name }}
                </div>
            </template>
            <template #cell-site="{ row }">
                <span v-if="row.site" class="whitespace-nowrap">{{
                    row.site.name
                }}</span>
                <span v-else class="text-muted-foreground">{{
                    $t('No site')
                }}</span>
                <Deviation
                    class="mt-1 min-w-44"
                    :deviations="row.deviations"
                    compact
                />
            </template>
            <template #cell-vlan="{ row }">
                <VlanTag
                    v-if="row.vlan"
                    :vid="row.vlan.vid"
                    :name="row.vlan.name"
                    :href="showVlan(row.vlan.id)"
                    size="sm"
                />
                <span v-else class="text-muted-foreground">{{
                    $t('None')
                }}</span>
            </template>
            <template #cell-gateway="{ row }">
                <span v-if="row.gateway">{{ row.gateway }}</span>
                <span v-else class="font-sans text-muted-foreground">{{
                    $t('No gateway')
                }}</span>
            </template>
            <template #cell-occupancy="{ row }">
                <OccupancyBar
                    :documented="row.documented_count"
                    :usable="row.usable_count"
                />
            </template>
            <template #cell-status="{ row }">
                <StatusBadge :status="row.status" :label="row.status_label" />
            </template>
        </DataTable>
    </div>
</template>
