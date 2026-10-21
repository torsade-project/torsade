<!-- torsade/resources/js/pages/patch-panels/Show.vue -->
<script setup lang="ts">
import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { Cable, Pencil, Plug, Unlink, Unplug } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Deviation from '@/components/network/Deviation.vue';
import Fact from '@/components/network/Fact.vue';
import FactBand from '@/components/network/FactBand.vue';
import JackAttachDialog from '@/components/network/JackAttachDialog.vue';
import PatchCordDialog from '@/components/network/PatchCordDialog.vue';
import PatchCordEditDialog from '@/components/network/PatchCordEditDialog.vue';
import PatchPanelDialog from '@/components/network/PatchPanelDialog.vue';
import PatchPanelGrid from '@/components/network/PatchPanelGrid.vue';
import PlacePath from '@/components/network/PlacePath.vue';
import StatusBadge from '@/components/network/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { trans, transChoice } from '@/lib/i18n';
import { show as showDevice } from '@/routes/devices';
import {
    destroy as unpatchCord,
    destroyMany as unpatchCords,
} from '@/routes/patch-cords';
import { destroy as detachJack } from '@/routes/patch-panel-ports/jack';
import { destroy, show } from '@/routes/patch-panels';
import { index as sitesIndex, show as showSite } from '@/routes/sites';
import type {
    DevicePortChoice,
    FreeJackGroup,
    Option,
    PanelPort,
    PatchDevice,
    PatchPanel,
} from '@/types';

// A patch panel: where it is, its ports drawn as on the panel itself, and
// for each port the jack whose horizontal cable ends there and the cord
// that leaves from it, with the port of the device it goes to and what that
// port carries.
const props = defineProps<{
    panel: PatchPanel;
    // Its ports, by number.
    ports: PanelPort[];
    connectors: Option[];
    statuses: Option[];
    // The jacks of the site of the panel that are not cabled yet, on any
    // of its floors, by building and floor.
    freeJacks: FreeJackGroup[];
    // The devices of the site, which a port is patched to.
    patchDevices: PatchDevice[];
    // The ports of the device chosen in the window "Patch", asked for by
    // the window.
    devicePorts?: DevicePortChoice[];
}>();

setLayoutProps({
    breadcrumbs: [
        { title: trans('Sites'), href: sitesIndex() },
        { title: props.panel.site.name, href: showSite(props.panel.site.id) },
        {
            title: trans('Patch panel :name', { name: props.panel.name }),
            href: show(props.panel.id),
        },
    ],
});

const page = usePage();

// Only decides which buttons are shown: the server checks every action.
const canEdit = computed<boolean>(() => page.props.auth.can.editNetwork);

const editing = ref<boolean>(false);
const confirmingDeletion = ref<boolean>(false);

// The port a jack is about to be cabled to, or detached from.
const attaching = ref<{ id: number; name: string } | null>(null);
const attachOpen = ref<boolean>(false);
const detaching = ref<PanelPort | null>(null);
const confirmingDetach = ref<boolean>(false);

const portName = (port: PanelPort): string =>
    `${props.panel.name} ${port.number}`;

const attach = (port: PanelPort): void => {
    attaching.value = { id: port.id, name: portName(port) };
    attachOpen.value = true;
};

const askDetach = (port: PanelPort): void => {
    detaching.value = port;
    confirmingDetach.value = true;
};

const confirmDetach = (): void => {
    const port = detaching.value;

    confirmingDetach.value = false;

    if (!port) {
        return;
    }

    router.delete(detachJack(port.id).url, {
        preserveScroll: true,
        onError: (errors) =>
            toast.error(
                Object.values(errors)[0] ?? trans('Something went wrong.'),
            ),
    });
};

// --- Patching -----------------------------------------------------------------

// The ports ticked, to be patched as a series.
const selected = ref<Set<number>>(new Set());

const toggle = (port: PanelPort): void => {
    const next = new Set(selected.value);

    if (next.has(port.id)) {
        next.delete(port.id);
    } else {
        next.add(port.id);
    }

    selected.value = next;
};

const clearSelection = (): void => {
    selected.value = new Set();
};

