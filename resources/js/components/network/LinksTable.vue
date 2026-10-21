<!-- torsade/resources/js/components/network/LinksTable.vue -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Deviation from '@/components/network/Deviation.vue';
import LinkEditDialog from '@/components/network/LinkEditDialog.vue';
import StatusBadge from '@/components/network/StatusBadge.vue';
import RowActions from '@/components/RowActions.vue';
import { trans } from '@/lib/i18n';
import { show as showDevice } from '@/routes/devices';
import { index as jacksIndex } from '@/routes/jacks';
import { destroy } from '@/routes/links';
import type {
    CabledPath,
    DeviceLink,
    LinkEnd,
    LinkLine,
    Option,
    StatusValue,
} from '@/types';

// A table of links: each with its two ends, its medium, its status and
// where its ends do not agree. The links between the ports of two
// aggregates are one aggregated link: one line, which opens on the links
// it is made of. A link is modified and deleted from its line.
//
// On the page of a device, the near end of a link is a port of that
// device, named alone; in the list of every link, both ends are told in
// full, each with its site. A link whose cabling is its physical path says
// so under its medium: "through PB-B 13 › B.13", which leads to the jack.
const props = defineProps<{
    lines: LinkLine[];
    media: Option[];
    statuses: Option[];
    canEdit: boolean;
    // The device whose page shows the table, if any.
    device?: { id: number; site_id: number } | null;
}>();

// The aggregated links that are open, by the key of their line.
const open = ref<Set<string>>(new Set());

const toggle = (key: string): void => {
    const next = new Set(open.value);

    if (next.has(key)) {
        next.delete(key);
    } else {
        next.add(key);
    }

    open.value = next;
};

// How a line names its near end, for the tests and for the buttons of the
// line: the port alone on the page of its device, with its device
// elsewhere.
const portOf = (end: LinkEnd): string =>
    props.device ? end.port.name : `${end.device.name} › ${end.port.name}`;

const aggregateOf = (end: LinkEnd): string =>
    props.device
        ? (end.aggregate?.name ?? '')
        : `${end.device.name} › ${end.aggregate?.name ?? ''}`;

// Whether the site of an end is told: always in the list of every link,
// and for another site than the one of the device on its page.
const showsSite = (end: LinkEnd): boolean =>
    !props.device || end.device.site.id !== props.device.site_id;

// The media of the links of an aggregated link, each named once.
const mediaOf = (line: LinkLine): string =>
    [
        ...new Set(
            line.links.map(
                (link) => link.medium_label ?? trans('Not specified'),
            ),
        ),
    ].join(', ');

// Where the jack of a cabled path is listed: the list of jacks of its
// floor, at its line.
const jackHref = (path: CabledPath): string =>
    `${jacksIndex({ query: { floor: path.jack.floor_id } }).url}#jack-${path.jack.id}`;

// The whole places of the panel and of the jack, for the tooltip.
const placesOf = (path: CabledPath): string =>
    `${path.panel_port.place} › ${path.panel_port.name} — ${path.jack.place} › ${path.jack.label}`;

// Their statuses, each shown once.
const statusesOf = (line: LinkLine): { status: StatusValue; label: string }[] =>
    line.links
        .map((link) => ({ status: link.status, label: link.status_label }))
        .filter(
            (one, index, all) =>
                all.findIndex((other) => other.status === one.status) === index,
        );

const linkToEdit = ref<DeviceLink | null>(null);
const editOpen = ref<boolean>(false);

const openEdit = (link: DeviceLink): void => {
    linkToEdit.value = link;
    editOpen.value = true;
};

const linkToDelete = ref<DeviceLink | null>(null);

const confirmDeletion = (): void => {
    const link = linkToDelete.value;

    linkToDelete.value = null;

    if (!link) {
        return;
    }

    router.delete(destroy(link.id).url, {
        preserveScroll: true,
        onError: (errors) =>
            toast.error(
                Object.values(errors)[0] ?? trans('Something went wrong.'),
            ),
    });
};
</script>

