<!-- torsade/resources/js/pages/sites/Show.vue -->
<script setup lang="ts">
import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Deviation from '@/components/network/Deviation.vue';
import DeviationList from '@/components/network/DeviationList.vue';
import DeviceTable from '@/components/network/DeviceTable.vue';
import OccupancyBar from '@/components/network/OccupancyBar.vue';
import PlacesSection from '@/components/network/PlacesSection.vue';
import SiteDialog from '@/components/network/SiteDialog.vue';
import StatusBadge from '@/components/network/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { AddressingRuleParams } from '@/lib/addressingRule';
import { trans } from '@/lib/i18n';
import { formatCount } from '@/lib/numbers';
import {
    create as createBlock,
    show as showBlock,
} from '@/routes/address-blocks';
import { show as showCatalogEntry } from '@/routes/catalog-vlans';
import { create as createDevice } from '@/routes/devices';
import { destroy, index, show } from '@/routes/sites';
import { store as storeExpectedBlock } from '@/routes/sites/expected-block';
import { create as createHostnameDevices } from '@/routes/sites/hostname-devices';
import {
    logical as logicalDiagram,
    physical as physicalDiagram,
} from '@/routes/diagrams';
import { create as createStandard } from '@/routes/sites/standard';
import { create as createSubnet, show as showSubnet } from '@/routes/subnets';
import { create as createVlan, show as showVlan } from '@/routes/vlans';
import type {
    AddressBlockNode,
    Deviation as DeviationData,
    DeviationItem,
    Device,
    Option,
    PanelChoice,
    PlaceBuilding,
    Site,
    Subnet,
    Vlan,
} from '@/types';

// A site: its code, its blocks of addresses, its VLANs with the entry of
// the catalogue each one follows, its subnets that no VLAN carries, its
// places, its devices, and what differs from the standard of the
// organisation.
const props = defineProps<{
    site: Site;
    blocks: AddressBlockNode[];
    vlans: Vlan[];
    // The subnets of the site that no VLAN carries.
    subnets: Subnet[];
    deviations: DeviationItem[];
    // How the site itself differs from the addressing rule: a number the
    // rule does not reach, or the block it expects that is not declared.
    siteDeviations: DeviationData[];
    // What the addressing rule works out, or null without a rule.
    rule: AddressingRuleParams | null;
    statuses: Option[];
    // The buildings of the site, with their floors and how many jacks
    // each has, their rooms, and the patch panels kept in each room.
    places: PlaceBuilding[];
    // What the window of a patch panel chooses among.
    panelConnectors: Option[];
    // The statuses of a jack, agreed with it.
    jackStatuses: Option[];
    // The panels of the site with their free ports, asked for by a window
    // of jacks: they are not there before.
    panelChoices?: PanelChoice[];
    devices: Device[];
}>();

setLayoutProps({
    breadcrumbs: [
        { title: trans('Sites'), href: index() },
        { title: props.site.name, href: show(props.site.id) },
    ],
});

const page = usePage();

// Only decides which buttons are shown: the server checks every action.
const canEdit = computed<boolean>(() => page.props.auth.can.editNetwork);

const deviationItems = computed(() =>
    props.deviations.map((item) => ({
        key: `${item.type}-${item.id}`,
        label: item.label,
        href: item.type === 'vlan' ? showVlan(item.id) : showSubnet(item.id),
        technical: item.type === 'subnet',
        deviations: item.deviations,
    })),
);

// --- The block the addressing rule expects --------------------------------

const blockIsMissing = computed<boolean>(() =>
    props.siteDeviations.some(
        (deviation) => deviation.kind === 'site_block_missing',
    ),
);

const creatingBlock = ref<boolean>(false);

const createExpectedBlock = (): void => {
    // The server works the block out by itself: nothing of it is sent. A
    // block in the way refuses it, with a message that names it.
    router.post(
        storeExpectedBlock(props.site.id).url,
        {},
        {
            preserveScroll: true,
            onStart: () => {
                creatingBlock.value = true;
            },
            onFinish: () => {
                creatingBlock.value = false;
            },
            onError: (errors) =>
                toast.error(
                    Object.values(errors)[0] ?? trans('Something went wrong.'),
                ),
        },
    );
};

const dialogOpen = ref<boolean>(false);
const confirmingDeletion = ref<boolean>(false);

