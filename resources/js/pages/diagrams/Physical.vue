<!-- torsade/resources/js/pages/diagrams/Physical.vue -->
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DiagramLayoutBar from '@/components/diagram/DiagramLayoutBar.vue';
import DiagramLegend from '@/components/diagram/DiagramLegend.vue';
import DiagramToolbar from '@/components/diagram/DiagramToolbar.vue';
import PhysicalDiagram from '@/components/diagram/PhysicalDiagram.vue';
import Heading from '@/components/Heading.vue';
import DeviationList from '@/components/network/DeviationList.vue';
import { Button } from '@/components/ui/button';
import { useDiagramPage } from '@/composables/useDiagramPage';
import { useDiagramSettings } from '@/composables/useDiagramSettings';
import { listedDeviations, withoutEndDevices } from '@/lib/diagram';
import type { Position } from '@/lib/diagramManual';
import { trans, transChoice } from '@/lib/i18n';
import { physical } from '@/routes/diagrams';
import { create as createDevice, show as showDevice } from '@/routes/devices';
import { index as links } from '@/routes/links';
import {
    destroy as resetLayout,
    update as updateLayout,
} from '@/routes/sites/diagrams/physical/layout';
import type { PhysicalDiagramData } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: trans('Physical diagram'),
                href: physical(),
            },
        ],
    },
});

// The physical diagram of a site, worked out from its devices, their links
// and their cabling: a list chooses the site, which is in the address of
// the page so that the diagram can be shared by its link.
const props = defineProps<{
    sites: { id: number; name: string }[];
    // Null when there is no site yet.
    site: { id: number; name: string; code: string | null } | null;
    diagram: PhysicalDiagramData | null;
    // Where the devices and the panels drawn were placed by hand, and the
    // version of that layout, which a save must still find.
    layout: {
        positions: { node: string; x: number; y: number }[];
        version: string;
    } | null;
    canCreateDevice: boolean;
    canEditLayout: boolean;
}>();

const drawing = ref<InstanceType<typeof PhysicalDiagram> | null>(null);
const ready = ref<boolean>(false);

// --- Placing the devices and the panels by hand ------------------------------

const positions = computed<Record<string, Position>>(() =>
    Object.fromEntries(
        (props.layout?.positions ?? []).map((position) => [
            position.node,
            { x: position.x, y: position.y },
        ]),
    ),
);

const page = useDiagramPage({
    site: () => props.site,
    version: () => props.layout?.version ?? null,
    leave: () =>
        trans(
            'Some elements were moved and not saved. Leave the page and lose these moves?',
        ),
    pageOf: (site) => physical({ query: { site } }).url,
    updateOf: (site) => updateLayout(site).url,
    resetOf: (site) => resetLayout(site).url,
    positions: () => drawing.value?.layout() ?? [],
    fit: () => drawing.value?.fit(),
});

const { siteId, editing, dirty, busy, layoutError } = page;

// Each device in the frame of its room, its floor and its building, and
// the end devices shown: as the user last left them, for every site.
const { grouped, endDevices } = useDiagramSettings();

// While the devices are being placed, the end devices are always shown, so
// that the layout saved keeps their places.
const endDevicesShown = computed<boolean>(
    () => editing.value || endDevices.value,
);

// What the diagram draws: everything, or the network without the end
// devices, each device joined to some of them telling how many.
const shown = computed<{
    diagram: PhysicalDiagramData;
    counts: Record<string, number>;
} | null>(() => {
    if (props.diagram === null) {
        return null;
    }

    return endDevicesShown.value
        ? { diagram: props.diagram, counts: {} }
        : withoutEndDevices(props.diagram);
});

const empty = computed<boolean>(
    () => props.diagram !== null && props.diagram.devices.length === 0,
);

