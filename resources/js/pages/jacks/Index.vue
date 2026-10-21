<!-- torsade/resources/js/pages/jacks/Index.vue -->
<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Cable, Plug } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Heading from '@/components/Heading.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import Deviation from '@/components/network/Deviation.vue';
import JackCordEditDialog from '@/components/network/JackCordEditDialog.vue';
import JackDevicePlugDialog from '@/components/network/JackDevicePlugDialog.vue';
import JackDialog from '@/components/network/JackDialog.vue';
import JackPath from '@/components/network/JackPath.vue';
import StatusBadge from '@/components/network/StatusBadge.vue';
import RowActions from '@/components/RowActions.vue';
import { Button } from '@/components/ui/button';
import { useLocationTarget } from '@/composables/useLocationTarget';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { trans } from '@/lib/i18n';
import { formatCount } from '@/lib/numbers';
import { destroy as unplugCord } from '@/routes/jack-cords';
import { destroy, index } from '@/routes/jacks';
import { index as sitesIndex } from '@/routes/sites';
import { show as showSubnet } from '@/routes/subnets';
import type {
    JackFilters,
    JackLine,
    JackPlaceOption,
    Option,
    PanelChoice,
    PlugDevice,
    PlugPorts,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: trans('Jacks'),
                href: index(),
            },
        ],
    },
});

// Every wall jack, with where it is, its path towards a device, the
// addresses plugged into it and where its cabling differs from what is
// declared. Jacks are added to a floor from the page of its site.
const props = defineProps<{
    // The jacks the filters keep, by site, building, floor and label.
    jacks: JackLine[];
    // How many jacks there are, whatever the filters.
    total: number;
    filters: JackFilters;
    places: JackPlaceOption[];
    statuses: Option[];
    // The panels of the site of the jack being modified, asked for by its
    // window.
    panelChoices?: PanelChoice[];
    // What the window "Plug a device" chooses among, asked for by it: the
    // devices of the site of the jack, then the free ports of the one
    // chosen.
    plugDevices?: PlugDevice[];
    plugPorts?: PlugPorts | null;
}>();

// The line a link leads to, as the page opens: marked.
const target = useLocationTarget();

const page = usePage();

// Only decides which buttons are shown: the server checks every action.
const canEdit = computed<boolean>(() => page.props.auth.can.editNetwork);

const filters = reactive<{
    site: string;
    building: string;
    floor: string;
    status: string;
    deviations: boolean;
    search: string;
}>({
    site: props.filters.site,
    building: props.filters.building,
    floor: props.filters.floor,
    status: props.filters.status,
    deviations: props.filters.deviations === '1',
    search: props.filters.search,
});

// The buildings of the site chosen, and the floors of the building chosen.
const buildings = computed<JackPlaceOption['buildings']>(
    () =>
        props.places.find((site) => String(site.id) === filters.site)
            ?.buildings ?? [],
);

const floors = computed<{ id: number; name: string }[]>(
    () =>
        buildings.value.find(
            (building) => String(building.id) === filters.building,
        )?.floors ?? [],
);

// Filtering and searching are a plain GET request: the address of the page
// keeps the filters, so that a filtered list can be bookmarked or shared.
const applyFilters = (): void => {
    const query: Record<string, string> = {};

    if (filters.site !== '') {
        query.site = filters.site;
    }

    if (filters.building !== '') {
        query.building = filters.building;
    }

    if (filters.floor !== '') {
        query.floor = filters.floor;
    }

    if (filters.status !== '') {
        query.status = filters.status;
    }

    if (filters.deviations) {
        query.deviations = '1';
    }

    if (filters.search.trim() !== '') {
        query.search = filters.search.trim();
    }

    router.get(index().url, query, { preserveState: true, replace: true });
};

// A building belongs to a site, and a floor to a building: choosing a
// place again forgets what was chosen inside the former one.
const chooseSite = (): void => {
    filters.building = '';
    filters.floor = '';
    applyFilters();
};

const chooseBuilding = (): void => {
    filters.floor = '';
    applyFilters();
};

// The window of a jack, and the jack about to be deleted.
const editing = ref<boolean>(false);
const jackToEdit = ref<JackLine | null>(null);
const jackToDelete = ref<JackLine | null>(null);
const confirming = ref<boolean>(false);

