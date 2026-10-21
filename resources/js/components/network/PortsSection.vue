<!-- torsade/resources/js/components/network/PortsSection.vue -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Cable, Plug, Unplug } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Deviation from '@/components/network/Deviation.vue';
import JackPlugDialog from '@/components/network/JackPlugDialog.vue';
import LinkDialog from '@/components/network/LinkDialog.vue';
import PortDialog from '@/components/network/PortDialog.vue';
import PortModeDialog from '@/components/network/PortModeDialog.vue';
import PortSeriesDialog from '@/components/network/PortSeriesDialog.vue';
import TemplateChoiceDialog from '@/components/network/TemplateChoiceDialog.vue';
import RowActions from '@/components/RowActions.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useLocationTarget } from '@/composables/useLocationTarget';
import { trans, transChoice } from '@/lib/i18n';
import { formatCount } from '@/lib/numbers';
import { show as showDevice } from '@/routes/devices';
import { destroyAll as unplugAll } from '@/routes/devices/jack-cords';
import { destroyAll as unpatchAll } from '@/routes/devices/patch-cords';
import { destroy as unplugCord } from '@/routes/jack-cords';
import { index as jacksIndex } from '@/routes/jacks';
import { show as showPanel } from '@/routes/patch-panels';
import { destroy, destroyMany, updateMany } from '@/routes/ports';
import type {
    DeviceMember,
    LinkableDevices,
    LinkDraft,
    OppositePorts,
    Option,
    PluggableJackGroup,
    Port,
    PortAggregate,
    PortOptions,
    TemplateChoice,
    VlanGroup,
} from '@/types';

// The ports of a device, grouped by member. A device without a member has
// none: choosing a template, or adding ports, gives it its first member.
// Ports are ticked one by one, by range or a whole member at a time, and
// what is ticked is changed, linked or deleted together. A port is linked
// to a port of another device from its line.
const props = defineProps<{
    // A retired device is plugged into no jack: its ports offer none.
    device: {
        id: number;
        name: string;
        serial_number?: string | null;
        retired?: boolean;
    };
    members: DeviceMember[];
    // Every port of the device, in the order of its member.
    ports: Port[];
    // Its aggregates: a port of an aggregate shows what the aggregate
    // carries.
    aggregates: PortAggregate[];
    templates: TemplateChoice[];
    options: PortOptions;
    // The VLANs a port can carry: those of the site of the device first,
    // then those of the other sites.
    vlans: VlanGroup[];
    // What the window "Link…" chooses among: the media and the statuses of
    // a link, the devices found for what was typed there and the free
    // ports of the one chosen.
    linkMedia: Option[];
    linkStatuses: Option[];
    linkable?: LinkableDevices;
    oppositePorts?: OppositePorts | null;
    // The jacks of the site a port can be plugged into, asked for by the
    // window "Plug into a jack".
    pluggableJacks?: PluggableJackGroup[];
    // The link to create between a port and the port it is cabled to,
    // asked from its deviation.
    linkDraft?: LinkDraft | null;
    canEdit: boolean;
}>();

// The ports of each member, in the order of the members.
const groups = computed<{ member: DeviceMember; ports: Port[] }[]>(() =>
    props.members.map((member) => ({
        member,
        ports: props.ports.filter((port) => port.member_id === member.id),
    })),
);

// The port a link leads to, as the page opens: marked in the table.
const target = useLocationTarget();

// Every port as the table shows them, from top to bottom.
const shown = computed<Port[]>(() =>
    groups.value.flatMap((group) => group.ports),
);

const toastError = (errors: Record<string, string>): void => {
    toast.error(Object.values(errors)[0] ?? trans('Something went wrong.'));
};

// --- Every cord at once ------------------------------------------------------

// How many cords a confirmation names at most.
const CORDS_NAMED = 10;

// The ports that have a cord, in the order of the table.
const patched = computed<Port[]>(() =>
    shown.value.filter((port) => port.cord !== null),
);

