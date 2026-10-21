<!-- torsade/resources/js/pages/vlans/Index.vue -->
<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import DataTable from '@/components/DataTable.vue';
import Heading from '@/components/Heading.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import Deviation from '@/components/network/Deviation.vue';
import OccupancyBar from '@/components/network/OccupancyBar.vue';
import StatusBadge from '@/components/network/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { trans } from '@/lib/i18n';
import { compareIpv4 } from '@/lib/ipv4';
import { show as showCatalogEntry } from '@/routes/catalog-vlans';
import { index as sitesIndex } from '@/routes/sites';
import { show as showSubnet } from '@/routes/subnets';
import { create, index, show } from '@/routes/vlans';
import type {
    CatalogOption,
    DataColumn,
    Option,
    SiteOption,
    Vlan,
    VlanFilters,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: trans('VLANs'),
                href: index(),
            },
        ],
    },
});

const props = defineProps<{
    vlans: Vlan[];
    filters: VlanFilters;
    sites: SiteOption[];
    statuses: Option[];
    catalogEntries: CatalogOption[];
}>();

const page = usePage();

// Only decides which buttons are shown: the server checks every action.
const canEdit = computed<boolean>(() => page.props.auth.can.editNetwork);

const filters = reactive<VlanFilters>({ ...props.filters });

// Filtering is a plain GET request: the address of the page keeps the
// filters, so that a filtered list can be bookmarked or shared.
const applyFilters = (): void => {
    const query = Object.fromEntries(
        Object.entries(filters).filter(([, value]) => value !== ''),
    );

    router.get(index().url, query, { preserveState: true, replace: true });
};

const hasFilters = computed<boolean>(
    () =>
        props.filters.site !== '' ||
        props.filters.status !== '' ||
        props.filters.catalog !== '',
);

const columns: DataColumn<Vlan>[] = [
    {
        id: 'vid',
        header: trans('VID'),
        value: (vlan) => vlan.vid,
        class: 'font-mono text-sm',
    },
    {
        id: 'name',
        header: trans('Name'),
        value: (vlan) => vlan.name,
        class: 'whitespace-nowrap',
    },
    {
        id: 'site',
        header: trans('Site'),
        value: (vlan) => vlan.site.name,
        class: 'whitespace-nowrap',
    },
    {
        id: 'catalog',
        header: trans('Catalogue'),
        // By the number of the entry, the VLANs outside the catalogue last.
        value: (vlan) => vlan.catalog?.vid ?? null,
    },
    {
        id: 'subnet',
        header: trans('Subnet'),
        value: (vlan) => vlan.subnet?.cidr ?? null,
        compare: (a, b) =>
            compareIpv4(a.subnet?.network ?? '', b.subnet?.network ?? ''),
        class: 'font-mono text-sm whitespace-nowrap',
    },
    {
        id: 'occupancy',
        header: trans('Occupancy'),
        value: (vlan) =>
            vlan.subnet
                ? vlan.subnet.documented_count / vlan.subnet.usable_count
                : null,
    },
    {
        id: 'status',
        header: trans('Status'),
        value: (vlan) => vlan.status_label,
    },
];
</script>

