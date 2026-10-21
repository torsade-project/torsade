<!-- torsade/resources/js/components/network/FreeAddresses.vue -->
<script setup lang="ts">
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { trans } from '@/lib/i18n';
import { formatCount } from '@/lib/numbers';
import type { FreeInterval, NextFreeAddress } from '@/types';

// What is left free in a subnet, as intervals: usable addresses that are
// neither the gateway, nor in a DHCP range, nor documented. Each interval
// says whether it lies in a static range, in a reserved one or outside of
// every range. The address to use next is put forward, with where it was
// taken from: a static range first, never a reserved one.
const props = defineProps<{
    intervals: FreeInterval[];
    total: number;
    // The address to document next, or null when there is none to propose.
    next: NextFreeAddress | null;
    canEdit: boolean;
}>();

const emit = defineEmits<{
    // The address to document next.
    use: [address: string];
}>();

// Where the next address was taken from: "Plage fixe « Serveurs »".
const provenance = computed<string>(() => {
    if (props.next === null) {
        return '';
    }

    if (props.next.kind === null) {
        return trans('Outside of any range');
    }

    return props.next.range
        ? trans('Static range ":name"', { name: props.next.range })
        : trans('Static range');
});

// Without a static or a reserved range, every interval is outside of any
// range: saying so on each line would only be noise.
const marked = computed<boolean>(() =>
    props.intervals.some((interval) => interval.kind !== null),
);

const markOf = (interval: FreeInterval): string => {
    const kind =
        interval.kind === 'static'
            ? trans('Static range')
            : interval.kind === 'reserved'
              ? trans('Reserved range')
              : trans('Outside of any range');

    return interval.range ? `${kind} — ${interval.range}` : kind;
};
</script>

<template>
    <section
        class="flex flex-col gap-3"
        aria-labelledby="free-addresses-title"
        data-test="free-addresses"
    >
        <h2 id="free-addresses-title" class="text-lg font-semibold">
            {{ $t('Free addresses') }}
        </h2>
        <p class="text-sm text-muted-foreground">
            {{
                $tc(
                    'Usable address that is neither the gateway, nor in a DHCP range, nor documented: :count.|Usable addresses that are neither the gateway, nor in a DHCP range, nor documented: :count.',
                    total,
                )
            }}
        </p>

        <p
            v-if="intervals.length === 0"
            class="rounded-lg border border-dashed p-4 text-muted-foreground"
        >
            {{ $t('No free address left in this subnet.') }}
        </p>

        <template v-else>
            <div
                v-if="next"
                class="flex items-center justify-between gap-3 rounded-lg border border-pair-green/50 bg-success-panel px-4 py-3"
                data-test="next-free-address-card"
            >
                <div class="flex min-w-0 flex-col gap-0.5">
                    <span class="text-note text-success">{{
                        $t('Next free address')
                    }}</span>
                    <span class="font-mono">{{ next.address }}</span>
                    <span
                        class="text-note text-foreground"
                        data-test="next-free-address-source"
                        >{{ provenance }}</span
                    >
                </div>
                <Button
                    v-if="canEdit"
                    type="button"
                    variant="success"
                    size="sm"
                    :aria-label="$t('Use :address', { address: next.address })"
                    data-test="use-next-free-address"
                    @click="emit('use', next.address)"
                >
                    {{ $t('Use') }}
                </Button>
            </div>

            <!-- What is free only lies in reserved ranges: nothing to propose. -->
            <p
                v-else
                class="rounded-lg border border-dashed p-4 text-muted-foreground"
                data-test="no-next-free-address"
            >
                {{
                    $t(
                        'No address to propose: what is free lies in reserved ranges only.',
                    )
                }}
            </p>

            <!-- A fragmented subnet has many intervals: the list scrolls in
                 its own frame, from the keyboard too. -->
            <div
                class="max-h-96 overflow-y-auto"
                tabindex="0"
                role="group"
                :aria-label="$t('Free intervals')"
            >
                <ul class="flex flex-col">
                    <li
                        v-for="interval in intervals"
                        :key="interval.start"
                        class="flex flex-col gap-0.5 border-b px-1 py-2"
                        data-test="free-interval"
                        :data-kind="interval.kind ?? 'none'"
                    >
                        <span class="flex items-baseline justify-between gap-3">
                            <span class="font-mono text-sm">
                                {{ interval.start
                                }}<template v-if="interval.count > 1">
                                    – {{ interval.end }}</template
                                >
                            </span>
                            <span class="text-sm text-muted-foreground">{{
                                formatCount(interval.count)
                            }}</span>
                        </span>
                        <span
                            v-if="marked"
                            class="text-note text-muted-foreground"
                            data-test="free-interval-mark"
                            >{{ markOf(interval) }}</span
                        >
                    </li>
                </ul>
            </div>
        </template>
    </section>
</template>