const confirmingUnpatchAll = ref<boolean>(false);
// Kept while the window fades out, so that its words do not change.
const unpatchAllCount = ref<number>(0);
const unpatchAllEnds = ref<string>('');

// The cords about to be taken away, by their two ends: "PB-B 1 ↔
// sw-etage-1 › 1/0/1", ten at most, then how many more.
const askUnpatchAll = (): void => {
    const ends = patched.value.map(
        (port) =>
            `${port.cord?.panel_port.name ?? ''} ↔ ${props.device.name} › ${port.name}`,
    );
    const listed = ends.slice(0, CORDS_NAMED).join(', ');

    unpatchAllCount.value = ends.length;
    unpatchAllEnds.value =
        ends.length > CORDS_NAMED
            ? transChoice(':items and :count more', ends.length - CORDS_NAMED, {
                  items: listed,
              })
            : listed;
    confirmingUnpatchAll.value = true;
};

const unpatchAllPorts = (): void => {
    confirmingUnpatchAll.value = false;

    router.delete(unpatchAll(props.device.id).url, {
        preserveScroll: true,
        onError: toastError,
    });
};

// --- Jack cords ------------------------------------------------------------

// The ports plugged into a jack, in the order of the table.
const plugged = computed<Port[]>(() =>
    shown.value.filter((port) => port.jack_cord !== null),
);

const confirmingUnplugAll = ref<boolean>(false);
// Kept while the window fades out, so that its words do not change.
const unplugAllCount = ref<number>(0);
const unplugAllEnds = ref<string>('');

// The cords about to be taken away, by their two ends: "1/0/1 ↔ B.07", ten
// at most, then how many more.
const askUnplugAll = (): void => {
    const ends = plugged.value.map(
        (port) => `${port.name} ↔ ${port.jack_cord?.jack.label ?? ''}`,
    );
    const listed = ends.slice(0, CORDS_NAMED).join(', ');

    unplugAllCount.value = ends.length;
    unplugAllEnds.value =
        ends.length > CORDS_NAMED
            ? transChoice(':items and :count more', ends.length - CORDS_NAMED, {
                  items: listed,
              })
            : listed;
    confirmingUnplugAll.value = true;
};

const unplugAllPorts = (): void => {
    confirmingUnplugAll.value = false;

    router.delete(unplugAll(props.device.id).url, {
        preserveScroll: true,
        onError: toastError,
    });
};

// The port the window "Plug into a jack" is open for.
const portToPlug = ref<Port | null>(null);
const plugging = ref<boolean>(false);

const plugOne = (port: Port): void => {
    portToPlug.value = port;
    plugging.value = true;
};

// The port about to be unplugged from its jack.
const portToUnplug = ref<Port | null>(null);

const confirmUnplug = (): void => {
    const cord = portToUnplug.value?.jack_cord;

    portToUnplug.value = null;

    if (!cord) {
        return;
    }

    router.delete(unplugCord(cord.id).url, {
        preserveScroll: true,
        onError: toastError,
    });
};

// Where a jack is listed: the list of jacks of its floor, at its line.
const jackHref = (jack: { id: number; floor_id: number }): string =>
    `${jacksIndex({ query: { floor: jack.floor_id } }).url}#jack-${jack.id}`;

// --- Selection -------------------------------------------------------------

const selected = ref<Set<number>>(new Set());
// The port ticked or unticked last: a click with the Shift key does the
// same to every port between it and the one clicked.
const anchor = ref<number | null>(null);

// A port that is gone is no longer ticked.
watch(
    () => props.ports,
    (ports) => {
        const ids = new Set(ports.map((port) => port.id));

        selected.value = new Set(
            [...selected.value].filter((id) => ids.has(id)),
        );

        if (anchor.value !== null && !ids.has(anchor.value)) {
            anchor.value = null;
        }
    },
);

