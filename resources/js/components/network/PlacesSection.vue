<!-- torsade/resources/js/components/network/PlacesSection.vue -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import JackDialog from '@/components/network/JackDialog.vue';
import JackSeriesDialog from '@/components/network/JackSeriesDialog.vue';
import PatchPanelDialog from '@/components/network/PatchPanelDialog.vue';
import PlaceDialog from '@/components/network/PlaceDialog.vue';
import type { PlaceTarget } from '@/components/network/PlaceDialog.vue';
import RowActions from '@/components/RowActions.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useLocationTarget } from '@/composables/useLocationTarget';
import { trans } from '@/lib/i18n';
import { formatCount } from '@/lib/numbers';
import { destroy as destroyBuilding } from '@/routes/buildings';
import { create as createDevice } from '@/routes/devices';
import { destroy as destroyFloor } from '@/routes/floors';
import {
    destroy as destroyPanel,
    show as showPanel,
} from '@/routes/patch-panels';
import { destroy as destroyRoom } from '@/routes/rooms';
import type {
    Option,
    PanelChoice,
    PatchPanelSummary,
    PlaceBuilding,
    PlaceFloor,
    PlaceRoom,
} from '@/types';

// The places of a site, as a tree: its buildings, the floors of each from
// the lowest up, with their wall jacks, the rooms of each floor, and the
// patch panels kept in each room. Each level is created, modified and
// deleted in a window, without leaving the page; a panel has a page of its
// own. Jacks are added to a floor one by one or as a series.
const props = defineProps<{
    siteId: number;
    places: PlaceBuilding[];
    // What the window of a patch panel chooses among.
    panelConnectors: Option[];
    statuses: Option[];
    // The statuses of a jack, agreed with it.
    jackStatuses: Option[];
    // The panels of the site with their free ports, asked for by a window
    // of jacks.
    panelChoices?: PanelChoice[];
    canEdit: boolean;
}>();

// The line a link leads to, as the page opens: marked.
const lineTarget = useLocationTarget();

const dialogOpen = ref<boolean>(false);
const target = ref<PlaceTarget | null>(null);

const open = (next: PlaceTarget): void => {
    target.value = next;
    dialogOpen.value = true;
};

// The window of a patch panel: a new one in a room, or one to modify.
const panelDialogOpen = ref<boolean>(false);
const panelRoom = ref<{ id: number; path: string } | null>(null);
const panelToEdit = ref<PatchPanelSummary | null>(null);

const newPanel = (
    building: PlaceBuilding,
    floor: PlaceFloor,
    room: PlaceRoom,
): void => {
    panelRoom.value = {
        id: room.id,
        path: `${building.name} › ${floor.name} › ${room.name}`,
    };
    panelToEdit.value = null;
    panelDialogOpen.value = true;
};

const editPanel = (panel: PatchPanelSummary): void => {
    panelRoom.value = null;
    panelToEdit.value = panel;
    panelDialogOpen.value = true;
};

// The windows of jacks: one jack, or a series, on a floor.
const jackFloor = ref<{ id: number; path: string } | null>(null);
const jackDialogOpen = ref<boolean>(false);
const jackSeriesOpen = ref<boolean>(false);

const floorOf = (
    building: PlaceBuilding,
    floor: PlaceFloor,
): { id: number; path: string } => ({
    id: floor.id,
    path: `${building.name} › ${floor.name}`,
});

const newJack = (building: PlaceBuilding, floor: PlaceFloor): void => {
    jackFloor.value = floorOf(building, floor);
    jackDialogOpen.value = true;
};

const newJacks = (building: PlaceBuilding, floor: PlaceFloor): void => {
    jackFloor.value = floorOf(building, floor);
    jackSeriesOpen.value = true;
};

// What is about to be deleted, once confirmed.
type Doomed = {
    kind: 'building' | 'floor' | 'room' | 'panel';
    id: number;
    name: string;
};

// Kept once the window is closed, so that its words do not change while
// it fades out.
const doomed = ref<Doomed | null>(null);
const confirming = ref<boolean>(false);

const askDeletion = (place: Doomed): void => {
    doomed.value = place;
    confirming.value = true;
};

