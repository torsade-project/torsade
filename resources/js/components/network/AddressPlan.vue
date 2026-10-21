<!-- torsade/resources/js/components/network/AddressPlan.vue -->
<script setup lang="ts">
import { computed } from 'vue';
import { trans, transChoice } from '@/lib/i18n';
import { ipv4ToNumber, numberToIpv4 } from '@/lib/ipv4';
import { formatCount } from '@/lib/numbers';
import type { AddressPlan, AddressState, IpAddress, Subnet } from '@/types';

// The address plan of a subnet, drawn like a patch panel: one cell per
// address, thirty-two per line, each line starting with the end of its
// first address. A cell tells its state by its shape, and the legend shows
// the same cells. It is read-only, and it is a picture: the table of
// addresses and the list of free intervals carry the same information for
// assistive technologies, which get a summary in words instead.
const props = defineProps<{
    subnet: Subnet;
    plan: AddressPlan;
    // The documented addresses, for the host name in the tooltip of a cell.
    addresses: IpAddress[];
}>();

const CELLS_PER_LINE = 32;

// In the order of the legend.
const STATES: AddressState[] = [
    'documented',
    'gateway',
    'planned',
    'retired',
    'dhcp_range',
    'reserved_range',
    'reservation',
    'free',
    'unusable',
];

const labels = computed<Record<AddressState, string>>(() => ({
    documented: trans('Documented'),
    gateway: trans('Gateway'),
    // Agreed with "address", feminine in French.
    planned: trans('Planned (feminine)'),
    retired: trans('Retired (feminine)'),
    dhcp_range: trans('DHCP range'),
    reserved_range: trans('Reserved range'),
    reservation: trans('DHCP reservation'),
    free: trans('Free'),
    unusable: trans('Unusable'),
}));

type Cell = {
    address: string;
    state: AddressState;
    title: string;
};

type Line = {
    label: string;
    cells: Cell[];
};

const hostNames = computed(
    () =>
        new Map(
            props.addresses.map((address) => [
                address.address,
                address.hostname,
            ]),
        ),
);

// ".32" in a subnet that fits in its last byte, ".1.32" in a larger one.
const lineLabel = (address: string): string =>
    `.${address
        .split('.')
        .slice(props.subnet.prefix_length >= 24 ? 3 : 2)
        .join('.')}`;

const lines = computed<Line[] | null>(() => {
    const network = ipv4ToNumber(props.subnet.network);

    if (props.plan.cells === null || network === null) {
        return null;
    }

    const cells = props.plan.cells.map((state, position): Cell => {
        const address = numberToIpv4(network + position);
        const hostName = hostNames.value.get(address);

        return {
            address,
            state,
            title: [address, labels.value[state], hostName]
                .filter(Boolean)
                .join(' — '),
        };
    });

    const result: Line[] = [];

    for (let start = 0; start < cells.length; start += CELLS_PER_LINE) {
        result.push({
            label: lineLabel(cells[start].address),
            cells: cells.slice(start, start + CELLS_PER_LINE),
        });
    }

    return result;
});

// What the picture shows, in words: the number of addresses in each state.
const summary = computed<string>(() => {
    const counts = new Map<AddressState, number>();

    for (const state of props.plan.cells ?? []) {
        counts.set(state, (counts.get(state) ?? 0) + 1);
    }

    return [
        transChoice(
            'Address plan of :cidr: :count address.|Address plan of :cidr: :count addresses.',
            props.plan.size,
            { cidr: props.subnet.cidr },
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
        aria-labelledby="address-plan-title"
        data-test="address-plan"
    >
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 id="address-plan-title" class="text-lg font-semibold">
                {{ $t('Address plan') }}
            </h2>
            <ul
                v-if="lines"
                class="flex flex-wrap gap-x-4 gap-y-1.5 text-note"
                data-test="address-plan-legend"
            >
                <!-- Each entry shows the very cell it explains: same
                     size, same shape, same colour. -->
                <li
                    v-for="state in STATES"
                    :key="state"
                    class="flex items-center gap-1.5"
                    :data-state="state"
                >
                    <span
                        class="plan-cell"
                        :data-state="state"
                        aria-hidden="true"
                    />
                    {{ labels[state] }}
                </li>
            </ul>
        </div>

        <template v-if="lines">
            <p class="sr-only" data-test="address-plan-summary">
                {{ summary }}
            </p>

            <!-- On a narrow screen the plan scrolls inside its frame, from
                 the keyboard too: the page itself never scrolls sideways. -->
            <div
                class="overflow-x-auto"
                tabindex="0"
                role="group"
                aria-labelledby="address-plan-title"
            >
                <div class="flex w-max flex-col gap-1.5" aria-hidden="true">
                    <div
                        v-for="line in lines"
                        :key="line.label"
                        class="flex items-center gap-3"
                    >
                        <span
                            class="w-11 shrink-0 text-right font-mono text-xs text-subtle-foreground"
                            >{{ line.label }}</span
                        >
                        <div class="flex gap-1">
                            <span
                                v-for="cell in line.cells"
                                :key="cell.address"
                                class="plan-cell"
                                :data-state="cell.state"
                                :title="cell.title"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <p v-else class="max-w-prose text-muted-foreground">
            {{
                $tc(
                    'This subnet holds :count address: the plan is only drawn up to :limit addresses. The table of addresses and the list of free addresses below remain available.|This subnet holds :count addresses: the plan is only drawn up to :limit addresses. The table of addresses and the list of free addresses below remain available.',
                    plan.size,
                    { limit: formatCount(plan.limit) },
                )
            }}
        </p>
    </section>
</template>