const toggle = (port: Port, event: MouseEvent): void => {
    const tick = !selected.value.has(port.id);
    const next = new Set(selected.value);
    const ids = shown.value.map((one) => one.id);
    const from = anchor.value === null ? -1 : ids.indexOf(anchor.value);
    const to = ids.indexOf(port.id);

    const range =
        event.shiftKey && from !== -1
            ? ids.slice(Math.min(from, to), Math.max(from, to) + 1)
            : [port.id];

    for (const id of range) {
        if (tick) {
            next.add(id);
        } else {
            next.delete(id);
        }
    }

    selected.value = next;
    anchor.value = port.id;
};

const allOf = (ports: Port[]): boolean =>
    ports.length > 0 && ports.every((port) => selected.value.has(port.id));

const someOf = (ports: Port[]): boolean =>
    ports.some((port) => selected.value.has(port.id));

// The whole member: ticked when some of its ports are not, unticked when
// all of them are.
const toggleMember = (ports: Port[]): void => {
    const tick = !allOf(ports);
    const next = new Set(selected.value);

    for (const port of ports) {
        if (tick) {
            next.add(port.id);
        } else {
            next.delete(port.id);
        }
    }

    selected.value = next;
    anchor.value = null;
};

const clearSelection = (): void => {
    selected.value = new Set();
    anchor.value = null;
};

// The ports ticked, in the order of the table.
const selectedIds = computed<number[]>(() =>
    shown.value
        .filter((port) => selected.value.has(port.id))
        .map((port) => port.id),
);

// --- Actions on the selection ----------------------------------------------

const busy = ref<boolean>(false);

const setEnabled = (enabled: boolean): void => {
    // Everything ticked, or nothing: the server refuses the whole action
    // when one port is in the way.
    router.patch(
        updateMany(props.device.id).url,
        { ports: selectedIds.value, action: enabled ? 'enable' : 'disable' },
        {
            preserveScroll: true,
            onStart: () => {
                busy.value = true;
            },
            onFinish: () => {
                busy.value = false;
            },
            onError: toastError,
        },
    );
};

const settingMode = ref<boolean>(false);
const confirmingModeRemoval = ref<boolean>(false);

const clearMode = (): void => {
    confirmingModeRemoval.value = false;

    router.patch(
        updateMany(props.device.id).url,
        { ports: selectedIds.value, action: 'clear_mode' },
        {
            preserveScroll: true,
            onStart: () => {
                busy.value = true;
            },
            onFinish: () => {
                busy.value = false;
            },
            onError: toastError,
        },
    );
};

const confirmingSelectionDeletion = ref<boolean>(false);

const deleteSelection = (): void => {
    confirmingSelectionDeletion.value = false;

    router.delete(destroyMany(props.device.id).url, {
        data: { ports: selectedIds.value },
        preserveScroll: true,
        onStart: () => {
            busy.value = true;
        },
        onFinish: () => {
            busy.value = false;
        },
        onError: toastError,
    });
};

// --- One port --------------------------------------------------------------

const portToEdit = ref<Port | null>(null);
const portDialogOpen = ref<boolean>(false);

const openPort = (port: Port): void => {
    portToEdit.value = port;
    portDialogOpen.value = true;
};

const portToDelete = ref<Port | null>(null);

const confirmDeletion = (): void => {
    const port = portToDelete.value;

    portToDelete.value = null;

    if (!port) {
        return;
    }

    router.delete(destroy(port.id).url, {
        preserveScroll: true,
        onError: toastError,
    });
};

// --- Links -----------------------------------------------------------------

// The ports the window "Link…" is open for: one from its line, or those
// ticked, in the order of the table.
const portsToLink = ref<Port[]>([]);
const linking = ref<boolean>(false);

// Whether the window links the ports ticked: once they are linked, they
// are no longer ticked.
const linkingSelection = ref<boolean>(false);

// The port in front chosen when the window opens from a deviation.
const linkDraftToOpen = ref<LinkDraft['opposite'] | null>(null);

