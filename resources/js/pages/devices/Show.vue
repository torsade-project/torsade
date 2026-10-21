<!-- torsade/resources/js/pages/devices/Show.vue -->
<script setup lang="ts">
import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import AggregatesSection from '@/components/network/AggregatesSection.vue';
import AttachAddressDialog from '@/components/network/AttachAddressDialog.vue';
import Deviation from '@/components/network/Deviation.vue';
import DeviationList from '@/components/network/DeviationList.vue';
import Fact from '@/components/network/Fact.vue';
import FactBand from '@/components/network/FactBand.vue';
import IpAddressDialog from '@/components/network/IpAddressDialog.vue';
import JackPath from '@/components/network/JackPath.vue';
import LinksSection from '@/components/network/LinksSection.vue';
import MembersSection from '@/components/network/MembersSection.vue';
import PlacePath from '@/components/network/PlacePath.vue';
import PortsSection from '@/components/network/PortsSection.vue';
import StatusBadge from '@/components/network/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { trans, transChoice } from '@/lib/i18n';
import { formatCount } from '@/lib/numbers';
import { destroy, edit, index, show } from '@/routes/devices';
import {
    destroy as detachAddress,
    destroyAll as detachAllAddresses,
} from '@/routes/devices/ip-addresses';
import { show as showSite } from '@/routes/sites';
import { show as showSubnet } from '@/routes/subnets';
import { show as showVlan } from '@/routes/vlans';
import type {
    AggregateDraft,
    AttachableAddresses,
    Device,
    DeviceAddress,
    DeviceMember,
    JackChoices,
    LinkableDevices,
    LinkDeviationItem,
    LinkDraft,
    LinkLine,
    MemberLimits,
    OppositePorts,
    Option,
    Place,
    PluggableJackGroup,
    Port,
    PortAggregate,
    PortOptions,
    SubnetChoice,
    TemplateChoice,
    VlanGroup,
} from '@/types';

// A device: what it is, where it is, its addresses, which are its
// interfaces, the members it is made of, their ports and what they are
// linked to. Addresses are attached, created and detached from here;
// members, ports and links are added, modified and deleted.
const props = defineProps<{
    device: Device;
    // The boxes the device is made of, by number.
    members: DeviceMember[];
    // What the window of a member chooses among.
    templates: TemplateChoice[];
    memberRoles: Option[];
    // The rooms of the site of the device.
    rooms: Place[];
    memberLimits: MemberLimits;
    // Every port of the device, in the order of its member.
    ports: Port[];
    portOptions: PortOptions;
    // Its aggregates of ports, by name.
    aggregates: PortAggregate[];
    aggregateProtocols: Option[];
    // The aggregate to create in front of another one, asked from its
    // deviation.
    aggregateDraft: AggregateDraft | null;
    // The link to create between two ports cabled to each other, asked
    // from its deviation.
    linkDraft: LinkDraft | null;
    // Its links with other devices, the aggregated ones as one line each.
    links: LinkLine[];
    linkMedia: Option[];
    // What differs about its links and its aggregates, object by object.
    linkDeviations: LinkDeviationItem[];
    // The VLANs its ports can carry: those of its site first, then those
    // of the other sites.
    vlans: VlanGroup[];
    addresses: DeviceAddress[];
    // The subnets a new address can be created in, those of the site of
    // the device first.
    subnets: SubnetChoice[];
    deviceTypes: Option[];
    assignments: Option[];
    statuses: Option[];
    // What the window "Attach an address" found: only there once asked.
    attachable?: AttachableAddresses;
    // What the window "Link…" found, and the free ports of the device
    // chosen there: only there once asked.
    linkable?: LinkableDevices;
    oppositePorts?: OppositePorts | null;
    // The jacks the window of a new address offers for what is typed in
    // its field "Jack": only there once asked.
    jackChoices?: JackChoices;
    // The jacks of the site a port can be plugged into: only there once
    // the window "Plug into a jack" asks.
    pluggableJacks?: PluggableJackGroup[];
}>();

setLayoutProps({
    breadcrumbs: [
        { title: trans('Devices'), href: index() },
        { title: props.device.name, href: show(props.device.id) },
    ],
});

const page = usePage();

// Only decides which buttons are shown: the server checks every action.
const canEdit = computed<boolean>(() => page.props.auth.can.editNetwork);

