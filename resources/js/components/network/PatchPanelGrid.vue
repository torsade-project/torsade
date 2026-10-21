<!-- torsade/resources/js/components/network/PatchPanelGrid.vue -->
<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { trans, transChoice } from '@/lib/i18n';
import { formatCount } from '@/lib/numbers';
import type { PanelPort } from '@/types';

// A patch panel drawn as it is seen in the rack: one cell per port, twenty
// four per line, numbered. A cell tells by its shape whether its port is
// free, has a jack without a cord, is patched, or has a cord without a
// jack; a port with a deviation has the warning sign under its cell. It is
// a picture: the table of ports carries the same information for assistive
// technologies, which get a summary in words instead.
const props = defineProps<{
    name: string;
    ports: PanelPort[];
}>();

const CELLS_PER_LINE = 24;

type CellState = 'free' | 'jack' | 'patched' | 'cord';

// In the order of the legend.
const STATES: CellState[] = ['patched', 'jack', 'cord', 'free'];

const labels = computed<Record<CellState, string>>(() => ({
    free: trans('Free'),
    jack: trans('Jack without a cord'),
    patched: trans('Patched'),
    cord: trans('Cord without a jack'),
}));

const stateOf = (port: PanelPort): CellState => {
    if (port.cord !== null) {
        return port.jack !== null ? 'patched' : 'cord';
    }

    return port.jack !== null ? 'jack' : 'free';
};

type Cell = {
    port: PanelPort;
    state: CellState;
    title: string;
};

const cells = computed<Cell[]>(() =>
    props.ports.map((port) => {
        const state = stateOf(port);

        return {
            port,
            state,
            title: [
                trans('Port :name', { name: port.number }),
                labels.value[state],
                port.jack ? `${port.jack.place} › ${port.jack.label}` : null,
                port.cord
                    ? `${port.cord.device.name} › ${port.cord.port.name}`
                    : null,
            ]
                .filter(Boolean)
                .join(' — '),
        };
    }),
);

const lines = computed<Cell[][]>(() => {
    const result: Cell[][] = [];

    for (let start = 0; start < cells.value.length; start += CELLS_PER_LINE) {
        result.push(cells.value.slice(start, start + CELLS_PER_LINE));
    }

    return result;
});

// What the picture shows, in words: the number of ports in each state.
const summary = computed<string>(() => {
    const counts = new Map<CellState, number>();

    for (const cell of cells.value) {
        counts.set(cell.state, (counts.get(cell.state) ?? 0) + 1);
    }

    return [
        transChoice(
            'Patch panel :name: :count port.|Patch panel :name: :count ports.',
            props.ports.length,
            { name: props.name },
        ),
        ...STATES.map((state) =>
            trans(':label: :count.', {
                label: labels.value[state],
                count: formatCount(counts.get(state) ?? 0),
            }),
        ),
    ].join(' ');
});
</script>

<template>
    <section
        class="flex flex-col gap-4 rounded-lg border bg-sunken px-5 py-5 sm:px-6"
        aria-labelledby="panel-grid-title"
        data-test="panel-grid"
    >
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 id="panel-grid-title" class="text-lg font-semibold">
                {{ $t('Ports of the panel') }}
            </h2>
            <ul
                class="flex flex-wrap gap-x-4 gap-y-1.5 text-note"
                data-test="panel-grid-legend"
            >
                <!-- Each entry shows the very cell it explains. -->
                <li
                    v-for="state in STATES"
                    :key="state"
                    class="flex items-center gap-1.5"
                    :data-state="state"
                >
                    <span
                        class="panel-cell"
                        :data-state="state"
                        aria-hidden="true"
                    />
                    {{ labels[state] }}
                </li>
                <li class="flex items-center gap-1.5">
                    <TriangleAlert
                        class="size-4 text-pair-orange"
                        aria-hidden="true"
                    />
                    {{ $t('Deviation') }}
                </li>
            </ul>
        </div>

        <p class="sr-only" data-test="panel-grid-summary">{{ summary }}</p>

        <!-- On a narrow screen the panel scrolls inside its frame, from the
             keyboard too: the page itself never scrolls sideways. -->
        <div
            class="overflow-x-auto"
            tabindex="0"
            role="group"
            aria-labelledby="panel-grid-title"
        >
            <div class="flex w-max flex-col gap-2" aria-hidden="true">
                <div
                    v-for="(line, index) in lines"
                    :key="index"
                    class="flex gap-1"
                >
                    <div
                        v-for="cell in line"
                        :key="cell.port.id"
                        class="flex flex-col items-center gap-0.5"
                        data-test="panel-cell"
                        :data-port="cell.port.number"
                        :data-state="cell.state"
                    >
                        <span
                            class="panel-cell"
                            :data-state="cell.state"
                            :title="cell.title"
                            >{{ cell.port.number }}</span
                        >
                        <!-- Always there, so that the lines keep their
                             height. -->
                        <span class="flex h-3.5 items-center">
                            <TriangleAlert
                                v-if="cell.port.deviations.length > 0"
                                class="size-3.5 text-pair-orange"
                                data-test="panel-cell-deviation"
                            />
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