<template>
    <Head :title="$t('VLANs')" />

    <div class="flex flex-col gap-7">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <Heading
                :title="$t('VLANs')"
                :description="
                    $t(
                        'The VLANs of each site, with the subnet each one carries.',
                    )
                "
            />
            <Button
                v-if="canEdit && sites.length > 0"
                as-child
                data-test="create-vlan-button"
            >
                <Link
                    :href="
                        create({
                            query: filters.site ? { site: filters.site } : {},
                        })
                    "
                    >{{ $t('New VLAN') }}</Link
                >
            </Button>
        </div>

        <!-- First use: a VLAN belongs to a site, so a site comes first. -->
        <div
            v-if="sites.length === 0"
            class="flex flex-col items-center gap-4 rounded-lg border border-dashed p-8 text-center"
            data-test="no-site-notice"
        >
            <p class="text-muted-foreground">
                {{
                    $t(
                        'A VLAN belongs to a site. Create a site first, then come back to declare its VLANs.',
                    )
                }}
            </p>
            <Button as-child variant="outline">
                <Link :href="sitesIndex()">{{ $t('Go to the sites') }}</Link>
            </Button>
        </div>

        <template v-else>
            <form
                class="flex flex-wrap gap-4"
                data-test="vlan-filters"
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
                        <option
                            v-for="site in sites"
                            :key="site.id"
                            :value="String(site.id)"
                        >
                            {{ site.name }}
                        </option>
                    </NativeSelect>
                </div>

                <div
                    v-if="catalogEntries.length > 0"
                    class="grid w-full gap-2 sm:w-56"
                >
                    <Label for="filter-catalog">{{ $t('Catalogue') }}</Label>
                    <NativeSelect
                        id="filter-catalog"
                        v-model="filters.catalog"
                        name="catalog"
                        class="h-9.5"
                        @change="applyFilters"
                    >
                        <option value="">{{ $t('All VLANs') }}</option>
                        <option value="none">
                            {{ $t('Outside the catalogue') }}
                        </option>
                        <option
                            v-for="entry in catalogEntries"
                            :key="entry.id"
                            :value="String(entry.id)"
                        >
                            {{ entry.vid }} — {{ entry.name }}
                        </option>
                    </NativeSelect>
                </div>

                <div class="grid w-full gap-2 sm:w-56">
                    <Label for="filter-status">{{ $t('Status') }}</Label>
                    <NativeSelect
                        id="filter-status"
                        v-model="filters.status"
                        name="status"
                        class="h-9.5"
                        @change="applyFilters"
                    >
                        <option value="">{{ $t('All statuses') }}</option>
                        <option
                            v-for="status in statuses"
                            :key="status.value"
                            :value="status.value"
                        >
                            {{ status.label }}
                        </option>
                    </NativeSelect>
                </div>
            </form>

            <DataTable
                :rows="vlans"
                :columns="columns"
                :label="$t('VLANs')"
                sort-by="vid"
                row-test="vlan-row"
                :empty-text="
                    hasFilters
                        ? $t('No VLAN matches these filters.')
                        : $t('No VLAN yet.')
                "
            >
                <template #cell-name="{ row }">
                    <Link :href="show(row.id)" class="link font-medium">{{
                        row.name
                    }}</Link>
                </template>
                <template #cell-catalog="{ row }">
                    <template v-if="row.catalog">
                        <Link
                            :href="showCatalogEntry(row.catalog.id)"
                            class="link whitespace-nowrap"
                        >
                            <span class="font-mono text-sm">{{
                                row.catalog.vid
                            }}</span>
                            — {{ row.catalog.name }}
                        </Link>
                        <Deviation
                            class="mt-1 min-w-44"
                            :deviations="row.deviations"
                            compact
                        />
                    </template>
                    <template v-else>
                        <span class="text-muted-foreground" aria-hidden="true"
                            >—</span
                        >
                        <span class="sr-only">{{
                            $t('Outside the catalogue')
                        }}</span>
                    </template>
                </template>
                <template #cell-subnet="{ row }">
                    <Link
                        v-if="row.subnet"
                        :href="showSubnet(row.subnet.id)"
                        class="link"
                        >{{ row.subnet.cidr }}</Link
                    >
                    <span v-else class="font-sans text-muted-foreground">{{
                        $t('None')
                    }}</span>
                </template>
                <template #cell-occupancy="{ row }">
                    <OccupancyBar
                        v-if="row.subnet"
                        :documented="row.subnet.documented_count"
                        :usable="row.subnet.usable_count"
                    />
                </template>
                <template #cell-status="{ row }">
                    <StatusBadge
                        :status="row.status"
                        :label="row.status_label"
                    />
                </template>
            </DataTable>
        </template>
    </div>
</template>