// The manufacturer and the model together, as they are read on a label.
const make = computed<string>(() =>
    [props.device.manufacturer, props.device.model]
        .filter((part) => part !== null && part !== '')
        .join(' '),
);

// The serial number of the device is the one of a machine made of one
// box. With members, each has its own: only a number typed before is
// still shown.
const showsSerialNumber = computed<boolean>(
    () => props.members.length === 0 || props.device.serial_number !== null,
);

// The addresses that are on the network of another site than the device,
// then the VLANs of one number a switch merges, its ports, its aggregates
// and its links whose two ends do not agree: each of these leads to the
// section of this page that shows it.
const deviationItems = computed(() => [
    ...props.addresses
        .filter((address) => address.deviations.length > 0)
        .map((address) => ({
            key: `address-${address.id}`,
            label: address.address,
            href: showSubnet(address.subnet.id).url,
            technical: true,
            deviations: address.deviations,
        })),
    ...props.linkDeviations.map((item) => ({
        key: item.key,
        label: item.label,
        href: `${show(props.device.id).url}#device-${item.section}`,
        technical: true,
        deviations: item.deviations,
    })),
]);

const toastError = (errors: Record<string, string>): void => {
    toast.error(Object.values(errors)[0] ?? trans('Something went wrong.'));
};

// --- Addresses -------------------------------------------------------------

const attachOpen = ref<boolean>(false);
const addressDialogOpen = ref<boolean>(false);

const detach = (address: DeviceAddress): void => {
    // The address stays documented in its subnet: nothing is lost, and it
    // can be attached again.
    router.delete(detachAddress([props.device.id, address.id]).url, {
        preserveScroll: true,
        onError: toastError,
    });
};

const confirmingDetachAll = ref<boolean>(false);

const detachAll = (): void => {
    confirmingDetachAll.value = false;

    router.delete(detachAllAddresses(props.device.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            refusal.value = '';
        },
        onError: toastError,
    });
};

// --- Deletion --------------------------------------------------------------

const confirmingDeletion = ref<boolean>(false);
// Why the device could not be deleted: shown on the page, with the action
// that lifts the refusal.
const refusal = ref<string>('');

const confirmDeletion = (): void => {
    confirmingDeletion.value = false;

    // A device that still has addresses is refused by the server, with a
    // message that says to detach them first.
    router.delete(destroy(props.device.id).url, {
        preserveScroll: true,
        onError: (errors) => {
            refusal.value =
                Object.values(errors)[0] ?? trans('Something went wrong.');
        },
    });
};
</script>

