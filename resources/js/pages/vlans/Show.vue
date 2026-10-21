<!-- torsade/resources/js/pages/vlans/Show.vue -->
<script setup lang="ts">
import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Heading from '@/components/Heading.vue';
import Deviation from '@/components/network/Deviation.vue';
import Fact from '@/components/network/Fact.vue';
import FactBand from '@/components/network/FactBand.vue';
import OccupancyBar from '@/components/network/OccupancyBar.vue';
import StatusBadge from '@/components/network/StatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { trans } from '@/lib/i18n';
import { formatCount } from '@/lib/numbers';
import { attach, show as showCatalogEntry } from '@/routes/catalog-vlans';
import { show as showDevice } from '@/routes/devices';
import { create as createSubnet, show as showSubnet } from '@/routes/subnets';
import { destroy, edit, index, show } from '@/routes/vlans';
import type {
    CatalogOption,
    Deviation as DeviationData,
    Vlan,
    VlanCarrierGroup,
} from '@/types';

const props = defineProps<{
    vlan: Vlan;
    // The entry of the catalogue that has the number of this VLAN, when the
    // VLAN follows none and can be attached to it at once by the person
    // signed in. Null in every other case.
    attachableEntry: CatalogOption | null;
    // How the subnet of the VLAN differs from the one the addressing rule
    // expects for it.
    subnetDeviations: DeviationData[];
    // The ports and the aggregates of ports that carry the VLAN, device by
    // device.
    carriers: VlanCarrierGroup[];
}>();

const carrierCount = computed<number>(() =>
    props.carriers.reduce((sum, group) => sum + group.carriers.length, 0),
);

setLayoutProps({
    breadcrumbs: [
        { title: trans('VLANs'), href: index() },
        { title: props.vlan.name, href: show(props.vlan.id) },
    ],
});

const page = usePage();

// Only decides which buttons are shown: the server checks every action.
const canEdit = computed<boolean>(() => page.props.auth.can.editNetwork);

const title = computed<string>(() =>
    trans('VLAN :label', { label: `${props.vlan.vid} — ${props.vlan.name}` }),
);

// --- Attaching the VLAN to the entry that has its number -------------------

const attaching = ref<boolean>(false);

const attachToEntry = (): void => {
    const entry = props.attachableEntry;

    if (!entry) {
        return;
    }

    // What the page of the entry sends for several VLANs, with this VLAN
    // alone: the same rule decides, and the answer comes back here.
    router.post(
        attach(entry.id).url,
        { vlans: [props.vlan.id], from_vlan: true },
        {
            preserveScroll: true,
            onStart: () => {
                attaching.value = true;
            },
            onFinish: () => {
                attaching.value = false;
            },
            onError: (errors) =>
                toast.error(
                    Object.values(errors)[0] ?? trans('Something went wrong.'),
                ),
        },
    );
};

const confirmingDeletion = ref<boolean>(false);

