<!-- torsade/resources/js/pages/sites/Index.vue -->
<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataTable from '@/components/DataTable.vue';
import Heading from '@/components/Heading.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import SiteDialog from '@/components/network/SiteDialog.vue';
import StatusBadge from '@/components/network/StatusBadge.vue';
import RowActions from '@/components/RowActions.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import type { AddressingRuleParams } from '@/lib/addressingRule';
import { trans } from '@/lib/i18n';
import { destroy, index, show } from '@/routes/sites';
import { index as vlans } from '@/routes/vlans';
import type { DataColumn, Option, Site, SiteFilters } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: trans('Sites'),
                href: index(),
            },
        ],
    },
});

const props = defineProps<{
    sites: Site[];
    // What the addressing rule works out, or null without a rule.
    rule: AddressingRuleParams | null;
    filters: SiteFilters;
    statuses: Option[];
}>();

const filters = reactive<SiteFilters>({ ...props.filters });

// Filtering is a plain GET request: the address of the page keeps the
// filter, so that a filtered list can be bookmarked or shared.
const applyFilters = (): void => {
    const query = Object.fromEntries(
        Object.entries(filters).filter(([, value]) => value !== ''),
    );

    router.get(index().url, query, { preserveState: true, replace: true });
};

const hasFilters = computed<boolean>(() => props.filters.status !== '');

// Only decides which buttons are shown: the server checks every action.
const page = usePage();
const canEdit = computed<boolean>(() => page.props.auth.can.editNetwork);

const columns = computed<DataColumn<Site>[]>(() => [
    { id: 'name', header: trans('Name'), value: (site) => site.name },
    {
        id: 'code',
        header: trans('Code'),
        value: (site) => site.code,
        class: 'font-mono text-sm whitespace-nowrap',
    },
    {
        id: 'number',
        header: trans('Number'),
        value: (site) => site.number,
        align: 'right',
        class: 'font-mono text-sm whitespace-nowrap',
    },
    {
        id: 'description',
        header: trans('Description'),
        value: (site) => site.description,
        class: 'text-muted-foreground',
    },
    {
        id: 'vlans',
        header: trans('VLANs'),
        value: (site) => site.vlans_count,
        align: 'right',
    },
    {
        id: 'status',
        header: trans('Status'),
        value: (site) => site.status_label,
    },
    ...(canEdit.value
        ? [
              {
                  id: 'actions',
                  header: trans('Actions'),
                  value: () => null,
                  sortable: false,
                  align: 'right',
                  class: 'py-1',
              } satisfies DataColumn<Site>,
          ]
        : []),
]);

const dialogOpen = ref<boolean>(false);
const editedSite = ref<Site | null>(null);
const siteToDelete = ref<Site | null>(null);

const openDialog = (site: Site | null): void => {
    editedSite.value = site;
    dialogOpen.value = true;
};

const confirmDeletion = (): void => {
    const site = siteToDelete.value;

    siteToDelete.value = null;

    if (!site) {
        return;
    }

    // A site that still has VLANs is refused by the server, with a message
    // that says what to delete first.
    router.delete(destroy(site.id).url, {
        preserveScroll: true,
        onError: (errors) =>
            toast.error(
                Object.values(errors)[0] ?? trans('Something went wrong.'),
            ),
    });
};
</script>

<template>
    <Head :title="$t('Sites')" />

    <div class="flex flex-col gap-7">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <Heading
                :title="$t('Sites')"
                :description="
                    $t(
                        'The places whose network is documented. Each VLAN belongs to a site.',
                    )
                "
            />
            <Button
                v-if="canEdit"
                type="button"
                data-test="create-site-button"
                @click="openDialog(null)"
            >
                {{ $t('New site') }}
            </Button>
        </div>

        <form
            class="flex flex-wrap gap-4"
            data-test="site-filters"
            @submit.prevent="applyFilters"
        >
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
            :rows="sites"
            :columns="columns"
            :label="$t('Sites')"
            row-test="site-row"
            :empty-text="
                hasFilters
                    ? $t('No site matches this filter.')
                    : canEdit
                      ? $t('No site yet. Create the first one to get started.')
                      : $t('No site yet.')
            "
        >
            <template #cell-name="{ row }">
                <Link :href="show(row.id)" class="link font-medium">{{
                    row.name
                }}</Link>
            </template>
            <template #cell-vlans="{ row }">
                <Link :href="vlans({ query: { site: row.id } })" class="link">{{
                    row.vlans_count
                }}</Link>
            </template>
            <template #cell-status="{ row }">
                <StatusBadge :status="row.status" :label="row.status_label" />
            </template>
            <template #cell-actions="{ row }">
                <RowActions
                    :edit-test="`edit-site-${row.id}`"
                    :delete-test="`delete-site-${row.id}`"
                    @edit="openDialog(row)"
                    @delete="siteToDelete = row"
                />
            </template>
        </DataTable>
    </div>

    <SiteDialog
        :open="dialogOpen"
        :site="editedSite"
        :rule="rule"
        :statuses="statuses"
        @close="dialogOpen = false"
    />

    <ConfirmDialog
        :open="siteToDelete !== null"
        :title="$t('Delete this site?')"
        :description="
            $t(
                ':name will be deleted. A site that still has VLANs, subnets, address blocks, buildings or devices cannot be deleted.',
                { name: siteToDelete?.name ?? '' },
            )
        "
        :confirm-label="$t('Delete')"
        destructive
        @confirm="confirmDeletion"
        @cancel="siteToDelete = null"
    />
</template>