const openEdit = (jack: JackLine): void => {
    jackToEdit.value = jack;
    editing.value = true;
};

const askDeletion = (jack: JackLine): void => {
    jackToDelete.value = jack;
    confirming.value = true;
};

const confirmDeletion = (): void => {
    const jack = jackToDelete.value;

    confirming.value = false;

    if (!jack) {
        return;
    }

    // A jack into which an address or a device is still plugged is
    // refused by the server, with a message that says so.
    router.delete(destroy(jack.id).url, {
        preserveScroll: true,
        onError: (errors) =>
            toast.error(
                Object.values(errors)[0] ?? trans('Something went wrong.'),
            ),
    });
};

// The device plugged into a jack: plugged from the line of the jack, its
// cord changed, and unplugged.
const jackToPlug = ref<JackLine | null>(null);
const plugging = ref<boolean>(false);

const openPlug = (jack: JackLine): void => {
    jackToPlug.value = jack;
    plugging.value = true;
};

const cordToEdit = ref<JackLine | null>(null);
const editingCord = ref<boolean>(false);

const openCord = (jack: JackLine): void => {
    cordToEdit.value = jack;
    editingCord.value = true;
};

// The two ends of the cord being changed, as the window names them.
const cordEnds = computed<{ jack: string; port: string } | null>(() => {
    const plugged = cordToEdit.value?.path.plugged;

    return cordToEdit.value && plugged
        ? {
              jack: cordToEdit.value.label,
              port: `${plugged.device.name} › ${plugged.port.name}`,
          }
        : null;
});

const jackToUnplug = ref<JackLine | null>(null);

// Asked from the window of the cord, which closes first.
const askUnplug = (): void => {
    editingCord.value = false;
    jackToUnplug.value = cordToEdit.value;
};

const confirmUnplug = (): void => {
    const cord = jackToUnplug.value?.cord;

    jackToUnplug.value = null;

    if (!cord) {
        return;
    }

    router.delete(unplugCord(cord.id).url, {
        preserveScroll: true,
        onError: (errors) =>
            toast.error(
                Object.values(errors)[0] ?? trans('Something went wrong.'),
            ),
    });
};
</script>