const linkOne = (port: Port): void => {
    portsToLink.value = [port];
    linkingSelection.value = false;
    linkDraftToOpen.value = null;
    linking.value = true;
};

const linkSelection = (): void => {
    portsToLink.value = shown.value.filter((port) =>
        selected.value.has(port.id),
    );
    linkingSelection.value = true;
    linkDraftToOpen.value = null;
    linking.value = true;
};

// The link to create comes in the address of the page, from a deviation.
// Once its window is open, the address forgets it: the page, found again
// after another action on it, does not open the window once more.
const forgetLinkDraft = (): void => {
    const address = new URL(window.location.href);

    address.searchParams.delete('link_port');
    address.searchParams.delete('link_to');

    router.replace({
        url: `${address.pathname}${address.search}${address.hash}`,
        props: (current) => ({ ...current, linkDraft: null }),
        preserveScroll: true,
        preserveState: true,
    });
};

watch(
    () => props.linkDraft,
    (draft) => {
        const port = props.ports.find((one) => one.id === draft?.port_id);

        if (draft && port && props.canEdit) {
            portsToLink.value = [port];
            linkingSelection.value = false;
            linkDraftToOpen.value = draft.opposite;
            linking.value = true;
            void nextTick(forgetLinkDraft);
        }
    },
    { immediate: true },
);

const linked = (): void => {
    if (linkingSelection.value) {
        clearSelection();
    }
};

// --- New ports -------------------------------------------------------------

const choosingTemplate = ref<boolean>(false);
const addingSeries = ref<boolean>(false);

// What a port can power: the standard, and the word of the manufacturer
// when there is one.
const poeOf = (port: Port): string | null => {
    if (port.poe_type === 'none') {
        return null;
    }

    return port.poe_label
        ? `${port.poe_type_label} (${port.poe_label})`
        : port.poe_type_label;
};

// The mode and the VLANs of a port of an aggregate are those of the
// aggregate: "via Po1: Trunk, native 99: 10, 20".
const viaAggregate = (aggregate: { id: number; name: string }): string => {
    const summary =
        props.aggregates.find((one) => one.id === aggregate.id)?.summary ??
        null;

    return summary === null
        ? trans('via :name: no mode', { name: aggregate.name })
        : trans('via :name: :summary', { name: aggregate.name, summary });
};

const columnCount = computed<number>(() => (props.canEdit ? 9 : 7));
</script>