<template>
    <div class="table-frame">
        <table class="table-dense" :aria-label="$t('Links')">
            <thead>
                <tr>
                    <th scope="col">
                        {{ device ? $t('Port') : $t('End 1') }}
                    </th>
                    <th scope="col">
                        {{ device ? $t('Opposite') : $t('End 2') }}
                    </th>
                    <th scope="col">{{ $t('Medium') }}</th>
                    <th scope="col">{{ $t('Status') }}</th>
                    <th scope="col">{{ $t('Deviations') }}</th>
                    <th v-if="canEdit" scope="col" class="text-right">
                        {{ $t('Actions') }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <template v-for="line in lines" :key="line.key">
                    <!-- An aggregated link: one line for the links between
                         two aggregates. -->
                    <tr
                        v-if="line.aggregated"
                        data-test="aggregated-link-row"
                        :data-link="aggregateOf(line.links[0].near)"
                    >
                        <td class="whitespace-nowrap">
                            <button
                                type="button"
                                class="-ml-1 inline-flex items-center gap-1.5 rounded-sm px-1"
                                :aria-expanded="open.has(line.key)"
                                :data-test="`toggle-link-${aggregateOf(line.links[0].near)}`"
                                @click="toggle(line.key)"
                            >
                                <component
                                    :is="
                                        open.has(line.key)
                                            ? ChevronDown
                                            : ChevronRight
                                    "
                                    class="size-4 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <span v-if="!device"
                                    >{{
                                        line.links[0].near.device.name
                                    }}
                                    ›</span
                                >
                                <span class="font-mono text-sm font-medium">{{
                                    line.links[0].near.aggregate?.name
                                }}</span>
                            </button>
                            <div class="text-note text-muted-foreground">
                                <template v-if="!device"
                                    >{{ line.links[0].near.device.site.name }}
                                    —
                                </template>
                                {{
                                    $tc(
                                        'Aggregated link, :count link|Aggregated link, :count links',
                                        line.links.length,
                                    )
                                }}
                            </div>
                        </td>
                        <td class="min-w-44">
                            <Link
                                :href="showDevice(line.links[0].far.device.id)"
                                class="link"
                                >{{ line.links[0].far.device.name }}</Link
                            >
                            ›
                            <span class="font-mono text-sm">{{
                                line.links[0].far.aggregate?.name
                            }}</span>
                            <div
                                v-if="showsSite(line.links[0].far)"
                                class="text-note text-muted-foreground"
                            >
                                {{ line.links[0].far.device.site.name }}
                            </div>
                        </td>
                        <td class="whitespace-nowrap">
                            {{ mediaOf(line) }}
                        </td>
                        <td>
                            <div class="flex flex-col gap-1">
                                <StatusBadge
                                    v-for="one in statusesOf(line)"
                                    :key="one.status"
                                    :status="one.status"
                                    :label="one.label"
                                />
                            </div>
                        </td>
                        <td class="min-w-44">
                            <Deviation :deviations="line.deviations" compact />
                        </td>
                        <td v-if="canEdit"></td>
                    </tr>

                    <!-- A link: a line of its own, or one of the links of
                         an aggregated link that is open. -->
                    <template v-if="!line.aggregated || open.has(line.key)">
                        <tr
                            v-for="link in line.links"
                            :key="link.id"
                            :class="line.aggregated ? 'bg-sunken' : ''"
                            data-test="link-row"
                            :data-link="portOf(link.near)"
                        >
                            <td
                                class="whitespace-nowrap"
                                :class="line.aggregated ? 'pl-9' : ''"
                            >
                                <template v-if="!device">
                                    <Link
                                        :href="showDevice(link.near.device.id)"
                                        class="link"
                                        >{{ link.near.device.name }}</Link
                                    >
                                    ›
                                </template>
                                <span class="font-mono text-sm font-medium">{{
                                    link.near.port.name
                                }}</span>
                                <!-- A port of an aggregate that does not
                                     face an aggregate. -->
                                <span
                                    v-if="
                                        !line.aggregated && link.near.aggregate
                                    "
                                    class="text-muted-foreground"
                                >
                                    ({{ link.near.aggregate.name }})</span
                                >
                                <div
                                    v-if="!line.aggregated && !device"
                                    class="text-note text-muted-foreground"
                                >
                                    {{ link.near.device.site.name }}
                                </div>
                            </td>
                            <td class="min-w-44">
                                <Link
                                    :href="showDevice(link.far.device.id)"
                                    class="link"
                                    >{{ link.far.device.name }}</Link
                                >
                                ›
                                <span class="font-mono text-sm">{{
                                    link.far.port.name
                                }}</span>
                                <span
                                    v-if="
                                        !line.aggregated && link.far.aggregate
                                    "
                                    class="text-muted-foreground"
                                >
                                    ({{ link.far.aggregate.name }})</span
                                >
                                <div
                                    v-if="
                                        !line.aggregated && showsSite(link.far)
                                    "
                                    class="text-note text-muted-foreground"
                                >
                                    {{ link.far.device.site.name }}
                                </div>
                                <div
                                    v-if="link.description"
                                    class="text-note whitespace-pre-line text-muted-foreground"
                                    data-test="link-description"
                                >
                                    {{ link.description }}
                                </div>
                            </td>
                            <td class="whitespace-nowrap">
                                <span v-if="link.medium_label">{{
                                    link.medium_label
                                }}</span>
                                <span v-else class="text-muted-foreground">{{
                                    $t('Not specified')
                                }}</span>
                                <div
                                    v-if="link.path"
                                    class="text-note"
                                    data-test="link-path"
                                >
                                    <span class="text-muted-foreground">{{
                                        $t('through')
                                    }}</span>
                                    {{ ' ' }}
                                    <Link
                                        :href="jackHref(link.path)"
                                        class="link font-mono"
                                        :title="placesOf(link.path)"
                                        >{{ link.path.panel_port.name }} ›
                                        {{ link.path.jack.label }}</Link
                                    >
                                </div>
                            </td>
                            <td>
                                <StatusBadge
                                    :status="link.status"
                                    :label="link.status_label"
                                />
                            </td>
                            <td class="min-w-44">
                                <!-- The deviations of an aggregated link are
                                     told once, on its line. -->
                                <Deviation
                                    v-if="!line.aggregated"
                                    :deviations="link.deviations"
                                    compact
                                />
                            </td>
                            <td v-if="canEdit" class="py-1">
                                <RowActions
                                    :edit-test="`edit-link-${portOf(link.near)}`"
                                    :delete-test="`delete-link-${portOf(link.near)}`"
                                    @edit="openEdit(link)"
                                    @delete="linkToDelete = link"
                                />
                            </td>
                        </tr>
                    </template>
                </template>
            </tbody>
        </table>
    </div>

    <LinkEditDialog
        :open="editOpen"
        :link="linkToEdit"
        :media="media"
        :statuses="statuses"
        @close="editOpen = false"
    />

    <ConfirmDialog
        :open="linkToDelete !== null"
        :title="$t('Delete this link?')"
        :description="
            $t(
                'The link between :near and :far will be deleted. Its two ports stay, free to be linked again.',
                {
                    near: `${linkToDelete?.near.device.name ?? ''} › ${linkToDelete?.near.port.name ?? ''}`,
                    far: `${linkToDelete?.far.device.name ?? ''} › ${linkToDelete?.far.port.name ?? ''}`,
                },
            )
        "
        :confirm-label="$t('Delete')"
        destructive
        @confirm="confirmDeletion"
        @cancel="linkToDelete = null"
    />
</template>
