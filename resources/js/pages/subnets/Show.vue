<!-- torsade/resources/js/pages/subnets/Show.vue -->
<script setup lang="ts">
import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import AddressPlan from '@/components/network/AddressPlan.vue';
import Deviation from '@/components/network/Deviation.vue';
import IpRangesSection from '@/components/network/IpRangesSection.vue';
import Fact from '@/components/network/Fact.vue';
import FactBand from '@/components/network/FactBand.vue';
import FreeAddresses from '@/components/network/FreeAddresses.vue';
import IpAddressesSection from '@/components/network/IpAddressesSection.vue';
import OccupancyBar from '@/components/network/OccupancyBar.vue';
import StatusBadge from '@/components/network/StatusBadge.vue';
import VlanTag from '@/components/network/VlanTag.vue';
import { Button } from '@/components/ui/button';
import { trans } from '@/lib/i18n';
import { formatCount } from '@/lib/numbers';
import { destroy, edit, index, show } from '@/routes/subnets';
import { show as showVlan } from '@/routes/vlans';
import type {
    AddressPlan as AddressPlanData,
    BreakdownRange,
    Deviation as DeviationData,
    DeviceOption,
    FreeInterval,
    IpAddress,
    IpRange,
    JackChoices,
    NextFreeAddress,
    Option,
    Subnet,
} from '@/types';

// The working screen of a subnet: what it is, its address plan, its
// addresses, its ranges and what is left free.
const props = defineProps<{
    subnet: Subnet;
    // Where the subnet is not where the address blocks say.
    deviations: DeviationData[];
    // Whether a subnet of this kind has a gateway, and ranges.
    allowsGateway: boolean;
    allowsRanges: boolean;
    ipRanges: IpRange[];
    // The ranges of the addressing rule as they would be created in this
    // subnet, when that can be asked for; null otherwise.
    breakdown: BreakdownRange[] | null;
    rangeKinds: Option[];
    addresses: IpAddress[];
    freeIntervals: FreeInterval[];
    freeCount: number;
    nextFreeAddress: NextFreeAddress | null;
    addressPlan: AddressPlanData;
    deviceTypes: Option[];
    assignments: Option[];
    statuses: Option[];
    // The devices an address of the subnet can be attached to.
    devices: DeviceOption[];
    maxBulkLines: number;
    // The jacks of the site of the subnet the window of an address offers
    // for what is typed in its field "Jack".
    jackChoices?: JackChoices;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: trans('Subnets'), href: index() },
        { title: props.subnet.cidr, href: show(props.subnet.id) },
    ],
});

const page = usePage();

// Only decides which buttons are shown: the server checks every action.
const canEdit = computed<boolean>(() => page.props.auth.can.editNetwork);

// Where the subnet is, what kind it is and what it is for, under its
// address: "Site Siège, Segment, Bureautique".
const context = computed<string>(() =>
    [
        props.subnet.site
            ? trans('Site :name', { name: props.subnet.site.name })
            : null,
        props.subnet.kind_label,
        props.subnet.name,
    ]
        .filter(Boolean)
        .join(', '),
);

// "Use" on the next free address opens the window of a new address, filled
// in with it.
const addressesSection = ref<InstanceType<typeof IpAddressesSection> | null>(
    null,
);

// "Document this address", from the search: the window of a new address
// opens filled in with it, its form keeping its own refusals.
onMounted(() => {
    const address = new URLSearchParams(window.location.search).get('address');

    if (canEdit.value && address !== null && address !== '') {
        addressesSection.value?.addAddress(address);
    }
});

const confirmingDeletion = ref<boolean>(false);

const confirmDeletion = (): void => {
    confirmingDeletion.value = false;

    // A subnet that still holds addresses is refused by the server, with a
    // message that says what to delete first.
    router.delete(destroy(props.subnet.id).url, {
        onError: (errors) =>
            toast.error(
                Object.values(errors)[0] ?? trans('Something went wrong.'),
            ),
    });
};
</script>

