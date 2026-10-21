<!-- torsade/resources/js/components/network/LinkDialog.vue -->
<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { trans, transChoice } from '@/lib/i18n';
import { store } from '@/routes/links';
import type {
    LinkableDevices,
    LinkDraft,
    OppositePorts,
    Option,
    Port,
} from '@/types';

type FreePort = OppositePorts['ports'][number];

// Ports of a device linked to as many free ports of another one: one port
// from its line, several from the ports ticked. The opposite device is
// looked for by its name, on every site; its free ports are then listed,
// each with what it carries. Several ports are linked in the order of each
// device, all of them or none.
const props = defineProps<{
    open: boolean;
    device: { id: number; name: string };
    // The ports to link, in the order of the device.
    ports: Port[];
    media: Option[];
    statuses: Option[];
    // What the server found for what was typed, and the free ports of the
    // device chosen. Both are only worked out when this window asks.
    linkable?: LinkableDevices;
    oppositePorts?: OppositePorts | null;
    // The port in front already chosen, when the window opens from the
    // deviation of a cabling without a link.
    draft?: LinkDraft['opposite'] | null;
}>();

const emit = defineEmits<{
    close: [];
    // The links are saved: what was ticked to make them is done with.
    saved: [];
}>();

const form = useForm<{
    port_ids: number[];
    opposite_device_id: number | null;
    opposite_port_ids: number[];
    medium: string;
    status: string;
    description: string;
}>({
    port_ids: [],
    opposite_device_id: null,
    opposite_port_ids: [],
    medium: '',
    status: 'active',
    description: '',
});

const search = ref<string>('');
const searching = ref<boolean>(false);
// The opposite device, once chosen.
const opposite = ref<{ id: number; name: string; site: string } | null>(null);
const loadingPorts = ref<boolean>(false);

// Only the list is asked again, and the address of the page stays what it
// is: what is typed here is not a filter of the page.
const look = (): void => {
    router.reload({
        only: ['linkable'],
        data: { search: search.value.trim() },
        preserveUrl: true,
        onStart: () => {
            searching.value = true;
        },
        onFinish: () => {
            searching.value = false;
        },
    });
};

let timer: ReturnType<typeof setTimeout> | undefined;

// The list follows what is typed, once the typing pauses.
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(look, 250);
});

onUnmounted(() => clearTimeout(timer));

const choose = (device: { id: number; name: string; site: string }): void => {
    opposite.value = device;
    form.opposite_device_id = device.id;
    form.opposite_port_ids = [];
    form.clearErrors('opposite_device_id', 'opposite_port_ids');

    router.reload({
        only: ['oppositePorts'],
        data: { opposite: device.id },
        preserveUrl: true,
        onStart: () => {
            loadingPorts.value = true;
        },
        onFinish: () => {
            loadingPorts.value = false;
        },
    });
};

const changeDevice = (): void => {
    opposite.value = null;
    form.opposite_device_id = null;
    form.opposite_port_ids = [];
};

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.port_ids = props.ports.map((port) => port.id);
        form.opposite_device_id = null;
        form.opposite_port_ids = [];
        form.medium = '';
        form.status = 'active';
        form.description = '';
        opposite.value = null;

        // Opened from a deviation, the port in front is already chosen:
        // only its device is asked for.
        if (props.draft) {
            choose(props.draft.device);
            form.opposite_port_ids = [props.draft.port_id];

            return;
        }

        if (search.value === '') {
            look();
        } else {
            // The watcher above asks for the list.
            search.value = '';
        }
    },
    // Opened from a deviation, the window is open as soon as it is there.
    { immediate: true },
);

// The free ports of the device chosen: those of a device chosen before are
// not shown in their place while the list is on its way.
const freePorts = computed<FreePort[] | null>(() =>
    opposite.value !== null &&
    !loadingPorts.value &&
    props.oppositePorts?.device.id === opposite.value.id
        ? props.oppositePorts.ports
        : null,
);