// The ports ticked, by number.
const selectedPorts = computed<PanelPort[]>(() =>
    props.ports.filter((port) => selected.value.has(port.id)),
);

// A series is made of ports that follow one another and have no cord yet.
const seriesProblem = computed<string | null>(() => {
    const ports = selectedPorts.value;

    if (ports.length === 0) {
        return null;
    }

    if (ports.some((port, index) => port.number !== ports[0].number + index)) {
        return trans(
            'The ports of the panel patched in one go follow one another: choose consecutive ports.',
        );
    }

    if (ports.some((port) => port.cord !== null)) {
        return trans('Some of the ports ticked are already patched.');
    }

    return null;
});

// The cords of the ports ticked, which "Unpatch" takes away: the ports
// without a cord are left out.
const selectedCords = computed<PanelPort[]>(() =>
    selectedPorts.value.filter((port) => port.cord !== null),
);

// How many cords a confirmation names at most.
const CORDS_NAMED = 10;

// The cords about to be taken away, by their two ends: "PB-B 1 ↔
// sw-etage-1 › 1/0/1", ten at most, then how many more.
const doomedCords = computed<string>(() => {
    const ends = selectedCords.value.map(
        (port) =>
            `${portName(port)} ↔ ${port.cord?.device.name ?? ''} › ${port.cord?.port.name ?? ''}`,
    );
    const shown = ends.slice(0, CORDS_NAMED).join(', ');

    return ends.length > CORDS_NAMED
        ? transChoice(':items and :count more', ends.length - CORDS_NAMED, {
              items: shown,
          })
        : shown;
});

const confirmingSeriesUnpatch = ref<boolean>(false);
// Kept while the window fades out, so that its words do not change.
const seriesUnpatchCount = ref<number>(0);
const seriesUnpatchEnds = ref<string>('');

const askSeriesUnpatch = (): void => {
    seriesUnpatchCount.value = selectedCords.value.length;
    seriesUnpatchEnds.value = doomedCords.value;
    confirmingSeriesUnpatch.value = true;
};

const confirmSeriesUnpatch = (): void => {
    const ids = selectedCords.value.map((port) => port.id);

    confirmingSeriesUnpatch.value = false;

    if (ids.length === 0) {
        return;
    }

    router.delete(unpatchCords(props.panel.id).url, {
        data: { panel_port_ids: ids },
        preserveScroll: true,
        onSuccess: () => clearSelection(),
        onError: (errors) =>
            toast.error(
                Object.values(errors)[0] ?? trans('Something went wrong.'),
            ),
    });
};

// The ports the window "Patch" is about to patch.
const patching = ref<PanelPort[]>([]);
const patchOpen = ref<boolean>(false);
// Whether the ports patched come from the selection, which is then
// emptied once they are saved.
const fromSelection = ref<boolean>(false);

const patchOne = (port: PanelPort): void => {
    patching.value = [port];
    fromSelection.value = false;
    patchOpen.value = true;
};

const patchSelection = (): void => {
    patching.value = selectedPorts.value;
    fromSelection.value = true;
    patchOpen.value = true;
};

const patched = (): void => {
    if (fromSelection.value) {
        clearSelection();
    }
};

// The port whose cord is being changed: its colour and its description.
const editingCord = ref<PanelPort | null>(null);
const cordEditOpen = ref<boolean>(false);

const editCord = (port: PanelPort): void => {
    editingCord.value = port;
    cordEditOpen.value = true;
};

// The port whose cord is about to be taken away.
const unpatching = ref<PanelPort | null>(null);
const confirmingUnpatch = ref<boolean>(false);

const askUnpatch = (port: PanelPort): void => {
    unpatching.value = port;
    confirmingUnpatch.value = true;
};

const confirmUnpatch = (): void => {
    const cord = unpatching.value?.cord;

    confirmingUnpatch.value = false;

    if (!cord) {
        return;
    }

    router.delete(unpatchCord(cord.id).url, {
        preserveScroll: true,
        onError: (errors) =>
            toast.error(
                Object.values(errors)[0] ?? trans('Something went wrong.'),
            ),
    });
};