const deletionTitle = computed<string>(() => {
    switch (doomed.value?.kind) {
        case 'building':
            return trans('Delete this building?');
        case 'floor':
            return trans('Delete this floor?');
        case 'panel':
            return trans('Delete this patch panel?');
        default:
            return trans('Delete this room?');
    }
});

const deletionDescription = computed<string>(() => {
    const name = doomed.value?.name ?? '';

    switch (doomed.value?.kind) {
        case 'building':
            return trans(
                ':name will be deleted. A building that still has floors cannot be deleted.',
                { name },
            );
        case 'floor':
            return trans(
                ':name will be deleted. A floor that still has rooms or jacks cannot be deleted.',
                { name },
            );
        case 'panel':
            return trans(
                ':name will be deleted with its ports. A panel one of whose ports still has a jack or a cord cannot be deleted.',
                { name },
            );
        default:
            return trans(
                ':name will be deleted. A room that still holds devices, members of devices or patch panels cannot be deleted.',
                { name },
            );
    }
});

const confirmDeletion = (): void => {
    const place = doomed.value;

    confirming.value = false;

    if (!place) {
        return;
    }

    const url = {
        building: destroyBuilding,
        floor: destroyFloor,
        room: destroyRoom,
        panel: destroyPanel,
    }[place.kind](place.id).url;

    // A place that still holds something is refused by the server, with a
    // message that says what to delete first.
    router.delete(url, {
        preserveScroll: true,
        onError: (errors) =>
            toast.error(
                Object.values(errors)[0] ?? trans('Something went wrong.'),
            ),
    });
};

const editBuilding = (building: PlaceBuilding): void =>
    open({ kind: 'building', siteId: props.siteId, place: building });

const editFloor = (building: PlaceBuilding, floor: PlaceFloor): void =>
    open({ kind: 'floor', building, place: floor });

const editRoom = (
    building: PlaceBuilding,
    floor: PlaceFloor,
    room: PlaceRoom,
): void => open({ kind: 'room', building, floor, place: room });
</script>