// Its free ports member by member, in the order of the device.
const groups = computed<{ member: number; ports: FreePort[] }[]>(() => {
    const byMember = new Map<number, FreePort[]>();

    for (const port of freePorts.value ?? []) {
        byMember.set(port.member, [...(byMember.get(port.member) ?? []), port]);
    }

    return [...byMember.entries()].map(([member, ports]) => ({
        member,
        ports,
    }));
});

const several = computed<boolean>(() => props.ports.length > 1);

// One port is chosen in place of another; several are ticked, as many as
// there are ports to link and no more.
const togglePort = (port: FreePort): void => {
    if (!several.value) {
        form.opposite_port_ids = [port.id];

        return;
    }

    form.opposite_port_ids = form.opposite_port_ids.includes(port.id)
        ? form.opposite_port_ids.filter((id) => id !== port.id)
        : [...form.opposite_port_ids, port.id];
};

const full = computed<boolean>(
    () => form.opposite_port_ids.length >= props.ports.length,
);

// The opposite ports chosen, in the order of their device: the first port
// to link goes with the first of them, whatever the order they were ticked
// in.
const chosen = computed<FreePort[]>(() =>
    (freePorts.value ?? []).filter((port) =>
        form.opposite_port_ids.includes(port.id),
    ),
);

// Which port goes with which, shown before anything is saved.
const pairs = computed<{ near: string; far: string }[]>(() =>
    chosen.value.length === props.ports.length
        ? props.ports.map((port, index) => ({
              near: port.name,
              far: chosen.value[index].name,
          }))
        : [],
);

// What a free port carries: its own mode and VLANs, or those of its
// aggregate.
const summaryOf = (port: FreePort): string => {
    if (port.aggregate !== null) {
        return port.summary === null
            ? trans('via :name: no mode', { name: port.aggregate })
            : trans('via :name: :summary', {
                  name: port.aggregate,
                  summary: port.summary,
              });
    }

    return port.summary ?? trans('No mode');
};

// A port has one link at most: a port ticked that is already linked keeps
// the series from being made, and the window says so at once.
const alreadyLinked = computed<string>(() => {
    const names = props.ports
        .filter((port) => port.link !== null)
        .map((port) => port.name);

    if (names.length === 0) {
        return '';
    }

    const shown = names.slice(0, 10).join(', ');

    return transChoice(
        'The port :names is already linked: deselect it first.|The ports :names are already linked: deselect them first.',
        names.length,
        {
            names:
                names.length > 10
                    ? trans(':names and :count more', {
                          names: shown,
                          count: names.length - 10,
                      })
                    : shown,
        },
    );
});

const ready = computed<boolean>(
    () =>
        alreadyLinked.value === '' &&
        opposite.value !== null &&
        form.opposite_port_ids.length === props.ports.length,
);

const submit = (): void => {
    form.post(store(props.device.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            emit('saved');
            emit('close');
        },
    });
};