const confirmDeletion = (): void => {
    confirmingDeletion.value = false;

    // A VLAN that still has its subnet, or that ports still carry, is
    // refused by the server, with a message that says what to do first.
    router.delete(destroy(props.vlan.id).url, {
        onError: (errors) =>
            toast.error(
                Object.values(errors)[0] ?? trans('Something went wrong.'),
            ),
    });
};
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-col gap-7">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <div class="flex min-w-0 flex-col gap-3">
                <div class="flex flex-wrap items-center gap-3.5">
                    <h1 class="text-title font-semibold">{{ title }}</h1>
                    <StatusBadge
                        :status="vlan.status"
                        :label="vlan.status_label"
                    />
                </div>
                <p class="text-muted-foreground">
                    {{ $t('Site :name', { name: vlan.site.name }) }}
                </p>
            </div>
            <div v-if="canEdit" class="flex flex-wrap gap-2.5">
                <!-- The catalogue has an entry with the number of this
                     VLAN, and the site does not follow it yet: one action
                     attaches the VLAN, instead of a second entry. -->
                <Button
                    v-if="attachableEntry"
                    type="button"
                    :disabled="attaching"
                    data-test="attach-to-entry"
                    @click="attachToEntry"
                >
                    <Spinner v-if="attaching" />
                    {{
                        $t('Attach to :label', {
                            label: `${attachableEntry.vid} — ${attachableEntry.name}`,
                        })
                    }}
                </Button>
                <Button variant="outline" as-child data-test="edit-vlan">
                    <Link :href="edit(vlan.id)">{{ $t('Edit') }}</Link>
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    data-test="delete-vlan"
                    @click="confirmingDeletion = true"
                >
                    {{ $t('Delete') }}
                </Button>
            </div>
        </div>

        <Deviation :deviations="vlan.deviations" />

        <FactBand class="xl:grid-cols-4">
            <Fact :label="$t('Site')">{{ vlan.site.name }}</Fact>
            <Fact :label="$t('VID')">
                <span class="font-mono">{{ vlan.vid }}</span>
            </Fact>
            <Fact :label="$t('Catalogue')">
                <Link
                    v-if="vlan.catalog"
                    :href="showCatalogEntry(vlan.catalog.id)"
                    class="link"
                    data-test="catalog-link"
                >
                    <span class="font-mono">{{ vlan.catalog.vid }}</span>
                    — {{ vlan.catalog.name }}
                </Link>
                <span v-else class="text-muted-foreground">{{
                    $t('Outside the catalogue')
                }}</span>
            </Fact>
            <Fact :label="$t('Status')">
                <StatusBadge :status="vlan.status" :label="vlan.status_label" />
            </Fact>
        </FactBand>

        <p
            v-if="vlan.description"
            class="max-w-prose whitespace-pre-line text-muted-foreground"
        >
            {{ vlan.description }}
        </p>

        <section class="flex flex-col gap-4" data-test="vlan-subnet">
            <Heading
                variant="small"
                :title="$t('Subnet')"
                :description="$t('A VLAN carries one subnet at most.')"
            />

            <Deviation :deviations="subnetDeviations" />

            <FactBand v-if="vlan.subnet" class="xl:grid-cols-4">
                <Fact :label="$t('Subnet')">
                    <Link
                        :href="showSubnet(vlan.subnet.id)"
                        class="link font-mono font-medium"
                        data-test="subnet-link"
                        >{{ vlan.subnet.cidr }}</Link
                    >
                </Fact>
                <Fact :label="$t('Mask')">
                    <span class="font-mono">{{ vlan.subnet.mask }}</span>
                </Fact>
                <Fact :label="$t('Gateway')">
                    <span v-if="vlan.subnet.gateway" class="font-mono">{{
                        vlan.subnet.gateway
                    }}</span>
                    <span v-else class="text-muted-foreground">{{
                        $t('No gateway')
                    }}</span>
                </Fact>
                <Fact
                    :label="
                        $tc(
                            'Usable address: :count|Usable addresses: :count',
                            vlan.subnet.usable_count,
                        )
                    "
                >
                    <OccupancyBar
                        :documented="vlan.subnet.documented_count"
                        :usable="vlan.subnet.usable_count"
                    />
                </Fact>
            </FactBand>

            <div
                v-else
                class="flex flex-col items-start gap-4 rounded-lg border border-dashed p-5"
            >
                <p class="text-muted-foreground">
                    {{ $t('This VLAN has no subnet yet.') }}
                </p>
                <Button
                    v-if="canEdit"
                    variant="outline"
                    as-child
                    data-test="attach-subnet"
                >
                    <Link :href="createSubnet({ query: { vlan: vlan.id } })">{{
                        $t('Attach a subnet')
                    }}</Link>
                </Button>
            </div>
        </section>

        <section
            class="flex min-w-0 flex-col gap-3"
            :aria-label="$t('Ports')"
            data-test="vlan-ports"
        >
            <h2 class="text-lg font-semibold">
                {{ $t('Ports') }}
                <span class="font-normal text-muted-foreground">{{
                    formatCount(carrierCount)
                }}</span>
            </h2>
            <p class="max-w-prose text-sm text-muted-foreground">
                {{
                    $t(
                        'The ports and the aggregates of ports that carry this VLAN, device by device.',
                    )
                }}
            </p>

            <p
                v-if="carriers.length === 0"
                class="rounded-lg border border-dashed p-4 text-muted-foreground"
            >
                {{ $t('No port carries this VLAN.') }}
            </p>

            <div v-else class="table-frame">
                <table class="table-dense" :aria-label="$t('Ports')">
                    <thead>
                        <tr>
                            <th scope="col">{{ $t('Port or aggregate') }}</th>
                            <th scope="col">{{ $t('Member') }}</th>
                            <th scope="col">{{ $t('Tagging') }}</th>
                        </tr>
                    </thead>
                    <tbody
                        v-for="group in carriers"
                        :key="group.device.id"
                        data-test="vlan-device"
                        :data-device="group.device.name"
                    >
                        <tr class="bg-sunken">
                            <th colspan="3" scope="rowgroup" class="py-2">
                                <Link
                                    :href="showDevice(group.device.id)"
                                    class="link"
                                    >{{ group.device.name }}</Link
                                >
                                <span class="font-normal text-muted-foreground">
                                    —
                                    {{
                                        $tc(
                                            ':count port|:count ports',
                                            group.carriers.length,
                                        )
                                    }}
                                </span>
                            </th>
                        </tr>
                        <tr
                            v-for="carrier in group.carriers"
                            :key="`${carrier.kind}-${carrier.name}`"
                            data-test="vlan-carrier"
                        >
                            <td class="whitespace-nowrap">
                                <span class="font-mono text-sm font-medium">{{
                                    carrier.name
                                }}</span>
                                <Badge
                                    v-if="carrier.kind === 'aggregate'"
                                    variant="outline"
                                    class="ml-2"
                                    >{{ $t('Aggregate') }}</Badge
                                >
                            </td>
                            <td class="font-mono text-sm">
                                {{ carrier.member }}
                            </td>
                            <td class="whitespace-nowrap">
                                {{ carrier.role_label }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <ConfirmDialog
        :open="confirmingDeletion"
        :title="$t('Delete this VLAN?')"
        :description="
            $t(
                'VLAN :label will be deleted. A VLAN that still has its subnet, or that ports still carry, cannot be deleted.',
                { label: `${vlan.vid} — ${vlan.name}` },
            )
        "
        :confirm-label="$t('Delete')"
        destructive
        @confirm="confirmDeletion"
        @cancel="confirmingDeletion = false"
    />
</template>