// What the diagram shows, in words: for a screen reader, and for anyone.
// The end devices are counted apart, shown or not.
const summary = computed<string>(() => {
    const counts = props.diagram?.summary;

    if (counts === undefined) {
        return '';
    }

    return [
        transChoice(':count device|:count devices', counts.devices),
        transChoice(':count end device|:count end devices', counts.end_devices),
        transChoice(':count link|:count links', counts.links),
        transChoice(':count deviation|:count deviations', counts.deviations),
    ].join(', ');
});

// The device a key of the diagram names: "device-12".
const deviceId = (key: string): number | null =>
    key.startsWith('device-') ? Number(key.slice('device-'.length)) : null;

// Every deviation drawn, those of the end devices hidden included, with
// its reason and where to see it: the page of the device, the links of the
// site that differ, or, for a cabling without a link, the page of the
// device patched to the panel.
const deviationItems = computed(() => {
    const diagram = props.diagram;
    const site = props.site;

    if (diagram === null || site === null) {
        return [];
    }

    const linksOfSite = links({
        query: { site: String(site.id), deviations: '1' },
    });

    return [
        ...diagram.devices
            .filter((device) => device.deviations.length > 0)
            .map((device) => ({
                key: device.key,
                label: device.name,
                href: showDevice(device.id),
                deviations: device.deviations,
            })),
        ...listedDeviations(diagram.links).map(({ link, deviations }) => {
            const patched =
                deviceId(link.source) ?? deviceId(link.target) ?? null;

            return {
                key: link.key,
                // The reason of a deviation of the cabling names its path.
                label: `${link.ends[0]} ↔ ${link.ends[1]}`,
                href:
                    link.links_count > 0 || patched === null
                        ? linksOfSite
                        : showDevice(patched),
                deviations,
            };
        }),
    ];
});

const save = (): void => {
    void drawing.value?.save(
        page.fileName('physical-diagram-:site-:day.svg'),
        trans('Physical diagram of the site :site', {
            site: props.site?.name ?? '',
        }),
    );
};
</script>