<template>
    <section
        class="flex flex-col gap-3"
        :aria-label="$t('Places')"
        data-test="site-places"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold">
                {{ $t('Places') }}
                <span class="font-normal text-muted-foreground">{{
                    formatCount(places.length)
                }}</span>
            </h2>
            <Button
                v-if="canEdit"
                type="button"
                variant="outline"
                size="sm"
                data-test="create-building"
                @click="open({ kind: 'building', siteId, place: null })"
            >
                {{ $t('New building') }}
            </Button>
        </div>

        <p
            v-if="places.length === 0"
            class="rounded-lg border border-dashed p-4 text-muted-foreground"
            data-test="site-places-empty"
        >
            {{
                $t(
                    'No building is declared for this site. Buildings, floors and rooms say where the devices are; a device can also belong to the site without a room.',
                )
            }}
        </p>

        <ul v-else class="flex flex-col gap-3">
            <li
                v-for="building in places"
                :id="`building-${building.id}`"
                :key="building.id"
                class="panel flex flex-col"
                :class="{
                    'ring-2 ring-ring':
                        lineTarget === `building-${building.id}`,
                }"
                data-test="place-building"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3"
                >
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <div class="flex flex-wrap items-center gap-2.5">
                            <h3 class="font-semibold">{{ building.name }}</h3>
                            <span
                                v-if="building.code"
                                class="inline-flex items-center rounded-sm border border-border-strong px-2 font-mono text-sm"
                            >
                                <span class="sr-only">{{ $t('Code') }}</span>
                                {{ building.code }}
                            </span>
                        </div>
                        <p
                            v-if="building.description"
                            class="text-sm whitespace-pre-line text-muted-foreground"
                        >
                            {{ building.description }}
                        </p>
                    </div>
                    <div v-if="canEdit" class="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :data-test="`create-floor-${building.id}`"
                            @click="
                                open({ kind: 'floor', building, place: null })
                            "
                        >
                            {{ $t('New floor') }}
                        </Button>
                        <RowActions
                            :edit-test="`edit-building-${building.id}`"
                            :delete-test="`delete-building-${building.id}`"
                            @edit="editBuilding(building)"
                            @delete="
                                askDeletion({
                                    kind: 'building',
                                    id: building.id,
                                    name: building.name,
                                })
                            "
                        />
                    </div>
                </div>

                <p
                    v-if="building.floors.length === 0"
                    class="border-t px-4 py-3 text-sm text-muted-foreground"
                >
                    {{ $t('No floor in this building yet.') }}
                </p>

                <ul v-else class="flex flex-col">
                    <li
                        v-for="floor in building.floors"
                        :id="`floor-${floor.id}`"
                        :key="floor.id"
                        class="flex flex-col border-t"
                        :class="{
                            'bg-accent': lineTarget === `floor-${floor.id}`,
                        }"
                        data-test="place-floor"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-2.5 pr-4 pl-6"
                        >
                            <div class="flex min-w-0 flex-col gap-0.5">
                                <div
                                    class="flex flex-wrap items-baseline gap-2.5"
                                >
                                    <span class="font-medium">{{
                                        floor.name
                                    }}</span>
                                    <span
                                        class="text-note whitespace-nowrap text-muted-foreground"
                                    >
                                        {{ $t('Level') }}
                                        <span class="font-mono">{{
                                            floor.level
                                        }}</span>
                                    </span>
                                    <span
                                        class="text-note whitespace-nowrap text-muted-foreground"
                                        data-test="floor-jacks"
                                    >
                                        {{
                                            $tc(
                                                ':count jack|:count jacks',
                                                floor.jacks_count,
                                            )
                                        }}
                                    </span>
                                </div>
                                <p
                                    v-if="floor.description"
                                    class="text-sm whitespace-pre-line text-muted-foreground"
                                >
                                    {{ floor.description }}
                                </p>
                            </div>
                            <div
                                v-if="canEdit"
                                class="flex flex-wrap items-center gap-x-2 gap-y-1"
                            >
                                <button
                                    type="button"
                                    class="link text-sm whitespace-nowrap"
                                    :data-test="`create-jack-${floor.id}`"
                                    @click="newJack(building, floor)"
                                >
                                    {{ $t('Add a jack') }}
                                </button>
                                <button
                                    type="button"
                                    class="link text-sm whitespace-nowrap"
                                    :data-test="`create-jacks-${floor.id}`"
                                    @click="newJacks(building, floor)"
                                >
                                    {{ $t('Add jacks as a series') }}
                                </button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :data-test="`create-room-${floor.id}`"
                                    @click="
                                        open({
                                            kind: 'room',
                                            building,
                                            floor,
                                            place: null,
                                        })
                                    "
                                >
                                    {{ $t('New room') }}
                                </Button>
                                <RowActions
                                    :edit-test="`edit-floor-${floor.id}`"
                                    :delete-test="`delete-floor-${floor.id}`"
                                    @edit="editFloor(building, floor)"
                                    @delete="
                                        askDeletion({
                                            kind: 'floor',
                                            id: floor.id,
                                            name: floor.name,
                                        })
                                    "
                                />
                            </div>
                        </div>

                        <ul
                            v-if="floor.rooms.length > 0"
                            class="flex flex-col pb-1.5"
                        >
                            <li
                                v-for="room in floor.rooms"
                                :id="`room-${room.id}`"
                                :key="room.id"
                                class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-1.5 pr-4 pl-10"
                                :class="{
                                    'bg-accent':
                                        lineTarget === `room-${room.id}`,
                                }"
                                data-test="place-room"
                            >
                                <div class="flex min-w-0 flex-col gap-0.5">
                                    <div
                                        class="flex flex-wrap items-center gap-x-2.5 gap-y-1"
                                    >
                                        <span>{{ room.name }}</span>
                                        <Badge
                                            v-if="room.is_technical"
                                            variant="outline"
                                            data-test="technical-label"
                                        >
                                            {{ $t('Technical') }}
                                        </Badge>
                                        <span
                                            class="text-note whitespace-nowrap text-muted-foreground"
                                            data-test="room-devices"
                                        >
                                            {{
                                                $tc(
                                                    ':count device|:count devices',
                                                    room.devices_count,
                                                )
                                            }}
                                        </span>
                                    </div>
                                    <p
                                        v-if="room.description"
                                        class="text-sm whitespace-pre-line text-muted-foreground"
                                    >
                                        {{ room.description }}
                                    </p>
                                </div>
                                <!-- On a narrow screen, its actions go to the
                                     next line rather than past the edge. -->
                                <div
                                    v-if="canEdit"
                                    class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1"
                                >
                                    <button
                                        type="button"
                                        class="link text-sm whitespace-nowrap"
                                        :data-test="`create-panel-${room.id}`"
                                        @click="newPanel(building, floor, room)"
                                    >
                                        {{ $t('Add a patch panel') }}
                                    </button>
                                    <Link
                                        :href="
                                            createDevice({
                                                query: { room: room.id },
                                            })
                                        "
                                        class="link text-sm whitespace-nowrap"
                                        >{{ $t('Add a device') }}</Link
                                    >
                                    <RowActions
                                        :edit-test="`edit-room-${room.id}`"
                                        :delete-test="`delete-room-${room.id}`"
                                        @edit="editRoom(building, floor, room)"
                                        @delete="
                                            askDeletion({
                                                kind: 'room',
                                                id: room.id,
                                                name: room.name,
                                            })
                                        "
                                    />
                                </div>

                                <!-- The patch panels kept in the room. -->
                                <ul
                                    v-if="room.panels.length > 0"
                                    class="flex w-full flex-col gap-1 pl-4"
                                    :aria-label="$t('Patch panels')"
                                >
                                    <li
                                        v-for="panel in room.panels"
                                        :key="panel.id"
                                        class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1"
                                        data-test="place-panel"
                                        :data-panel="panel.name"
                                    >
                                        <span
                                            class="flex flex-wrap items-baseline gap-x-2.5"
                                        >
                                            <Link
                                                :href="showPanel(panel.id)"
                                                class="link font-mono text-sm"
                                                >{{ panel.name }}</Link
                                            >
                                            <span
                                                class="text-note text-muted-foreground"
                                            >
                                                {{ panel.connector_label
                                                }}<template
                                                    v-if="panel.category"
                                                    >,
                                                    {{
                                                        $t(
                                                            'category :category',
                                                            {
                                                                category:
                                                                    panel.category,
                                                            },
                                                        )
                                                    }}</template
                                                >
                                                —
                                                {{
                                                    $tc(
                                                        ':count port occupied out of :total|:count ports occupied out of :total',
                                                        panel.occupied,
                                                        {
                                                            total: panel.port_count,
                                                        },
                                                    )
                                                }}
                                            </span>
                                        </span>
                                        <RowActions
                                            v-if="canEdit"
                                            :edit-test="`edit-panel-${panel.id}`"
                                            :delete-test="`delete-panel-${panel.id}`"
                                            @edit="editPanel(panel)"
                                            @delete="
                                                askDeletion({
                                                    kind: 'panel',
                                                    id: panel.id,
                                                    name: panel.name,
                                                })
                                            "
                                        />
                                    </li>
                                </ul>
                            </li>
                        </ul>
                    </li>
                </ul>
            </li>
        </ul>
    </section>

    <PlaceDialog
        :open="dialogOpen"
        :target="target"
        :places="places"
        @close="dialogOpen = false"
    />

    <PatchPanelDialog
        :open="panelDialogOpen"
        :room="panelRoom"
        :panel="panelToEdit"
        :connectors="panelConnectors"
        :statuses="statuses"
        @close="panelDialogOpen = false"
    />

    <JackDialog
        :open="jackDialogOpen"
        :floor="jackFloor"
        :jack="null"
        :statuses="jackStatuses"
        :panel-choices="panelChoices"
        @close="jackDialogOpen = false"
    />

    <JackSeriesDialog
        :open="jackSeriesOpen"
        :floor="jackFloor"
        :statuses="jackStatuses"
        :panel-choices="panelChoices"
        @close="jackSeriesOpen = false"
    />

    <ConfirmDialog
        :open="confirming"
        :title="deletionTitle"
        :description="deletionDescription"
        :confirm-label="$t('Delete')"
        destructive
        @confirm="confirmDeletion"
        @cancel="confirming = false"
    />
</template>