<template>
    <Head :title="$t('Jacks')" />

    <div class="flex flex-col gap-7">
        <Heading
            :title="$t('Jacks')"
            :description="
                $t(
                    'Each wall jack with where it is and its path: the port of a panel its horizontal cable ends on, the cord that leaves it, the port of the device at the other end and what that port carries.',
                )
            "
        />

        <!-- First use: jacks are added to a floor, on the page of their
             site. -->
        <div
            v-if="total === 0"
            class="flex flex-col items-center gap-4 rounded-lg border border-dashed p-8 text-center"
            data-test="no-jack-notice"
        >
            <p class="max-w-prose text-muted-foreground">
                {{
                    $t(
                        'No jack yet. Jacks are added to a floor from the page of its site, one by one or as a series.',
                    )
                }}
            </p>
            <Button as-child variant="outline">
                <Link :href="sitesIndex()">{{ $t('Go to the sites') }}</Link>
            </Button>
        </div>

        <template v-else>
            <form
                class="flex flex-wrap items-end gap-x-4 gap-y-3"
                data-test="jack-filters"
                @submit.prevent="applyFilters"
            >
                <div class="grid w-full gap-2 sm:w-48">
                    <Label for="filter-site">{{ $t('Site') }}</Label>
                    <NativeSelect
                        id="filter-site"
                        v-model="filters.site"
                        name="site"
                        class="h-9.5"
                        @change="chooseSite"
                    >
                        <option value="">{{ $t('All sites') }}</option>
                        <option
                            v-for="site in places"
                            :key="site.id"
                            :value="String(site.id)"
                        >
                            {{ site.name }}
                        </option>
                    </NativeSelect>
                </div>

                <div class="grid w-full gap-2 sm:w-48">
                    <Label for="filter-building">{{ $t('Building') }}</Label>
                    <NativeSelect
                        id="filter-building"
                        v-model="filters.building"
                        name="building"
                        class="h-9.5"
                        :disabled="filters.site === ''"
                        @change="chooseBuilding"
                    >
                        <option value="">{{ $t('All buildings') }}</option>
                        <option
                            v-for="building in buildings"
                            :key="building.id"
                            :value="String(building.id)"
                        >
                            {{ building.name }}
                        </option>
                    </NativeSelect>
                </div>

                <div class="grid w-full gap-2 sm:w-44">
                    <Label for="filter-floor">{{ $t('Floor') }}</Label>
                    <NativeSelect
                        id="filter-floor"
                        v-model="filters.floor"
                        name="floor"
                        class="h-9.5"
                        :disabled="filters.building === ''"
                        @change="applyFilters"
                    >
                        <option value="">{{ $t('All floors') }}</option>
                        <option
                            v-for="floor in floors"
                            :key="floor.id"
                            :value="String(floor.id)"
                        >
                            {{ floor.name }}
                        </option>
                    </NativeSelect>
                </div>

                <div class="grid w-full gap-2 sm:w-40">
                    <Label for="filter-status">{{ $t('Status') }}</Label>
                    <NativeSelect
                        id="filter-status"
                        v-model="filters.status"
                        name="status"
                        class="h-9.5"
                        @change="applyFilters"
                    >
                        <option value="">{{ $t('All statuses') }}</option>
                        <option
                            v-for="status in statuses"
                            :key="status.value"
                            :value="status.value"
                        >
                            {{ status.label }}
                        </option>
                    </NativeSelect>
                </div>

                <div class="grid w-full gap-2 sm:w-80">
                    <Label for="filter-search">{{ $t('Search') }}</Label>
                    <div class="flex gap-2">
                        <Input
                            id="filter-search"
                            v-model="filters.search"
                            type="search"
                            name="search"
                            maxlength="100"
                            autocomplete="off"
                            class="h-9.5"
                            :placeholder="$t('Label or location')"
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            class="h-9.5"
                            data-test="search-jacks"
                        >
                            {{ $t('Search') }}
                        </Button>
                    </div>
                </div>

                <label class="flex h-9.5 items-center gap-2.5">
                    <input
                        v-model="filters.deviations"
                        type="checkbox"
                        name="deviations"
                        class="size-4.5 shrink-0 rounded-xs accent-primary"
                        data-test="filter-deviations"
                        @change="applyFilters"
                    />
                    <span>{{ $t('With a deviation only') }}</span>
                </label>
            </form>

            <p
                class="text-sm text-muted-foreground"
                role="status"
                data-test="jacks-count"
            >
                {{
                    jacks.length === total
                        ? $tc(':count jack|:count jacks', total)
                        : $tc(
                              ':shown jack out of :total|:shown jacks out of :total',
                              jacks.length,
                              {
                                  shown: formatCount(jacks.length),
                                  total: formatCount(total),
                              },
                          )
                }}
            </p>

            <p
                v-if="jacks.length === 0"
                class="rounded-lg border border-dashed p-4 text-muted-foreground"
            >
                {{ $t('No jack matches these filters.') }}
            </p>

            <div v-else class="table-frame">
                <table class="table-dense" :aria-label="$t('Jacks')">
                    <thead>
                        <tr>
                            <th scope="col">{{ $t('Jack') }}</th>
                            <th scope="col">{{ $t('Location') }}</th>
                            <th scope="col">{{ $t('Path') }}</th>
                            <th scope="col">{{ $t('Addresses') }}</th>
                            <th scope="col">{{ $t('Status') }}</th>
                            <th scope="col">{{ $t('Deviations') }}</th>
                            <th v-if="canEdit" scope="col" class="text-right">
                                {{ $t('Actions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="jack in jacks"
                            :id="`jack-${jack.id}`"
                            :key="jack.id"
                            :class="
                                target === `jack-${jack.id}`
                                    ? 'bg-accent'
                                    : undefined
                            "
                            data-test="jack-row"
                            :data-jack="jack.label"
                        >
                            <td class="min-w-40">
                                <span
                                    class="font-mono text-sm font-medium"
                                    data-test="jack-label"
                                    >{{ jack.label }}</span
                                >
                                <div class="text-note text-muted-foreground">
                                    {{ jack.site.name }} ·
                                    {{ jack.path.jack.place }}
                                </div>
                            </td>
                            <td class="min-w-24">{{ jack.location }}</td>
                            <td class="min-w-52">
                                <JackPath
                                    :path="jack.path"
                                    :with-jack="false"
                                />
                            </td>
                            <td class="min-w-32">
                                <ul
                                    v-if="jack.addresses.length > 0"
                                    class="flex flex-col"
                                    data-test="jack-addresses"
                                >
                                    <li
                                        v-for="address in jack.addresses"
                                        :key="address.id"
                                    >
                                        <Link
                                            :href="
                                                showSubnet(address.subnet_id)
                                            "
                                            class="link font-mono text-sm"
                                            >{{ address.address }}</Link
                                        >
                                        <div
                                            v-if="address.hostname"
                                            class="text-note text-muted-foreground"
                                        >
                                            {{ address.hostname }}
                                        </div>
                                    </li>
                                </ul>
                            </td>
                            <td>
                                <StatusBadge
                                    :status="jack.status"
                                    :label="jack.status_label"
                                />
                            </td>
                            <td class="min-w-36">
                                <Deviation
                                    :deviations="jack.deviations"
                                    compact
                                />
                            </td>
                            <td v-if="canEdit" class="py-1">
                                <RowActions
                                    :edit-test="`edit-jack-${jack.id}`"
                                    :delete-test="`delete-jack-${jack.id}`"
                                    @edit="openEdit(jack)"
                                    @delete="askDeletion(jack)"
                                >
                                    <!-- One device at most is plugged
                                         into a jack, on the side of the
                                         desk; a retired jack takes none.
                                         The window of its cord changes it
                                         or takes it away. -->
                                    <Button
                                        v-if="jack.cord"
                                        type="button"
                                        variant="ghost"
                                        size="icon-xs"
                                        :aria-label="
                                            $t('Edit the jack cord of :jack', {
                                                jack: jack.label,
                                            })
                                        "
                                        :title="$t('Edit the jack cord')"
                                        :data-test="`edit-jack-cord-${jack.id}`"
                                        @click="openCord(jack)"
                                    >
                                        <Cable aria-hidden="true" />
                                    </Button>
                                    <Button
                                        v-else
                                        type="button"
                                        variant="ghost"
                                        size="icon-xs"
                                        :class="
                                            jack.status === 'retired'
                                                ? 'invisible'
                                                : ''
                                        "
                                        :disabled="jack.status === 'retired'"
                                        :aria-hidden="
                                            jack.status === 'retired'
                                                ? 'true'
                                                : undefined
                                        "
                                        :aria-label="
                                            $t('Plug a device into :jack', {
                                                jack: jack.label,
                                            })
                                        "
                                        :title="$t('Plug a device')"
                                        :data-test="`plug-jack-${jack.id}`"
                                        @click="openPlug(jack)"
                                    >
                                        <Plug aria-hidden="true" />
                                    </Button>
                                </RowActions>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>

    <JackDialog
        :open="editing"
        :floor="null"
        :jack="jackToEdit"
        :statuses="statuses"
        :panel-choices="panelChoices"
        :choices-query="jackToEdit ? { panels: jackToEdit.site.id } : undefined"
        @close="editing = false"
    />

    <JackDevicePlugDialog
        :open="plugging"
        :jack="jackToPlug"
        :devices="plugDevices"
        :ports="plugPorts"
        @close="plugging = false"
    />

    <JackCordEditDialog
        :open="editingCord"
        :cord="cordToEdit?.cord ?? null"
        :ends="cordEnds"
        @close="editingCord = false"
        @unplug="askUnplug"
    />

    <ConfirmDialog
        :open="jackToUnplug !== null"
        :title="$t('Unplug this device?')"
        :description="
            $t(
                'The cord from :device :port to the jack :jack is taken away; the port and the jack stay.',
                {
                    device: jackToUnplug?.path.plugged?.device.name ?? '',
                    port: jackToUnplug?.path.plugged?.port.name ?? '',
                    jack: jackToUnplug?.label ?? '',
                },
            )
        "
        :confirm-label="$t('Unplug')"
        @confirm="confirmUnplug"
        @cancel="jackToUnplug = null"
    />

    <ConfirmDialog
        :open="confirming"
        :title="$t('Delete this jack?')"
        :description="
            $t(
                ':name will be deleted. A jack into which an address or a device is still plugged cannot be deleted; its port of a panel stays.',
                { name: jackToDelete?.label ?? '' },
            )
        "
        :confirm-label="$t('Delete')"
        destructive
        @confirm="confirmDeletion"
        @cancel="confirming = false"
    />
</template>