<template>
    <Head :title="subnet.cidr" />

    <div class="flex flex-col gap-7">
        <header class="flex flex-wrap items-end justify-between gap-5">
            <div class="flex min-w-0 flex-col gap-3">
                <div class="flex flex-wrap items-center gap-x-3.5 gap-y-2">
                    <h1
                        class="font-mono text-2xl font-medium tracking-tight break-all sm:text-4xl"
                        data-test="subnet-cidr"
                    >
                        {{ subnet.cidr }}
                    </h1>
                    <VlanTag
                        v-if="subnet.vlan"
                        :vid="subnet.vlan.vid"
                        :name="subnet.vlan.name"
                        :href="showVlan(subnet.vlan.id)"
                    />
                    <StatusBadge
                        :status="subnet.status"
                        :label="subnet.status_label"
                    />
                </div>
                <p
                    v-if="context"
                    class="text-muted-foreground"
                    data-test="subnet-context"
                >
                    {{ context }}
                </p>
                <Deviation :deviations="deviations" />
            </div>
            <div v-if="canEdit" class="flex flex-wrap gap-2.5">
                <Button variant="outline" as-child data-test="edit-subnet">
                    <Link :href="edit(subnet.id)">{{ $t('Edit') }}</Link>
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    data-test="delete-subnet"
                    @click="confirmingDeletion = true"
                >
                    {{ $t('Delete') }}
                </Button>
            </div>
        </header>

        <FactBand data-test="subnet-summary">
            <Fact :label="$t('Mask')">
                <div class="font-mono">{{ subnet.mask }}</div>
                <div class="font-mono text-note text-muted-foreground">
                    /{{ subnet.prefix_length }}
                </div>
            </Fact>
            <!-- A link between two routers or a loopback has no gateway. -->
            <Fact v-if="allowsGateway || subnet.gateway" :label="$t('Gateway')">
                <span v-if="subnet.gateway" class="font-mono">{{
                    subnet.gateway
                }}</span>
                <span v-else class="text-muted-foreground">{{
                    $t('No gateway')
                }}</span>
            </Fact>
            <Fact :label="$t('Broadcast address')">
                <span class="font-mono">{{ subnet.broadcast }}</span>
            </Fact>
            <Fact :label="$t('Usable addresses')">
                <div class="font-mono">
                    {{ formatCount(subnet.usable_count) }}
                </div>
                <div class="font-mono text-note text-muted-foreground">
                    {{ subnet.first_usable
                    }}<template v-if="subnet.usable_count > 1">
                        – {{ subnet.last_usable }}</template
                    >
                </div>
            </Fact>
            <Fact :label="$t('Free addresses')">
                <div class="font-mono">{{ formatCount(freeCount) }}</div>
                <OccupancyBar
                    class="mt-1.5"
                    :documented="subnet.documented_count"
                    :usable="subnet.usable_count"
                />
            </Fact>
        </FactBand>

        <p
            v-if="subnet.description"
            class="max-w-prose whitespace-pre-line text-muted-foreground"
        >
            {{ subnet.description }}
        </p>

        <AddressPlan
            :subnet="subnet"
            :plan="addressPlan"
            :addresses="addresses"
        />

        <!-- The table takes the width it needs; the ranges and the free
             addresses sit beside it on a wide screen, under it otherwise. -->
        <div class="flex flex-wrap items-start gap-7">
            <div class="min-w-0 flex-[999_1_56rem]">
                <IpAddressesSection
                    ref="addressesSection"
                    :subnet="subnet"
                    :addresses="addresses"
                    :free-intervals="freeIntervals"
                    :device-types="deviceTypes"
                    :assignments="assignments"
                    :statuses="statuses"
                    :devices="devices"
                    :max-bulk-lines="maxBulkLines"
                    :jack-choices="jackChoices"
                    :can-edit="canEdit"
                />
            </div>

            <aside class="flex min-w-0 flex-[1_1_18.75rem] flex-wrap gap-7">
                <!-- Nor is it cut into ranges. -->
                <div
                    v-if="allowsRanges || ipRanges.length > 0"
                    class="min-w-0 flex-[1_1_18rem]"
                >
                    <IpRangesSection
                        :subnet="subnet"
                        :ranges="ipRanges"
                        :kinds="rangeKinds"
                        :can-edit="canEdit"
                        :breakdown="breakdown"
                    />
                </div>
                <div class="min-w-0 flex-[1_1_18rem]">
                    <FreeAddresses
                        :intervals="freeIntervals"
                        :total="freeCount"
                        :next="nextFreeAddress"
                        :can-edit="canEdit"
                        @use="addressesSection?.addAddress($event)"
                    />
                </div>
            </aside>
        </div>
    </div>

    <ConfirmDialog
        :open="confirmingDeletion"
        :title="$t('Delete this subnet?')"
        :description="
            $t(
                ':cidr and its ranges will be deleted. A subnet that still holds IP addresses cannot be deleted.',
                { cidr: subnet.cidr },
            )
        "
        :confirm-label="$t('Delete')"
        destructive
        @confirm="confirmDeletion"
        @cancel="confirmingDeletion = false"
    />
</template>
