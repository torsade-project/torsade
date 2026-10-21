<!-- torsade/resources/js/pages/diagrams/Logical.vue -->
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DiagramLayoutBar from '@/components/diagram/DiagramLayoutBar.vue';
import DiagramToolbar from '@/components/diagram/DiagramToolbar.vue';
import LogicalDiagram from '@/components/diagram/LogicalDiagram.vue';
import LogicalLegend from '@/components/diagram/LogicalLegend.vue';
import Heading from '@/components/Heading.vue';
import DeviationList from '@/components/network/DeviationList.vue';
import { useDiagramPage } from '@/composables/useDiagramPage';
import type { Position } from '@/lib/diagramManual';
import { trans, transChoice } from '@/lib/i18n';
import { logical } from '@/routes/diagrams';
import { show as showDevice } from '@/routes/devices';
import {
    destroy as resetLayout,
    update as updateLayout,
} from '@/routes/sites/diagrams/logical/layout';
import { show as showSubnet } from '@/routes/subnets';
import type { LogicalDiagramData } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: trans('Logical diagram'),
                href: logical(),
            },
        ],
    },
});

// The logical diagram of a site, worked out from its subnets, their VLANs
// and the addresses of its devices: a list chooses the site, which is in
// the address of the page so that the diagram can be shared by its link.
const props = defineProps<{
    sites: { id: number; name: string }[];
    // Null when there is no site yet.
    site: { id: number; name: string; code: string | null } | null;
    diagram: LogicalDiagramData | null;
    // Where the nodes drawn were placed by hand, and the version of that
    // layout, which a save must still find.
    layout: {
        positions: { node: string; x: number; y: number }[];
        version: string;
    } | null;
    canEditLayout: boolean;
}>();

const drawing = ref<InstanceType<typeof LogicalDiagram> | null>(null);
const ready = ref<boolean>(false);

// --- Placing the nodes by hand -----------------------------------------------

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
    pageOf: (site) => logical({ query: { site } }).url,
    updateOf: (site) => updateLayout(site).url,
    resetOf: (site) => resetLayout(site).url,
    positions: () => drawing.value?.layout() ?? [],
    fit: () => drawing.value?.fit(),
});

const { siteId, editing, dirty, busy, layoutError } = page;

const empty = computed<boolean>(
    () =>
        props.diagram !== null &&
        props.diagram.subnets.length === 0 &&
        props.diagram.devices.length === 0,
);

// What the diagram shows, in words: for a screen reader, and for anyone.
const summary = computed<string>(() => {
    const diagram = props.diagram;

    if (diagram === null) {
        return '';
    }

    const counts = diagram.summary;

    return [
        transChoice(':count subnet|:count subnets', counts.subnets),
        ...(diagram.internet === null ? [] : [trans('the internet')]),
        transChoice(':count device|:count devices', counts.devices),
        transChoice(':count connection|:count connections', counts.connections),
        transChoice(':count deviation|:count deviations', counts.deviations),
    ].join(', ');
});

// Every deviation drawn, with its reason and where to see it: the page of
// the subnet, or of the device.
const deviationItems = computed(() => {
    const diagram = props.diagram;

    if (diagram === null) {
        return [];
    }

    return [
        ...diagram.subnets
            .filter((subnet) => subnet.deviations.length > 0)
            .map((subnet) => ({
                key: subnet.key,
                label: subnet.cidr,
                href: showSubnet(subnet.id),
                deviations: subnet.deviations,
            })),
        ...diagram.devices
            .filter((device) => device.deviations.length > 0)
            .map((device) => ({
                key: device.key,
                label: device.name,
                href: showDevice(device.id),
                deviations: device.deviations,
            })),
    ];
});

const save = (): void => {
    void drawing.value?.save(
        page.fileName('logical-diagram-:site-:day.svg'),
        trans('Logical diagram of the site :site', {
            site: props.site?.name ?? '',
        }),
    );
};
</script>

<template>
    <Head :title="$t('Logical diagram')" />

    <div class="flex min-w-0 flex-col gap-6" data-print-only>
        <Heading
            :title="
                site === null
                    ? $t('Logical diagram')
                    : $t('Logical diagram of the site :site', {
                          site: site.name,
                      })
            "
            :description="
                $t(
                    'The subnets of the site, the internet, and the devices plugged into them, worked out from what is declared: nothing is drawn by hand. Each subnet and each device leads to its page.',
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
            />

            <!-- A site without anything to draw. -->
            <p
                v-if="empty"
                class="max-w-prose rounded-lg border border-dashed p-6 text-muted-foreground"
                data-test="diagram-empty"
            >
                {{
                    $t(
                        'This site has nothing to draw: no subnet, and no firewall, router nor switch.',
                    )
                }}
            </p>

            <template v-else>
                <LogicalLegend />

                <!-- While the nodes are being placed: what saves them, what
                     forgets them, and whether some moved. -->
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
                            'Drag a subnet, a device or the internet to move it; from the keyboard, the arrows move the element that has the focus by 8 pixels, and by 40 with Shift.',
                        ),
                    }"
                    @save="page.saveLayout"
                    @cancel="page.cancelEditing"
                    @reset="page.resetLayout"
                />

                <LogicalDiagram
                    ref="drawing"
                    :diagram="diagram"
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
                    <p>
                        {{
                            $t(
                                'Every firewall, router and switch of the site is drawn; any other device when it has addresses in two subnets drawn or carries the VLAN of one. Workstations, printers and cameras are counted on the bar of their subnet.',
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
