<!-- torsade/resources/js/components/search/SearchResult.vue -->
<script setup lang="ts">
import {
    Binary,
    Blocks,
    BookMarked,
    Building,
    DoorClosed,
    EthernetPort,
    Layers,
    LayoutTemplate,
    MapPin,
    Network,
    PanelTop,
    Plug,
    Rows3,
    Server,
} from '@lucide/vue';
import type { Component } from 'vue';
import SearchSegments from '@/components/search/SearchSegments.vue';
import StatusBadge from '@/components/network/StatusBadge.vue';
import type { SearchItem, SearchResultType } from '@/types';

// One result of the search: the icon of its kind, its label, where it is,
// and its status when it is not in service. The dot of a retired object
// only reads on the background of the page: on the highlight of the option
// reached in the window, it is hidden and its label stays in place.
withDefaults(
    defineProps<{
        item: SearchItem;
        highlighted?: boolean;
    }>(),
    {
        highlighted: false,
    },
);

const icons: Record<SearchResultType, Component> = {
    site: MapPin,
    building: Building,
    floor: Rows3,
    room: DoorClosed,
    vlan: Layers,
    catalog: BookMarked,
    block: Blocks,
    subnet: Network,
    address: Binary,
    device: Server,
    port: Plug,
    jack: EthernetPort,
    panel: PanelTop,
    template: LayoutTemplate,
};
</script>

<template>
    <span class="flex min-w-0 items-start gap-3">
        <component
            :is="icons[item.type]"
            class="mt-0.5 size-4 shrink-0 text-muted-foreground"
            aria-hidden="true"
        />
        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
            <span class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
                <span class="min-w-0 break-words" data-test="search-label">
                    <SearchSegments :segments="item.label" />
                </span>
                <StatusBadge
                    v-if="item.status && item.status_label"
                    class="text-note"
                    :status="item.status"
                    :label="item.status_label"
                    :hide-dot="highlighted && item.status === 'retired'"
                />
            </span>
            <span
                v-if="item.context.length > 0"
                class="min-w-0 text-note break-words text-muted-foreground"
            >
                <template
                    v-for="(part, position) in item.context"
                    :key="position"
                >
                    <span v-if="position > 0" aria-hidden="true"> · </span>
                    <SearchSegments :segments="part" />
                </template>
            </span>
            <span
                v-if="item.excerpt"
                class="min-w-0 text-note break-words text-muted-foreground"
                data-test="search-excerpt"
            >
                {{ item.excerpt.field }}{{ $t(':') }}
                <SearchSegments :segments="item.excerpt.segments" />
            </span>
        </span>
    </span>
</template>