const confirmDeletion = (): void => {
    confirmingDeletion.value = false;

    // A panel one of whose ports has a jack or a cord is refused by the
    // server, with a message that names the ports.
    router.delete(destroy(props.panel.id).url, {
        preserveScroll: true,
        onError: (errors) =>
            toast.error(
                Object.values(errors)[0] ?? trans('Something went wrong.'),
            ),
    });
};
</script>

<template>
    <Head :title="$t('Patch panel :name', { name: panel.name })" />

    <div class="flex flex-col gap-7">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <div class="flex flex-wrap items-center gap-3.5">
                <h1 class="font-mono text-title font-semibold">
                    {{ panel.name }}
                </h1>
                <span
                    class="inline-flex h-7.5 items-center rounded-sm border border-border-strong px-3 text-sm font-medium"
                >
                    {{ $t('Patch panel') }}
                </span>
                <StatusBadge
                    :status="panel.status"
                    :label="panel.status_label"
                />
            </div>
            <div v-if="canEdit" class="flex flex-wrap gap-2.5">
                <Button
                    type="button"
                    variant="outline"
                    data-test="edit-patch-panel"
                    @click="editing = true"
                >
                    {{ $t('Edit') }}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    data-test="delete-patch-panel"
                    @click="confirmingDeletion = true"
                >
                    {{ $t('Delete') }}
                </Button>
            </div>
        </div>

        <FactBand class="xl:grid-cols-4">
            <Fact :label="$t('Site')">
                <Link :href="showSite(panel.site.id)" class="link">{{
                    panel.site.name
                }}</Link>
            </Fact>
            <Fact :label="$t('Place')">
                <PlacePath
                    :path="panel.room.path"
                    :technical="panel.room.is_technical"
                />
            </Fact>
            <Fact :label="$t('Connector')">
                {{ panel.connector_label }}
                <span v-if="panel.category" class="text-muted-foreground"
                    >·
                    {{
                        $t('category :category', { category: panel.category })
                    }}</span
                >
            </Fact>
            <Fact :label="$t('Ports')">
                <span data-test="panel-occupancy">{{
                    $tc(
                        ':count port occupied out of :total|:count ports occupied out of :total',
                        panel.occupied,
                        { total: panel.port_count },
                    )
                }}</span>
            </Fact>
        </FactBand>

        <p
            v-if="panel.description"
            class="max-w-prose whitespace-pre-line text-muted-foreground"
        >
            {{ panel.description }}
        </p>

        <PatchPanelGrid :name="panel.name" :ports="ports" />

        <section
            class="flex min-w-0 flex-col gap-3"
            :aria-label="$t('Ports')"
            data-test="panel-ports"
        >
            <h2 class="text-lg font-semibold">{{ $t('Ports') }}</h2>

            <!-- What is done to the ports ticked. It stays in sight while
                 the table is scrolled. -->
            <div
                v-if="canEdit"
                class="sticky top-0 z-10 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border bg-card px-3.5 py-2"
                role="group"
                :aria-label="$t('Actions on the ports selected')"
                data-test="panel-port-actions"
            >
                <span
                    class="min-w-44 text-sm"
                    aria-live="polite"
                    data-test="selected-panel-ports"
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
                        :disabled="
                            selected.size === 0 || seriesProblem !== null
                        "
                        data-test="patch-series"
                        @click="patchSelection"
                    >
                        {{ $t('Patch as a series') }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="selectedCords.length === 0"
                        data-test="unpatch-series"
                        @click="askSeriesUnpatch"
                    >
                        {{ $t('Unpatch') }}
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :disabled="selected.size === 0"
                        data-test="clear-panel-selection"
                        @click="clearSelection"
                    >
                        {{ $t('Clear the selection') }}
                    </Button>
                </div>
                <!-- Why the ports ticked are not patched as a series: an
                     explanation, not an error, since they may be ticked to
                     be unpatched. -->
                <p
                    v-if="seriesProblem"
                    class="w-full text-sm text-muted-foreground"
                    data-test="patch-series-problem"
                >
                    {{ seriesProblem }}
                </p>
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
                            <th scope="col">{{ $t('Port') }}</th>
                            <th scope="col">{{ $t('Jack') }}</th>
                            <th scope="col">{{ $t('Cord') }}</th>
                            <th scope="col">{{ $t('Opposite') }}</th>
                            <th scope="col">{{ $t('Mode and VLANs') }}</th>
                            <th scope="col">{{ $t('Deviations') }}</th>
                            <th v-if="canEdit" scope="col" class="text-right">
                                {{ $t('Actions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="port in ports"
                            :key="port.id"
                            :class="
                                selected.has(port.id) ? 'bg-accent' : undefined
                            "
                            data-test="panel-port-row"
                            :data-port="port.number"
                        >
                            <td v-if="canEdit" class="w-9">
                                <input
                                    type="checkbox"
                                    class="block size-4.5 shrink-0 rounded-xs accent-primary"
                                    :checked="selected.has(port.id)"
                                    :aria-label="
                                        $t('Select the port :name', {
                                            name: port.number,
                                        })
                                    "
                                    :data-test="`select-panel-port-${port.number}`"
                                    @click="toggle(port)"
                                />
                            </td>
                            <td class="font-mono text-sm font-medium">
                                {{ port.number }}
                            </td>
                            <td class="min-w-36">
                                <template v-if="port.jack">
                                    <span
                                        class="font-mono text-sm font-medium"
                                        data-test="panel-port-jack"
                                        >{{ port.jack.label }}</span
                                    >
                                    <div
                                        class="text-note text-muted-foreground"
                                    >
                                        {{ port.jack.place }}
                                    </div>
                                </template>
                                <span v-else class="text-muted-foreground">{{
                                    $t('No jack')
                                }}</span>
                            </td>
                            <td class="whitespace-nowrap">
                                <template v-if="port.cord">
                                    <span v-if="port.cord.color">{{
                                        port.cord.color
                                    }}</span>
                                    <span
                                        v-else
                                        class="text-muted-foreground"
                                        >{{ $t('Patched') }}</span
                                    >
                                </template>
                                <span v-else class="text-muted-foreground">{{
                                    $t('Not patched')
                                }}</span>
                            </td>
                            <td class="whitespace-nowrap">
                                <span
                                    v-if="port.cord"
                                    data-test="panel-port-opposite"
                                >
                                    <Link
                                        :href="showDevice(port.cord.device.id)"
                                        class="link"
                                        >{{ port.cord.device.name }}</Link
                                    >
                                    ›
                                    <span class="font-mono text-sm">{{
                                        port.cord.port.name
                                    }}</span>
                                </span>
                            </td>
                            <td class="min-w-36">
                                <template v-if="port.cord">
                                    <span v-if="port.cord.configuration">{{
                                        port.cord.configuration
                                    }}</span>
                                    <span
                                        v-else
                                        class="text-muted-foreground"
                                        >{{ $t('No mode') }}</span
                                    >
                                </template>
                            </td>
                            <td class="min-w-44">
                                <Deviation
                                    :deviations="port.deviations"
                                    compact
                                />
                            </td>
                            <td v-if="canEdit" class="py-1">
                                <div class="flex justify-end gap-1">
                                    <!-- Only a patched port has a cord to
                                         change: the others keep its place,
                                         so that the icons stay aligned. -->
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-xs"
                                        :class="port.cord ? '' : 'invisible'"
                                        :disabled="port.cord === null"
                                        :aria-hidden="
                                            port.cord ? undefined : 'true'
                                        "
                                        :aria-label="
                                            $t(
                                                'Edit the cord of the port :port',
                                                {
                                                    port: port.number,
                                                },
                                            )
                                        "
                                        :title="$t('Edit the cord')"
                                        :data-test="`edit-cord-${port.number}`"
                                        @click="editCord(port)"
                                    >
                                        <Pencil aria-hidden="true" />
                                    </Button>
                                    <Button
                                        v-if="port.cord"
                                        type="button"
                                        variant="ghost"
                                        size="icon-xs"
                                        class="hover:bg-error-surface hover:text-error"
                                        :aria-label="
                                            $t('Unpatch the port :port', {
                                                port: port.number,
                                            })
                                        "
                                        :title="$t('Unpatch')"
                                        :data-test="`unpatch-${port.number}`"
                                        @click="askUnpatch(port)"
                                    >
                                        <Unlink aria-hidden="true" />
                                    </Button>
                                    <Button
                                        v-else
                                        type="button"
                                        variant="ghost"
                                        size="icon-xs"
                                        :aria-label="
                                            $t('Patch the port :port', {
                                                port: port.number,
                                            })
                                        "
                                        :title="$t('Patch…')"
                                        :data-test="`patch-${port.number}`"
                                        @click="patchOne(port)"
                                    >
                                        <Cable aria-hidden="true" />
                                    </Button>
                                    <Button
                                        v-if="port.jack"
                                        type="button"
                                        variant="ghost"
                                        size="icon-xs"
                                        :aria-label="
                                            $t('Detach the jack :jack', {
                                                jack: port.jack.label,
                                            })
                                        "
                                        :title="$t('Detach the jack')"
                                        :data-test="`detach-jack-${port.number}`"
                                        @click="askDetach(port)"
                                    >
                                        <Unplug aria-hidden="true" />
                                    </Button>
                                    <Button
                                        v-else
                                        type="button"
                                        variant="ghost"
                                        size="icon-xs"
                                        :aria-label="
                                            $t(
                                                'Cable a jack to the port :port',
                                                {
                                                    port: port.number,
                                                },
                                            )
                                        "
                                        :title="$t('Cable a jack')"
                                        :data-test="`attach-jack-${port.number}`"
                                        @click="attach(port)"
                                    >
                                        <Plug aria-hidden="true" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <PatchPanelDialog
        :open="editing"
        :room="null"
        :panel="panel"
        :connectors="connectors"
        :statuses="statuses"
        @close="editing = false"
    />

    <PatchCordEditDialog
        :open="cordEditOpen"
        :panel="panel"
        :port="editingCord"
        @close="cordEditOpen = false"
    />

    <PatchCordDialog
        :open="patchOpen"
        :panel="panel"
        :ports="patching"
        :devices="patchDevices"
        :device-ports="devicePorts"
        @saved="patched"
        @close="patchOpen = false"
    />

    <ConfirmDialog
        :open="confirmingSeriesUnpatch"
        :title="$t('Unpatch the ports ticked?')"
        :description="
            $tc(
                ':count cord will be taken away: :cords. The ports stay.|:count cords will be taken away: :cords. The ports stay.',
                seriesUnpatchCount,
                { cords: seriesUnpatchEnds },
            )
        "
        :confirm-label="$t('Unpatch')"
        @confirm="confirmSeriesUnpatch"
        @cancel="confirmingSeriesUnpatch = false"
    />

    <ConfirmDialog
        :open="confirmingUnpatch"
        :title="$t('Unpatch this port?')"
        :description="
            $t(
                'The cord from :port to :device :opposite is taken away; both ports stay.',
                {
                    port: unpatching ? portName(unpatching) : '',
                    device: unpatching?.cord?.device.name ?? '',
                    opposite: unpatching?.cord?.port.name ?? '',
                },
            )
        "
        :confirm-label="$t('Unpatch')"
        @confirm="confirmUnpatch"
        @cancel="confirmingUnpatch = false"
    />

    <JackAttachDialog
        :open="attachOpen"
        :port="attaching"
        :jacks="freeJacks"
        @close="attachOpen = false"
    />

    <ConfirmDialog
        :open="confirmingDetach"
        :title="$t('Detach this jack?')"
        :description="
            $t(
                'The jack :jack stays on its floor; the port :port is free again.',
                {
                    jack: detaching?.jack?.label ?? '',
                    port: detaching ? portName(detaching) : '',
                },
            )
        "
        :confirm-label="$t('Detach')"
        @confirm="confirmDetach"
        @cancel="confirmingDetach = false"
    />

    <ConfirmDialog
        :open="confirmingDeletion"
        :title="$t('Delete this patch panel?')"
        :description="
            $t(
                ':name will be deleted with its ports. A panel one of whose ports still has a jack or a cord cannot be deleted.',
                { name: panel.name },
            )
        "
        :confirm-label="$t('Delete')"
        destructive
        @confirm="confirmDeletion"
        @cancel="confirmingDeletion = false"
    />
</template>