<template>
    <Head :title="$t('Device :name', { name: device.name })" />

    <div class="flex flex-col gap-7">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <div class="flex min-w-0 flex-col gap-3">
                <div class="flex flex-wrap items-center gap-3.5">
                    <h1 class="text-title font-semibold">{{ device.name }}</h1>
                    <span
                        class="inline-flex h-7.5 items-center rounded-sm border border-border-strong px-3 text-sm font-medium"
                        data-test="device-type"
                    >
                        <span class="sr-only">{{ $t('Type') }}</span>
                        {{ device.type_label }}
                    </span>
                    <StatusBadge
                        :status="device.status"
                        :label="device.status_label"
                    />
                </div>
            </div>
            <div v-if="canEdit" class="flex flex-wrap gap-2.5">
                <Button variant="outline" as-child data-test="edit-device">
                    <Link :href="edit(device.id)">{{ $t('Edit') }}</Link>
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    data-test="delete-device"
                    @click="confirmingDeletion = true"
                >
                    {{ $t('Delete') }}
                </Button>
            </div>
        </div>

        <!-- A refused deletion says what is in the way, and leads to the
             action that removes it. -->
        <div
            v-if="refusal"
            role="alert"
            class="flex flex-wrap items-center gap-x-5 gap-y-3 rounded-lg border border-destructive bg-error-surface px-4 py-3 text-error"
            data-test="deletion-refused"
        >
            <p class="flex items-start gap-2">
                <TriangleAlert
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <span>{{ refusal }}</span>
            </p>
            <Button
                v-if="addresses.length > 0"
                type="button"
                variant="outline"
                size="sm"
                data-test="refusal-detach-all"
                @click="confirmingDetachAll = true"
            >
                {{ $t('Detach all the addresses') }}
            </Button>
        </div>

        <FactBand
            :class="showsSerialNumber ? 'xl:grid-cols-4' : 'xl:grid-cols-3'"
        >
            <Fact :label="$t('Site')">
                <Link
                    :href="showSite(device.site.id)"
                    class="link"
                    data-test="device-site"
                    >{{ device.site.name }}</Link
                >
            </Fact>
            <Fact :label="$t('Place')">
                <PlacePath
                    v-if="device.room"
                    :path="device.room.path"
                    :technical="device.room.is_technical"
                />
                <span v-else class="text-muted-foreground">{{
                    $t('Not specified')
                }}</span>
            </Fact>
            <Fact :label="$t('Manufacturer and model')">
                <span v-if="make" data-test="device-make">{{ make }}</span>
                <span v-else class="text-muted-foreground">{{
                    $t('Not specified')
                }}</span>
            </Fact>
            <Fact v-if="showsSerialNumber" :label="$t('Serial number')">
                <span v-if="device.serial_number" class="font-mono break-all">{{
                    device.serial_number
                }}</span>
                <span v-else class="text-muted-foreground">{{
                    $t('Not specified')
                }}</span>
            </Fact>
        </FactBand>

        <p
            v-if="device.description"
            class="max-w-prose whitespace-pre-line text-muted-foreground"
        >
            {{ device.description }}
        </p>

        <section
            class="flex min-w-0 flex-col gap-3"
            :aria-label="$t('Addresses')"
            data-test="device-addresses"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">
                    {{ $t('Addresses') }}
                    <span class="font-normal text-muted-foreground">{{
                        formatCount(addresses.length)
                    }}</span>
                </h2>
                <div v-if="canEdit" class="flex flex-wrap gap-2.5">
                    <Button
                        type="button"
                        size="sm"
                        data-test="attach-address"
                        @click="attachOpen = true"
                    >
                        {{ $t('Attach an address') }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="subnets.length === 0"
                        data-test="new-device-address"
                        @click="addressDialogOpen = true"
                    >
                        {{ $t('New address') }}
                    </Button>
                    <Button
                        v-if="addresses.length > 0"
                        type="button"
                        variant="outline"
                        size="sm"
                        data-test="detach-all"
                        @click="confirmingDetachAll = true"
                    >
                        {{ $t('Detach all the addresses') }}
                    </Button>
                </div>
            </div>

            <p class="max-w-prose text-sm text-muted-foreground">
                {{
                    $t(
                        'The IP addresses of the device: its interfaces. Each one keeps its host name and stays documented in its subnet.',
                    )
                }}
            </p>

            <p
                v-if="addresses.length === 0"
                class="rounded-lg border border-dashed p-4 text-muted-foreground"
            >
                {{ $t('No address is attached to this device yet.') }}
            </p>

            <div v-else class="table-frame">
                <table class="table-dense" :aria-label="$t('Addresses')">
                    <thead>
                        <tr>
                            <th scope="col">{{ $t('Address') }}</th>
                            <th scope="col">{{ $t('Subnet') }}</th>
                            <th scope="col">{{ $t('VLAN') }}</th>
                            <th scope="col">{{ $t('Host name') }}</th>
                            <th scope="col">{{ $t('Status') }}</th>
                            <th v-if="canEdit" scope="col" class="text-right">
                                {{ $t('Actions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="address in addresses"
                            :key="address.id"
                            data-test="device-address-row"
                        >
                            <td>
                                <span
                                    class="font-mono text-sm font-medium whitespace-nowrap"
                                    >{{ address.address }}</span
                                >
                                <Deviation
                                    class="mt-1 min-w-44"
                                    :deviations="address.deviations"
                                    compact
                                />
                            </td>
                            <td>
                                <Link
                                    :href="showSubnet(address.subnet.id)"
                                    class="link font-mono text-sm whitespace-nowrap"
                                    >{{ address.subnet.cidr }}</Link
                                >
                                <div
                                    v-if="address.subnet.site"
                                    class="text-note text-muted-foreground"
                                >
                                    {{ address.subnet.site }}
                                </div>
                            </td>
                            <td class="whitespace-nowrap">
                                <Link
                                    v-if="address.vlan"
                                    :href="showVlan(address.vlan.id)"
                                    class="link"
                                >
                                    <span class="font-mono text-sm">{{
                                        address.vlan.vid
                                    }}</span>
                                    — {{ address.vlan.name }}
                                </Link>
                                <span v-else class="text-muted-foreground">{{
                                    $t('None')
                                }}</span>
                            </td>
                            <td class="min-w-36">
                                <div class="whitespace-nowrap">
                                    {{ address.hostname }}
                                </div>
                                <!-- The jack the machine is plugged into,
                                     under its host name. -->
                                <div
                                    v-if="address.jack"
                                    class="text-note"
                                    data-test="address-jack"
                                >
                                    <span class="sr-only">{{
                                        $t('Jack')
                                    }}</span>
                                    <JackPath :path="address.jack.path" />
                                </div>
                            </td>
                            <td>
                                <StatusBadge
                                    :status="address.status"
                                    :label="address.status_label"
                                />
                            </td>
                            <td v-if="canEdit" class="py-1 text-right">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    :data-test="`detach-${address.address}`"
                                    @click="detach(address)"
                                >
                                    {{ $t('Detach') }}
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <DeviationList
            :items="deviationItems"
            :description="
                $t(
                    'The addresses of this device that are on the network of another site or are not served as declared, the VLANs of one number a switch merges, its links whose two ends do not agree, and its ports whose cabling differs from what is declared around them. A deviation refuses nothing: it shows the network as it is.',
                )
            "
        />

        <MembersSection
            v-if="members.length > 0"
            :device="{ id: device.id, name: device.name }"
            :members="members"
            :templates="templates"
            :roles="memberRoles"
            :rooms="rooms"
            :limits="memberLimits"
            :can-edit="canEdit"
        />

        <AggregatesSection
            v-if="members.length > 0"
            :device="{ id: device.id, name: device.name }"
            :members="members"
            :ports="ports"
            :aggregates="aggregates"
            :protocols="aggregateProtocols"
            :vlans="vlans"
            :can-edit="canEdit"
            :draft="aggregateDraft"
        />

        <LinksSection
            v-if="members.length > 0"
            :device="{
                id: device.id,
                name: device.name,
                site_id: device.site.id,
            }"
            :lines="links"
            :media="linkMedia"
            :statuses="statuses"
            :can-edit="canEdit"
        />

        <PortsSection
            :device="{
                id: device.id,
                name: device.name,
                serial_number: device.serial_number,
                retired: device.status === 'retired',
            }"
            :members="members"
            :ports="ports"
            :aggregates="aggregates"
            :templates="templates"
            :options="portOptions"
            :vlans="vlans"
            :link-media="linkMedia"
            :link-statuses="statuses"
            :linkable="linkable"
            :opposite-ports="oppositePorts"
            :pluggable-jacks="pluggableJacks"
            :link-draft="linkDraft"
            :can-edit="canEdit"
        />
    </div>

    <AttachAddressDialog
        :open="attachOpen"
        :device="device"
        :attachable="attachable"
        @close="attachOpen = false"
    />

    <IpAddressDialog
        :open="addressDialogOpen"
        :address="null"
        :device="{ id: device.id, name: device.name, site_id: device.site.id }"
        :subnets="subnets"
        :device-types="deviceTypes"
        :assignments="assignments"
        :statuses="statuses"
        :jack-choices="jackChoices"
        @close="addressDialogOpen = false"
    />

    <ConfirmDialog
        :open="confirmingDetachAll"
        :title="$t('Detach all the addresses?')"
        :description="
            transChoice(
                'The address of :name will no longer be attached to it. It stays documented in its subnet, with its host name.|The :count addresses of :name will no longer be attached to it. They stay documented in their subnets, with their host names.',
                addresses.length,
                { name: device.name },
            )
        "
        :confirm-label="$t('Detach')"
        @confirm="detachAll"
        @cancel="confirmingDetachAll = false"
    />

    <ConfirmDialog
        :open="confirmingDeletion"
        :title="$t('Delete this device?')"
        :description="
            $t(
                ':name will be deleted. A device that still has addresses cannot be deleted: they are detached first.',
                { name: device.name },
            )
        "
        :confirm-label="$t('Delete')"
        destructive
        @confirm="confirmDeletion"
        @cancel="confirmingDeletion = false"
    />
</template>