const confirmDeletion = (): void => {
    confirmingDeletion.value = false;

    // A site that still holds something is refused by the server, with a
    // message that says what to delete first.
    router.delete(destroy(props.site.id).url, {
        onError: (errors) =>
            toast.error(
                Object.values(errors)[0] ?? trans('Something went wrong.'),
            ),
    });
};
</script>

<template>
    <Head :title="$t('Site :name', { name: site.name })" />

    <div class="flex flex-col gap-7">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <div class="flex min-w-0 flex-col gap-3">
                <div class="flex flex-wrap items-center gap-3.5">
                    <h1 class="text-title font-semibold">
                        {{ $t('Site :name', { name: site.name }) }}
                    </h1>
                    <span
                        v-if="site.code"
                        class="inline-flex h-7.5 items-center rounded-sm border border-border-strong px-3 font-mono text-sm font-medium"
                        :title="$t('Code')"
                        data-test="site-code"
                    >
                        <span class="sr-only">{{ $t('Code') }}</span>
                        {{ site.code }}
                    </span>
                    <span
                        v-if="site.number !== null"
                        class="inline-flex h-7.5 items-center rounded-sm border border-border-strong px-3 text-sm font-medium"
                        data-test="site-number"
                    >
                        {{ $t('No.') }}
                        <span class="ml-1.5 font-mono">{{ site.number }}</span>
                    </span>
                    <StatusBadge
                        :status="site.status"
                        :label="site.status_label"
                        data-test="site-status"
                    />
                </div>
                <p
                    v-if="site.description"
                    class="max-w-prose whitespace-pre-line text-muted-foreground"
                >
                    {{ site.description }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2.5">
                <!-- Its devices and their links, drawn, and its subnets and
                     what is plugged into them: for every role. -->
                <Button variant="outline" as-child data-test="site-diagram">
                    <Link
                        :href="physicalDiagram({ query: { site: site.id } })"
                        >{{ $t('Physical diagram') }}</Link
                    >
                </Button>
                <Button
                    variant="outline"
                    as-child
                    data-test="site-logical-diagram"
                >
                    <Link
                        :href="logicalDiagram({ query: { site: site.id } })"
                        >{{ $t('Logical diagram') }}</Link
                    >
                </Button>
                <template v-if="canEdit">
                    <!-- The entries of the catalogue the site does not
                         follow yet, created here in one action. -->
                    <Button
                        variant="outline"
                        as-child
                        data-test="apply-standard"
                    >
                        <Link :href="createStandard(site.id)">{{
                            $t('Apply the standard')
                        }}</Link>
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        data-test="edit-site"
                        @click="dialogOpen = true"
                    >
                        {{ $t('Edit') }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        data-test="delete-site"
                        @click="confirmingDeletion = true"
                    >
                        {{ $t('Delete') }}
                    </Button>
                </template>
            </div>
        </div>

        <!-- What the addressing rule expects of the site itself. The block
             it expects can be declared from here. -->
        <div
            v-if="siteDeviations.length > 0"
            class="flex flex-wrap items-center gap-x-5 gap-y-3"
            data-test="site-rule-deviations"
        >
            <Deviation :deviations="siteDeviations" />
            <Button
                v-if="canEdit && blockIsMissing"
                type="button"
                variant="outline"
                size="sm"
                :disabled="creatingBlock"
                data-test="create-expected-block"
                @click="createExpectedBlock"
            >
                <Spinner v-if="creatingBlock" />
                {{ $t('Create this block') }}
            </Button>
        </div>

        <section
            class="flex flex-col gap-3"
            :aria-label="$t('Address blocks')"
            data-test="site-blocks"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">
                    {{ $t('Address blocks') }}
                    <span class="font-normal text-muted-foreground">{{
                        formatCount(blocks.length)
                    }}</span>
                </h2>
                <Button v-if="canEdit" variant="outline" size="sm" as-child>
                    <Link :href="createBlock({ query: { site: site.id } })">{{
                        $t('New block')
                    }}</Link>
                </Button>
            </div>

            <!-- What a block is, whether the site has some or not: the same
                 words as in the form. -->
            <p
                class="max-w-prose text-sm text-muted-foreground"
                data-test="site-blocks-help"
            >
                {{
                    $t(
                        'The block of a site is the envelope of all of its subnets, for example 192.168.0.0/16 for VLANs in 192.168.10.0/24 and 192.168.20.0/24. The network of a LAN is declared as the subnet of its VLAN, not as a block. A site without a hierarchical plan may have no block at all.',
                    )
                }}
            </p>

            <p
                v-if="blocks.length === 0"
                class="rounded-lg border border-dashed p-4 text-muted-foreground"
                data-test="site-blocks-empty"
            >
                {{
                    $t(
                        'No block is set aside for this site: its subnets are not compared with any.',
                    )
                }}
            </p>

            <div v-else class="table-frame">
                <table class="table-dense" :aria-label="$t('Address blocks')">
                    <thead>
                        <tr>
                            <th scope="col">{{ $t('Block') }}</th>
                            <th scope="col">{{ $t('Occupied space') }}</th>
                            <th scope="col">{{ $t('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="block in blocks"
                            :key="block.id"
                            data-test="site-block-row"
                        >
                            <td>
                                <Link
                                    :href="showBlock(block.id)"
                                    class="link font-mono text-sm font-medium whitespace-nowrap"
                                    >{{ block.cidr }}</Link
                                >
                                <div
                                    v-if="block.name"
                                    class="text-note text-muted-foreground"
                                >
                                    {{ block.name }}
                                </div>
                            </td>
                            <td>
                                <OccupancyBar
                                    :documented="block.occupied"
                                    :usable="block.size"
                                />
                            </td>
                            <td>
                                <StatusBadge
                                    :status="block.status"
                                    :label="block.status_label"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section
            class="flex flex-col gap-3"
            :aria-label="$t('VLANs')"
            data-test="site-vlans"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">
                    {{ $t('VLANs') }}
                    <span class="font-normal text-muted-foreground">{{
                        formatCount(vlans.length)
                    }}</span>
                </h2>
                <Button v-if="canEdit" variant="outline" size="sm" as-child>
                    <Link :href="createVlan({ query: { site: site.id } })">{{
                        $t('New VLAN')
                    }}</Link>
                </Button>
            </div>

            <p
                v-if="vlans.length === 0"
                class="rounded-lg border border-dashed p-4 text-muted-foreground"
            >
                {{ $t('No VLAN on this site yet.') }}
            </p>

            <div v-else class="table-frame">
                <table class="table-dense" :aria-label="$t('VLANs')">
                    <thead>
                        <tr>
                            <th scope="col">{{ $t('VID') }}</th>
                            <th scope="col">{{ $t('Name') }}</th>
                            <th scope="col">{{ $t('Catalogue') }}</th>
                            <th scope="col">{{ $t('Subnet') }}</th>
                            <th scope="col">{{ $t('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="vlan in vlans"
                            :key="vlan.id"
                            data-test="site-vlan-row"
                        >
                            <td class="font-mono text-sm">{{ vlan.vid }}</td>
                            <td class="whitespace-nowrap">
                                <Link
                                    :href="showVlan(vlan.id)"
                                    class="link font-medium"
                                    >{{ vlan.name }}</Link
                                >
                            </td>
                            <td>
                                <template v-if="vlan.catalog">
                                    <Link
                                        :href="
                                            showCatalogEntry(vlan.catalog.id)
                                        "
                                        class="link whitespace-nowrap"
                                    >
                                        <span class="font-mono text-sm">{{
                                            vlan.catalog.vid
                                        }}</span>
                                        — {{ vlan.catalog.name }}
                                    </Link>
                                    <Deviation
                                        class="mt-1 min-w-44"
                                        :deviations="vlan.deviations"
                                        compact
                                    />
                                </template>
                                <span v-else class="text-muted-foreground">{{
                                    $t('Outside the catalogue')
                                }}</span>
                            </td>
                            <td>
                                <Link
                                    v-if="vlan.subnet"
                                    :href="showSubnet(vlan.subnet.id)"
                                    class="link font-mono text-sm whitespace-nowrap"
                                    >{{ vlan.subnet.cidr }}</Link
                                >
                                <span v-else class="text-muted-foreground">{{
                                    $t('None')
                                }}</span>
                            </td>
                            <td>
                                <StatusBadge
                                    :status="vlan.status"
                                    :label="vlan.status_label"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section
            class="flex flex-col gap-3"
            :aria-label="$t('Subnets without a VLAN')"
            data-test="site-subnets"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">
                    {{ $t('Subnets without a VLAN') }}
                    <span class="font-normal text-muted-foreground">{{
                        formatCount(subnets.length)
                    }}</span>
                </h2>
                <Button v-if="canEdit" variant="outline" size="sm" as-child>
                    <Link :href="createSubnet({ query: { site: site.id } })">{{
                        $t('New subnet')
                    }}</Link>
                </Button>
            </div>

            <p
                v-if="subnets.length === 0"
                class="rounded-lg border border-dashed p-4 text-muted-foreground"
            >
                {{
                    $t(
                        'No subnet of this site is without a VLAN: no carrier link, tunnel or loopback is documented here.',
                    )
                }}
            </p>

            <div v-else class="table-frame">
                <table
                    class="table-dense"
                    :aria-label="$t('Subnets without a VLAN')"
                >
                    <thead>
                        <tr>
                            <th scope="col">{{ $t('Subnet') }}</th>
                            <th scope="col">{{ $t('Type') }}</th>
                            <th scope="col">{{ $t('Gateway') }}</th>
                            <th scope="col">{{ $t('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="subnet in subnets"
                            :key="subnet.id"
                            data-test="site-subnet-row"
                        >
                            <td>
                                <Link
                                    :href="showSubnet(subnet.id)"
                                    class="link font-mono text-sm font-medium whitespace-nowrap"
                                    >{{ subnet.cidr }}</Link
                                >
                                <div
                                    v-if="subnet.name"
                                    class="text-note text-muted-foreground"
                                >
                                    {{ subnet.name }}
                                </div>
                            </td>
                            <td class="whitespace-nowrap">
                                {{ subnet.kind_label }}
                            </td>
                            <td>
                                <span
                                    v-if="subnet.gateway"
                                    class="font-mono text-sm"
                                    >{{ subnet.gateway }}</span
                                >
                                <span v-else class="text-muted-foreground">{{
                                    $t('No gateway')
                                }}</span>
                            </td>
                            <td>
                                <StatusBadge
                                    :status="subnet.status"
                                    :label="subnet.status_label"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <PlacesSection
            :site-id="site.id"
            :places="places"
            :panel-connectors="panelConnectors"
            :statuses="statuses"
            :jack-statuses="jackStatuses"
            :panel-choices="panelChoices"
            :can-edit="canEdit"
        />

        <section
            class="flex flex-col gap-3"
            :aria-label="$t('Devices')"
            data-test="site-devices"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">
                    {{ $t('Devices') }}
                    <span class="font-normal text-muted-foreground">{{
                        formatCount(devices.length)
                    }}</span>
                </h2>
                <div v-if="canEdit" class="flex flex-wrap gap-2.5">
                    <!-- The host names of the addresses of the site, turned
                         into devices in one action. -->
                    <Button
                        variant="outline"
                        size="sm"
                        as-child
                        data-test="create-devices-from-hostnames"
                    >
                        <Link :href="createHostnameDevices(site.id)">{{
                            $t('Create devices from host names')
                        }}</Link>
                    </Button>
                    <Button variant="outline" size="sm" as-child>
                        <Link
                            :href="createDevice({ query: { site: site.id } })"
                            >{{ $t('New device') }}</Link
                        >
                    </Button>
                </div>
            </div>

            <DeviceTable
                :devices="devices"
                :empty-text="$t('No device on this site yet.')"
            />
        </section>

        <DeviationList
            :items="deviationItems"
            :told-elsewhere="siteDeviations.length > 0"
        />
    </div>

    <SiteDialog
        :open="dialogOpen"
        :site="site"
        :rule="rule"
        :statuses="statuses"
        from-page
        @close="dialogOpen = false"
    />

    <ConfirmDialog
        :open="confirmingDeletion"
        :title="$t('Delete this site?')"
        :description="
            $t(
                ':name will be deleted. A site that still has VLANs, subnets, address blocks, buildings or devices cannot be deleted.',
                { name: site.name },
            )
        "
        :confirm-label="$t('Delete')"
        destructive
        @confirm="confirmDeletion"
        @cancel="confirmingDeletion = false"
    />
</template>
