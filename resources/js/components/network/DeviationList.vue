<!-- torsade/resources/js/components/network/DeviationList.vue -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import Deviation from '@/components/network/Deviation.vue';
import type { Deviation as DeviationData } from '@/types';

// The deviations of a page, object by object: what differs from the
// standard of the organisation, each with a link to the object and the
// reason in words. Nothing of it is an error.
defineProps<{
    items: {
        // Unique on the page.
        key: string;
        // How the object is named: "10.2.5.0/24", "VLAN 110 — Bureaux".
        label: string;
        href: NonNullable<InertiaLinkProps['href']>;
        // Whether the label is an address, written in the fixed-width font.
        technical?: boolean;
        deviations: DeviationData[];
    }[];
    // Whether the page has already told, above this list, what differs
    // about its own object: the list is then never said to be all there is.
    toldElsewhere?: boolean;
    // What the deviations of this page are about, when it is not the
    // standard of the organisation.
    description?: string;
    // The title of the list, when the page names its deviations otherwise.
    title?: string;
}>();
</script>

<template>
    <section
        class="flex flex-col gap-3"
        :aria-label="title ?? $t('Deviations')"
        data-test="deviation-list"
    >
        <h2 class="text-lg font-semibold">{{ title ?? $t('Deviations') }}</h2>
        <p class="max-w-prose text-sm text-muted-foreground">
            {{
                description ??
                $t(
                    'What differs from the catalogue, from the address blocks or from the addressing rule. A deviation refuses nothing: it shows the network as it is.',
                )
            }}
        </p>

        <p
            v-if="items.length === 0"
            class="rounded-lg border border-dashed p-4 text-muted-foreground"
        >
            {{
                toldElsewhere ? $t('No other deviation.') : $t('No deviation.')
            }}
        </p>

        <ul v-else class="panel flex flex-col divide-y">
            <li
                v-for="item in items"
                :key="item.key"
                class="flex flex-wrap items-start gap-x-4 gap-y-1.5 px-4 py-3"
                data-test="deviation-item"
            >
                <Link
                    :href="item.href"
                    class="link font-medium whitespace-nowrap"
                    :class="item.technical ? 'font-mono text-sm' : ''"
                    >{{ item.label }}</Link
                >
                <Deviation :deviations="item.deviations" />
            </li>
        </ul>
    </section>
</template>
