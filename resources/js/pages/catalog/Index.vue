<!-- torsade/resources/js/pages/catalog/Index.vue -->
<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Heading from '@/components/Heading.vue';
import CatalogVlanDialog from '@/components/network/CatalogVlanDialog.vue';
import Deviation from '@/components/network/Deviation.vue';
import RowActions from '@/components/RowActions.vue';
import { Button } from '@/components/ui/button';
import { trans } from '@/lib/i18n';
import { destroy, index, show } from '@/routes/catalog-vlans';
import { show as showVlan } from '@/routes/vlans';
import type { CatalogEntry, CatalogSite, CatalogVlan } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: trans('VLAN catalogue'),
                href: index(),
            },
        ],
    },
});

// The catalogue as a table: one line per entry, one column per site, and in
// each cell the VLAN of that site that follows the entry.
const props = defineProps<{
    entries: CatalogEntry[];
    sites: CatalogSite[];
}>();

// Only decides which buttons are shown: the server checks every action.
const page = usePage();
const canEdit = computed<boolean>(() => page.props.auth.can.editNetwork);

// For each entry, its VLAN on each site.
const cells = computed(
    () =>
        new Map(
            props.entries.map((entry) => [
                entry.id,
                new Map(entry.vlans.map((vlan) => [vlan.site_id, vlan])),
            ]),
        ),
);

const vlanOn = (entry: CatalogEntry, site: CatalogSite): CatalogVlan | null =>
    cells.value.get(entry.id)?.get(site.id) ?? null;

const dialogOpen = ref<boolean>(false);
const editedEntry = ref<CatalogEntry | null>(null);
const entryToDelete = ref<CatalogEntry | null>(null);

const openDialog = (entry: CatalogEntry | null): void => {
    editedEntry.value = entry;
    dialogOpen.value = true;
};

const confirmDeletion = (): void => {
    const entry = entryToDelete.value;

    entryToDelete.value = null;

    if (!entry) {
        return;
    }

    // An entry that still has VLANs attached is refused by the server, with
    // a message that says what to do first.
    router.delete(destroy(entry.id).url, {
        preserveScroll: true,
        onError: (errors) =>
            toast.error(
                Object.values(errors)[0] ?? trans('Something went wrong.'),
            ),
    });
};
</script>

<template>
    <Head :title="$t('VLAN catalogue')" />

    <div class="flex flex-col gap-7">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <Heading
                :title="$t('VLAN catalogue')"
                :description="
                    $t(
                        'An entry stands for every site. Here are the VLANs the organisation has standardised, and the VLAN of each site that follows each of them. A VLAN may differ from its entry: it is then shown as a deviation.',
                    )
                "
            />
            <Button
                v-if="canEdit"
                type="button"
                data-test="create-catalog-vlan-button"
                @click="openDialog(null)"
            >
                {{ $t('New entry') }}
            </Button>
        </div>

        <p
            v-if="entries.length === 0"
            class="rounded-lg border border-dashed p-8 text-center text-muted-foreground"
        >
            {{
                canEdit
                    ? $t(
                          'The catalogue is empty. Create its first entry, then attach to it the VLANs that already bear its number.',
                      )
                    : $t('The catalogue is empty.')
            }}
        </p>

        <!-- With many sites the table is wider than the page: it scrolls
             inside its own frame, from the keyboard too. -->
        <div
            v-else
            class="table-frame"
            tabindex="0"
            role="group"
            :aria-label="$t('VLAN catalogue')"
            data-test="catalog-table"
        >
            <table class="table-dense" :aria-label="$t('VLAN catalogue')">
                <thead>
                    <tr>
                        <th scope="col">{{ $t('VID') }}</th>
                        <th scope="col">{{ $t('Name') }}</th>
                        <th
                            v-for="site in sites"
                            :key="site.id"
                            scope="col"
                            :data-test="`catalog-site-${site.id}`"
                        >
                            {{ site.name }}
                        </th>
                        <th v-if="canEdit" scope="col" class="text-right">
                            {{ $t('Actions') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="entry in entries"
                        :key="entry.id"
                        :data-test="`catalog-row-${entry.id}`"
                    >
                        <td class="font-mono text-sm">{{ entry.vid }}</td>
                        <th scope="row" class="font-medium whitespace-nowrap">
                            <Link :href="show(entry.id)" class="link">{{
                                entry.name
                            }}</Link>
                        </th>
                        <td
                            v-for="site in sites"
                            :key="site.id"
                            class="align-top"
                        >
                            <template v-if="vlanOn(entry, site)">
                                <Link
                                    :href="showVlan(vlanOn(entry, site)!.id)"
                                    class="link font-mono text-sm"
                                    :aria-label="
                                        $t('VLAN :label', {
                                            label: `${vlanOn(entry, site)!.vid} — ${vlanOn(entry, site)!.name}`,
                                        })
                                    "
                                    >{{ vlanOn(entry, site)!.vid }}</Link
                                >
                                <Deviation
                                    class="mt-1 min-w-44"
                                    :deviations="
                                        vlanOn(entry, site)!.deviations
                                    "
                                    compact
                                />
                            </template>
                            <template v-else>
                                <span
                                    class="text-muted-foreground"
                                    aria-hidden="true"
                                    >—</span
                                >
                                <span class="sr-only">{{ $t('No VLAN') }}</span>
                            </template>
                        </td>
                        <td v-if="canEdit" class="py-1">
                            <RowActions
                                :edit-test="`edit-catalog-vlan-${entry.id}`"
                                :delete-test="`delete-catalog-vlan-${entry.id}`"
                                @edit="openDialog(entry)"
                                @delete="entryToDelete = entry"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <CatalogVlanDialog
        :open="dialogOpen"
        :entry="editedEntry"
        @close="dialogOpen = false"
    />

    <ConfirmDialog
        :open="entryToDelete !== null"
        :title="$t('Delete this catalogue entry?')"
        :description="
            $t(
                ':label will be deleted. An entry that still has VLANs attached cannot be deleted.',
                {
                    label: entryToDelete
                        ? `${entryToDelete.vid} — ${entryToDelete.name}`
                        : '',
                },
            )
        "
        :confirm-label="$t('Delete')"
        destructive
        @confirm="confirmDeletion"
        @cancel="entryToDelete = null"
    />
</template>