<template>
    <Head :title="$t('Physical diagram')" />

    <div class="flex min-w-0 flex-col gap-6" data-print-only>
        <Heading
            :title="
                site === null
                    ? $t('Physical diagram')
                    : $t('Physical diagram of the site :site', {
                          site: site.name,
                      })
            "
            :description="
                $t(
                    'The devices of the site, the links between them and the patch panels they go through, worked out from what is declared: nothing is drawn by hand. Each device and each panel leads to its page.',
                )
            "
        />

        <p
            v-if="site === null"
            class="rounded-lg border border-dashed p-6 text-muted-foreground"
            data-test="no-site"
        >
            {{ $t('No site yet: a diagram is drawn for a site.') }}
        </p>

        <template v-else-if="diagram !== null">
            <DiagramToolbar
                v-model:site-id="siteId"
                :sites="sites"
                :can-edit="canEditLayout && !empty && !editing"
                :ready="ready"
                @choose-site="page.chooseSite"
                @edit="page.startEditing"
                @save="save"
                @print="page.print"
            >
                <label class="flex h-11 items-center gap-2.5">
                    <input
                        v-model="grouped"
                        type="checkbox"
                        class="size-4.5 shrink-0 rounded-xs accent-primary"
                        data-test="group-by-place"
                    />
                    <span>{{ $t('Group by place') }}</span>
                </label>
                <!-- Kept as the user leaves it; always shown while the
                     devices are being placed. -->
                <label
                    class="flex h-11 items-center gap-2.5"
                    :class="{ 'text-muted-foreground': editing }"
                >
                    <input
                        type="checkbox"
                        class="size-4.5 shrink-0 rounded-xs accent-primary"
                        :checked="endDevicesShown"
                        :disabled="editing"
                        :aria-describedby="
                            editing ? 'end-devices-while-editing' : undefined
                        "
                        data-test="show-end-devices"
                        @change="
                            endDevices = ($event.target as HTMLInputElement)
                                .checked
                        "
                    />
                    <span>{{ $t('Show the end devices') }}</span>
                    <span
                        v-if="editing"
                        id="end-devices-while-editing"
                        class="text-sm"
                        >{{
                            $t('shown while the elements are being placed')
                        }}</span
                    >
                </label>
            </DiagramToolbar>

            <!-- A site without anything to draw. -->
            <div
                v-if="empty"
                class="flex flex-col items-start gap-4 rounded-lg border border-dashed p-6"
                data-test="diagram-empty"
            >
                <p class="max-w-prose text-muted-foreground">
                    {{
                        $t(
                            'This site has no device to draw: firewalls, routers, switches, access points, and any other device that has a link.',
                        )
                    }}
                </p>
                <Button v-if="canCreateDevice" as-child variant="outline">
                    <Link :href="createDevice({ query: { site: site.id } })">{{
                        $t('New device')
                    }}</Link>
                </Button>
            </div>

            <template v-else>
                <!-- Devices without a link: drawn, and told where a link is
                     declared. -->
                <p
                    v-if="diagram.links.length === 0"
                    class="max-w-prose rounded-lg border border-dashed p-4 text-muted-foreground print:hidden"
                    data-test="diagram-no-link"
                >
                    {{
                        $t(
                            'No link between these devices yet. A link is declared from the page of a device, on the line of one of its ports, with "Link…".',
                        )
                    }}
                </p>

                <DiagramLegend />

                <!-- While the devices are being placed: what saves them,
                     what forgets them, and whether some moved. -->
                <DiagramLayoutBar
                    v-if="editing"
                    :busy="busy"
                    :dirty="dirty"
                    :can-reset="(layout?.positions.length ?? 0) > 0"
                    :error="layoutError"
                    :texts="{
                        moved: $t(
                            'Some elements were moved and are not saved yet.',
                        ),
                        still: $t('Nothing moved yet.'),
                        help: $t(
                            'Drag a device or a panel to move it; from the keyboard, the arrows move the element that has the focus by 8 pixels, and by 40 with Shift.',
                        ),
                    }"
                    @save="page.saveLayout"
                    @cancel="page.cancelEditing"
                    @reset="page.resetLayout"
                />

                <PhysicalDiagram
                    v-if="shown !== null"
                    ref="drawing"
                    :diagram="shown.diagram"
                    :hidden="shown.counts"
                    :grouped="grouped"
                    :positions="positions"
                    :editing="editing"
                    edit-help="diagram-edit-help"
                    described-by="diagram-summary"
                    @ready="ready = $event"
                    @dirty="dirty = $event"
                />

                <div
                    class="flex flex-col gap-1 text-sm text-muted-foreground print:hidden"
                >
                    <p id="diagram-summary" data-test="diagram-summary">
                        {{ summary }}
                    </p>
                    <p
                        v-if="diagram.summary.undrawn > 0"
                        data-test="diagram-undrawn"
                    >
                        {{
                            transChoice(
                                ':count device of the site is not drawn: it is not a firewall, a router, a switch nor an access point, and it has no link nor any cabling to a device drawn.|:count devices of the site are not drawn: they are not firewalls, routers, switches nor access points, and they have no link nor any cabling to a device drawn.',
                                diagram.summary.undrawn,
                            )
                        }}
                    </p>
                    <p>
                        {{
                            $t(
                                'Drag the background to move the diagram; zoom with its buttons, with Ctrl and the wheel, by pinching, or with + and − once it has the focus.',
                            )
                        }}
                    </p>
                </div>

                <DeviationList
                    class="print:hidden"
                    :title="$t('Deviations of the diagram')"
                    :description="
                        $t(
                            'Each deviation drawn on the diagram, with its reason. A deviation refuses nothing: it shows the network as it is.',
                        )
                    "
                    :items="deviationItems"
                    data-test="diagram-deviations"
                />
            </template>
        </template>
    </div>
</template>