const onOpenChange = (open: boolean): void => {
    if (!open) {
        emit('close');
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent class="sm:max-w-2xl" data-test="link-dialog">
            <form class="grid gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{
                        several
                            ? $t('Link ports as a series')
                            : $t('Link a port')
                    }}</DialogTitle>
                    <DialogDescription>{{
                        several
                            ? $tc(
                                  'The port selected on :name is linked to a free port of another device.|The :count ports selected on :name are linked, in their order, to as many free ports of another device: all of them, or none.',
                                  ports.length,
                                  { name: device.name },
                              )
                            : $t(
                                  'The port :port of :name is linked to a free port of another device, on any site.',
                                  {
                                      port: ports[0]?.name ?? '',
                                      name: device.name,
                                  },
                              )
                    }}</DialogDescription>
                </DialogHeader>

                <!-- Said before anything is chosen. -->
                <p
                    v-if="alreadyLinked"
                    role="alert"
                    class="rounded-md border border-destructive bg-error-surface px-3 py-2 text-sm text-error"
                    data-test="link-already-linked"
                >
                    {{ alreadyLinked }}
                </p>
                <InputError :message="form.errors.port_ids" />

                <!-- The opposite device: looked for, then chosen. -->
                <div v-if="opposite === null" class="grid gap-2">
                    <Label for="link-search">{{ $t('Opposite device') }}</Label>
                    <div class="flex items-center gap-2.5">
                        <Input
                            id="link-search"
                            v-model="search"
                            type="search"
                            name="search"
                            maxlength="100"
                            autocomplete="off"
                            spellcheck="false"
                            :placeholder="$t('Name of a device')"
                            data-test="link-search"
                        />
                        <Spinner v-if="searching" />
                    </div>

                    <!-- Always there, so that a screen reader is told when
                         the list changes. -->
                    <div role="status" data-test="linkable-devices">
                        <p
                            v-if="linkable === undefined"
                            class="text-muted-foreground"
                        >
                            {{ $t('Searching…') }}
                        </p>
                        <p
                            v-else-if="linkable.items.length === 0"
                            class="rounded-lg border border-dashed p-4 text-muted-foreground"
                        >
                            {{ $t('No other device matches.') }}
                        </p>
                        <template v-else>
                            <ul
                                class="panel flex max-h-64 flex-col divide-y overflow-y-auto"
                            >
                                <li
                                    v-for="other in linkable.items"
                                    :key="other.id"
                                    class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1.5 px-4 py-2.5"
                                    data-test="linkable-device"
                                    :data-device="other.name"
                                >
                                    <div class="flex min-w-0 flex-col gap-0.5">
                                        <span class="font-medium">{{
                                            other.name
                                        }}</span>
                                        <span
                                            class="text-note text-muted-foreground"
                                        >
                                            {{ other.site }} —
                                            {{ other.type_label }},
                                            {{
                                                $tc(
                                                    ':count free port|:count free ports',
                                                    other.free_ports,
                                                )
                                            }}
                                        </span>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        :disabled="other.free_ports === 0"
                                        :data-test="`choose-device-${other.name}`"
                                        @click="choose(other)"
                                    >
                                        {{ $t('Choose') }}
                                    </Button>
                                </li>
                            </ul>
                            <p
                                v-if="linkable.more"
                                class="mt-2 text-sm text-muted-foreground"
                                data-test="linkable-more"
                            >
                                {{
                                    $tc(
                                        'Only the first device is listed: type more to find the others.|Only the first :count devices are listed: type more to find the others.',
                                        linkable.items.length,
                                    )
                                }}
                            </p>
                        </template>
                    </div>
                    <InputError :message="form.errors.opposite_device_id" />
                </div>

                <template v-else>
                    <div class="grid gap-2">
                        <p class="text-sm font-medium">
                            {{ $t('Opposite device') }}
                        </p>
                        <div
                            class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-md border px-3.5 py-2.5"
                            data-test="chosen-device"
                        >
                            <span>
                                <span class="font-medium">{{
                                    opposite.name
                                }}</span>
                                <span class="text-muted-foreground">
                                    — {{ opposite.site }}</span
                                >
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                data-test="change-device"
                                @click="changeDevice"
                            >
                                {{ $t('Choose another device') }}
                            </Button>
                        </div>
                        <InputError :message="form.errors.opposite_device_id" />
                    </div>

                    <div
                        class="grid gap-2"
                        role="group"
                        aria-labelledby="link-ports-label"
                    >
                        <p id="link-ports-label" class="text-sm font-medium">
                            {{
                                several
                                    ? $t('Opposite ports')
                                    : $t('Opposite port')
                            }}
                            <span
                                v-if="several"
                                class="font-normal text-muted-foreground"
                                data-test="opposite-ports-count"
                                >{{ form.opposite_port_ids.length }} /
                                {{ ports.length }}</span
                            >
                        </p>
                        <div role="status">
                            <p
                                v-if="freePorts === null"
                                class="text-muted-foreground"
                            >
                                {{ $t('Searching…') }}
                            </p>
                            <p
                                v-else-if="freePorts.length === 0"
                                class="rounded-lg border border-dashed p-4 text-muted-foreground"
                            >
                                {{ $t('This device has no free port.') }}
                            </p>
                        </div>
                        <div
                            v-if="freePorts !== null && freePorts.length > 0"
                            class="grid max-h-64 gap-3 overflow-y-auto rounded-md border p-3"
                            data-test="opposite-ports"
                        >
                            <div
                                v-for="group in groups"
                                :key="group.member"
                                class="grid gap-1.5"
                            >
                                <p class="text-sm text-muted-foreground">
                                    {{
                                        $t('Member :position', {
                                            position: group.member,
                                        })
                                    }}
                                </p>
                                <label
                                    v-for="port in group.ports"
                                    :key="port.id"
                                    class="flex min-w-0 items-center gap-2.5"
                                    data-test="opposite-port"
                                    :data-port="port.name"
                                >
                                    <input
                                        :type="several ? 'checkbox' : 'radio'"
                                        name="opposite-port"
                                        class="size-4.5 shrink-0 accent-primary"
                                        :class="several ? 'rounded-xs' : ''"
                                        :checked="
                                            form.opposite_port_ids.includes(
                                                port.id,
                                            )
                                        "
                                        :disabled="
                                            several &&
                                            full &&
                                            !form.opposite_port_ids.includes(
                                                port.id,
                                            )
                                        "
                                        @change="togglePort(port)"
                                    />
                                    <span
                                        class="w-24 shrink-0 font-mono text-sm font-medium"
                                        >{{ port.name }}</span
                                    >
                                    <span
                                        class="min-w-0 text-sm text-muted-foreground"
                                        >{{ summaryOf(port) }}</span
                                    >
                                    <Badge
                                        v-if="!port.enabled"
                                        variant="outline"
                                        >{{ $t('Disabled (masculine)') }}</Badge
                                    >
                                </label>
                            </div>
                        </div>
                        <InputError :message="form.errors.opposite_port_ids" />

                        <!-- Which port goes with which, before anything is
                             saved. -->
                        <ul
                            v-if="several && pairs.length > 0"
                            class="grid max-h-32 gap-1 overflow-y-auto rounded-md border border-border-strong bg-sunken px-3 py-2 text-sm"
                            :aria-label="$t('Links that will be created')"
                            data-test="link-pairs"
                        >
                            <li
                                v-for="pair in pairs"
                                :key="pair.near"
                                class="font-mono"
                            >
                                {{ pair.near }} ↔ {{ opposite.name }} ›
                                {{ pair.far }}
                            </li>
                        </ul>
                    </div>
                </template>

                <div class="grid gap-x-4 gap-y-5 sm:grid-cols-2">
                    <div class="grid content-start gap-2">
                        <Label for="link-medium">{{ $t('Medium') }}</Label>
                        <NativeSelect
                            id="link-medium"
                            v-model="form.medium"
                            name="medium"
                            :aria-invalid="
                                form.errors.medium ? 'true' : undefined
                            "
                        >
                            <option value="">{{ $t('Not specified') }}</option>
                            <option
                                v-for="option in media"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </NativeSelect>
                        <InputError :message="form.errors.medium" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="link-status">{{ $t('Status') }}</Label>
                        <NativeSelect
                            id="link-status"
                            v-model="form.status"
                            name="status"
                            :aria-invalid="
                                form.errors.status ? 'true' : undefined
                            "
                        >
                            <option
                                v-for="option in statuses"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </NativeSelect>
                        <InputError :message="form.errors.status" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="link-description">{{
                        $t('Description')
                    }}</Label>
                    <Textarea
                        id="link-description"
                        v-model="form.description"
                        name="description"
                        maxlength="1000"
                        rows="2"
                        :aria-invalid="
                            form.errors.description ? 'true' : undefined
                        "
                    />
                    <InputError :message="form.errors.description" />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('close')"
                    >
                        {{ $t('Cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :disabled="!ready || form.processing"
                        data-test="save-link"
                    >
                        <Spinner v-if="form.processing" />
                        {{ $t('Link (verb)') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