<template>
    <section
        id="device-ports"
        class="flex min-w-0 scroll-mt-4 flex-col gap-3"
        :aria-label="$t('Ports')"
        data-test="device-ports"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold">
                {{ $t('Ports') }}
                <span
                    v-if="members.length > 0"
                    class="font-normal text-muted-foreground"
                    >{{ formatCount(ports.length) }}</span
                >
            </h2>
            <div
                v-if="canEdit && members.length > 0"
                class="flex flex-wrap gap-2.5"
            >
                <Button
                    type="button"
                    size="sm"
                    data-test="add-ports"
                    @click="addingSeries = true"
                >
                    {{ $t('Add ports') }}
                </Button>
                <!-- What moving the device to another site asks for
                     first: a cord does not join two sites. Each is offered
                     only when there is something to take away. -->
                <Button
                    v-if="patched.length > 0"
                    type="button"
                    variant="outline"
                    size="sm"
                    data-test="unpatch-all"
                    @click="askUnpatchAll"
                >
                    {{ $t('Unpatch all its ports') }}
                </Button>
                <Button
                    v-if="plugged.length > 0"
                    type="button"
                    variant="outline"
                    size="sm"
                    data-test="unplug-all"
                    @click="askUnplugAll"
                >
                    {{ $t('Unplug all its ports') }}
                </Button>
            </div>
        </div>

        <!-- A device without a member has no port yet. -->
        <div
            v-if="members.length === 0"
            class="flex flex-wrap items-center justify-between gap-x-5 gap-y-3 rounded-lg border border-dashed p-4"
        >
            <p class="text-muted-foreground">
                {{ $t('This device has no port yet.') }}
            </p>
            <div v-if="canEdit" class="flex flex-wrap gap-2.5">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-test="choose-template"
                    @click="choosingTemplate = true"
                >
                    {{ $t('Choose a template') }}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-test="add-ports"
                    @click="addingSeries = true"
                >
                    {{ $t('Add ports') }}
                </Button>
            </div>
        </div>

        <template v-else>
            <!-- What is done to the ports ticked. It stays in sight while
                 a long table is scrolled. -->
            <div
                v-if="canEdit && ports.length > 0"
                class="sticky top-0 z-10 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border bg-card px-3.5 py-2"
                role="group"
                :aria-label="$t('Actions on the ports selected')"
                data-test="port-actions"
            >
                <span
                    class="min-w-44 text-sm"
                    aria-live="polite"
                    data-test="selected-ports"
                >
                    {{
                        selected.size === 0
                            ? $t('No port selected')
                            : $tc(
                                  ':count port selected|:count ports selected',
                                  selected.size,
                              )
                    }}
                </span>
                <div class="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="selected.size === 0 || busy"
                        data-test="set-port-mode"
                        @click="settingMode = true"
                    >
                        {{ $t('Mode and VLANs') }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="selected.size === 0 || busy"
                        data-test="clear-port-mode"
                        @click="confirmingModeRemoval = true"
                    >
                        {{ $t('Remove the mode') }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="selected.size === 0 || busy"
                        data-test="link-ports"
                        @click="linkSelection"
                    >
                        {{ $t('Link as a series') }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="selected.size === 0 || busy"
                        data-test="enable-ports"
                        @click="setEnabled(true)"
                    >
                        {{ $t('Enable') }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="selected.size === 0 || busy"
                        data-test="disable-ports"
                        @click="setEnabled(false)"
                    >
                        {{ $t('Disable') }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="selected.size === 0 || busy"
                        data-test="delete-ports"
                        @click="confirmingSelectionDeletion = true"
                    >
                        {{ $t('Delete') }}
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :disabled="selected.size === 0"
                        data-test="clear-port-selection"
                        @click="clearSelection"
                    >
                        {{ $t('Deselect all') }}
                    </Button>
                </div>
            </div>

            <div class="table-frame">
                <table class="table-dense" :aria-label="$t('Ports')">
                    <thead>
                        <tr>
                            <th v-if="canEdit" scope="col" class="w-9">
                                <span class="sr-only">{{
                                    $t('Selection')
                                }}</span>
                            </th>
                            <th scope="col">{{ $t('Name') }}</th>
                            <th scope="col">{{ $t('State') }}</th>
                            <th scope="col">{{ $t('Mode and VLANs') }}</th>
                            <!-- On two lines if need be: the column stays as
                                 narrow as what it holds. -->
                            <th scope="col" class="whitespace-normal">
                                {{ $t('Link and cord') }}
                            </th>
                            <th scope="col" class="whitespace-normal">
                                {{ $t('Connector and speed') }}
                            </th>
                            <th scope="col">{{ $t('PoE') }}</th>
                            <th scope="col">{{ $t('Description') }}</th>
                            <th v-if="canEdit" scope="col" class="text-right">
                                {{ $t('Actions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        v-for="group in groups"
                        :key="group.member.id"
                        :data-test="`member-ports-${group.member.position}`"
                    >
                        <tr class="bg-sunken">
                            <th
                                :colspan="columnCount"
                                scope="rowgroup"
                                class="py-2 text-left"
                            >
                                <div
                                    class="flex flex-wrap items-center gap-x-5 gap-y-1.5"
                                >
                                    <span>
                                        {{
                                            $t('Member :position', {
                                                position: group.member.position,
                                            })
                                        }}
                                        <span
                                            class="font-normal text-muted-foreground"
                                        >
                                            — {{ group.member.role_label }},
                                            {{
                                                $tc(
                                                    ':count port|:count ports',
                                                    group.ports.length,
                                                )
                                            }}
                                        </span>
                                    </span>
                                    <label
                                        v-if="canEdit && group.ports.length > 0"
                                        class="flex items-center gap-2 font-normal"
                                    >
                                        <input
                                            type="checkbox"
                                            class="size-4.5 shrink-0 rounded-xs accent-primary"
                                            :checked="allOf(group.ports)"
                                            :indeterminate="
                                                someOf(group.ports) &&
                                                !allOf(group.ports)
                                            "
                                            :data-test="`select-member-${group.member.position}`"
                                            @change="toggleMember(group.ports)"
                                        />
                                        <span>{{
                                            $t('The whole member')
                                        }}</span>
                                    </label>
                                </div>
                            </th>
                        </tr>
                        <tr v-if="group.ports.length === 0">
                            <td
                                :colspan="columnCount"
                                class="text-muted-foreground"
                            >
                                {{ $t('This member has no port yet.') }}
                            </td>
                        </tr>
                        <tr
                            v-for="port in group.ports"
                            :id="`port-${port.id}`"
                            :key="port.id"
                            :class="
                                selected.has(port.id) ||
                                target === `port-${port.id}`
                                    ? 'bg-accent'
                                    : undefined
                            "
                            data-test="port-row"
                            :data-port="port.name"
                        >
                            <td v-if="canEdit" class="w-9">
                                <input
                                    type="checkbox"
                                    class="block size-4.5 shrink-0 rounded-xs accent-primary"
                                    :checked="selected.has(port.id)"
                                    :aria-label="
                                        $t('Select the port :name', {
                                            name: port.name,
                                        })
                                    "
                                    @click="toggle(port, $event)"
                                />
                            </td>
                            <td
                                class="font-mono text-sm font-medium whitespace-nowrap"
                            >
                                {{ port.name }}
                            </td>
                            <td class="whitespace-nowrap">
                                <span
                                    v-if="port.enabled"
                                    class="text-muted-foreground"
                                    >{{ $t('Enabled (masculine)') }}</span
                                >
                                <Badge v-else variant="outline">{{
                                    $t('Disabled (masculine)')
                                }}</Badge>
                            </td>
                            <td class="min-w-44">
                                <!-- The mode and the VLANs of a port of an
                                     aggregate are those of the aggregate,
                                     told as a secondary text. -->
                                <span
                                    v-if="port.aggregate"
                                    class="text-muted-foreground"
                                    data-test="port-via-aggregate"
                                    >{{ viaAggregate(port.aggregate) }}</span
                                >
                                <span v-else-if="port.summary">{{
                                    port.summary
                                }}</span>
                                <span v-else class="text-muted-foreground">{{
                                    $t('None')
                                }}</span>
                            </td>
                            <!-- What the port is plugged into: the
                                 device, and under it the port at the
                                 other end, so that the column stays
                                 narrow in a table that is already wide. -->
                            <td class="whitespace-nowrap">
                                <div v-if="port.link" data-test="port-link">
                                    <Link
                                        :href="showDevice(port.link.device.id)"
                                        class="link"
                                        >{{ port.link.device.name }}</Link
                                    >
                                    <div
                                        class="text-note text-muted-foreground"
                                    >
                                        ›
                                        <span class="font-mono">{{
                                            port.link.port
                                        }}</span>
                                    </div>
                                </div>
                                <!-- The cord of the port, towards the jack of
                                     the port of the panel when it has one:
                                     "patched to B.15". -->
                                <div
                                    v-if="port.cord"
                                    class="text-note"
                                    data-test="port-cord"
                                >
                                    <span class="text-muted-foreground">{{
                                        $t('patched to')
                                    }}</span>
                                    {{ ' ' }}
                                    <Link
                                        :href="
                                            showPanel(
                                                port.cord.panel_port.panel_id,
                                            )
                                        "
                                        class="link font-mono"
                                        :title="port.cord.panel_port.name"
                                        >{{
                                            port.cord.jack?.label ??
                                            port.cord.panel_port.name
                                        }}</Link
                                    >
                                </div>
                                <!-- The jack the port is plugged into, on the
                                     side of the desk: "plugged into B.07",
                                     the whole place of the jack in the
                                     tooltip. -->
                                <div
                                    v-if="port.jack_cord"
                                    class="text-note"
                                    data-test="port-jack-cord"
                                >
                                    <span class="text-muted-foreground">{{
                                        $t('plugged into')
                                    }}</span>
                                    {{ ' ' }}
                                    <Link
                                        :href="jackHref(port.jack_cord.jack)"
                                        class="link font-mono"
                                        :title="port.jack_cord.jack.path"
                                        >{{ port.jack_cord.jack.label }}</Link
                                    >
                                </div>
                                <Deviation
                                    class="mt-1 whitespace-normal"
                                    :deviations="port.deviations"
                                    compact
                                />
                            </td>
                            <td class="whitespace-nowrap">
                                {{ port.connector_label }}
                                <span class="text-muted-foreground"
                                    >· {{ port.max_speed_label }}</span
                                >
                            </td>
                            <td class="whitespace-nowrap">
                                {{ poeOf(port) }}
                            </td>
                            <td class="min-w-28">{{ port.description }}</td>
                            <td v-if="canEdit" class="py-1">
                                <RowActions
                                    :edit-test="`edit-port-${port.name}`"
                                    :delete-test="`delete-port-${port.name}`"
                                    @edit="openPort(port)"
                                    @delete="portToDelete = port"
                                >
                                    <!-- A port has one connector: a port
                                         patched to a panel is plugged into
                                         no jack. A plugged port is
                                         unplugged from here. -->
                                    <Button
                                        v-if="port.jack_cord"
                                        type="button"
                                        variant="ghost"
                                        size="icon-xs"
                                        :aria-label="
                                            $t(
                                                'Unplug the port :port from its jack',
                                                { port: port.name },
                                            )
                                        "
                                        :title="$t('Unplug')"
                                        :data-test="`unplug-port-${port.name}`"
                                        @click="portToUnplug = port"
                                    >
                                        <Unplug aria-hidden="true" />
                                    </Button>
                                    <Button
                                        v-else
                                        type="button"
                                        variant="ghost"
                                        size="icon-xs"
                                        :class="
                                            port.cord || device.retired
                                                ? 'invisible'
                                                : ''
                                        "
                                        :disabled="
                                            port.cord !== null ||
                                            device.retired === true
                                        "
                                        :aria-hidden="
                                            port.cord || device.retired
                                                ? 'true'
                                                : undefined
                                        "
                                        :aria-label="
                                            $t(
                                                'Plug the port :port into a jack',
                                                { port: port.name },
                                            )
                                        "
                                        :title="$t('Plug into a jack')"
                                        :data-test="`plug-port-${port.name}`"
                                        @click="plugOne(port)"
                                    >
                                        <Plug aria-hidden="true" />
                                    </Button>
                                    <!-- A port has one link at most: a
                                         linked port offers no other. -->
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-xs"
                                        :class="port.link ? 'invisible' : ''"
                                        :disabled="port.link !== null"
                                        :aria-hidden="
                                            port.link ? 'true' : undefined
                                        "
                                        :aria-label="$t('Link…')"
                                        :title="$t('Link…')"
                                        :data-test="`link-port-${port.name}`"
                                        @click="linkOne(port)"
                                    >
                                        <Cable aria-hidden="true" />
                                    </Button>
                                </RowActions>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </section>

    <TemplateChoiceDialog
        :open="choosingTemplate"
        :device="device"
        :templates="templates"
        @close="choosingTemplate = false"
    />

    <PortSeriesDialog
        :open="addingSeries"
        :device="device"
        :members="members"
        :options="options"
        @close="addingSeries = false"
    />

    <PortDialog
        :open="portDialogOpen"
        :device="device"
        :port="portToEdit"
        :members="members"
        :options="options"
        :vlans="vlans"
        @close="portDialogOpen = false"
    />

    <LinkDialog
        :open="linking"
        :device="device"
        :ports="portsToLink"
        :media="linkMedia"
        :statuses="linkStatuses"
        :linkable="linkable"
        :opposite-ports="oppositePorts"
        :draft="linkDraftToOpen"
        @saved="linked"
        @close="linking = false"
    />

    <JackPlugDialog
        :open="plugging"
        :device="device"
        :port="portToPlug"
        :jacks="pluggableJacks"
        @close="plugging = false"
    />

    <PortModeDialog
        :open="settingMode"
        :device="device"
        :ports="selectedIds"
        :vlans="vlans"
        @close="settingMode = false"
    />

    <ConfirmDialog
        :open="confirmingModeRemoval"
        :title="$t('Remove the mode of the ports selected?')"
        :description="
            transChoice(
                'The port selected on :name will have no mode and will carry no VLAN.|The :count ports selected on :name will have no mode and will carry no VLAN.',
                selected.size,
                { name: device.name },
            )
        "
        :confirm-label="$t('Remove the mode')"
        @confirm="clearMode"
        @cancel="confirmingModeRemoval = false"
    />

    <ConfirmDialog
        :open="confirmingUnpatchAll"
        :title="$t('Unpatch all the ports of :name?', { name: device.name })"
        :description="
            $tc(
                ':count cord will be taken away: :cords. The ports stay.|:count cords will be taken away: :cords. The ports stay.',
                unpatchAllCount,
                { cords: unpatchAllEnds },
            )
        "
        :confirm-label="$t('Unpatch')"
        @confirm="unpatchAllPorts"
        @cancel="confirmingUnpatchAll = false"
    />

    <ConfirmDialog
        :open="confirmingUnplugAll"
        :title="$t('Unplug all the ports of :name?', { name: device.name })"
        :description="
            $tc(
                ':count jack cord will be taken away: :cords. The ports and the jacks stay.|:count jack cords will be taken away: :cords. The ports and the jacks stay.',
                unplugAllCount,
                { cords: unplugAllEnds },
            )
        "
        :confirm-label="$t('Unplug')"
        @confirm="unplugAllPorts"
        @cancel="confirmingUnplugAll = false"
    />

    <ConfirmDialog
        :open="portToUnplug !== null"
        :title="$t('Unplug this port?')"
        :description="
            $t(
                'The cord from :port to the jack :jack is taken away; the port and the jack stay.',
                {
                    port: portToUnplug?.name ?? '',
                    jack: portToUnplug?.jack_cord?.jack.label ?? '',
                },
            )
        "
        :confirm-label="$t('Unplug')"
        @confirm="confirmUnplug"
        @cancel="portToUnplug = null"
    />

    <ConfirmDialog
        :open="portToDelete !== null"
        :title="$t('Delete this port?')"
        :description="
            $t(
                'The port :port of :name will be deleted, with the VLANs it carries.',
                { port: portToDelete?.name ?? '', name: device.name },
            )
        "
        :confirm-label="$t('Delete')"
        destructive
        @confirm="confirmDeletion"
        @cancel="portToDelete = null"
    />

    <ConfirmDialog
        :open="confirmingSelectionDeletion"
        :title="$t('Delete the ports selected?')"
        :description="
            transChoice(
                'The port selected will be deleted from :name, with the VLANs it carries.|The :count ports selected will be deleted from :name, with the VLANs they carry.',
                selected.size,
                { name: device.name },
            )
        "
        :confirm-label="$t('Delete')"
        destructive
        @confirm="deleteSelection"
        @cancel="confirmingSelectionDeletion = false"
    />
</template>
