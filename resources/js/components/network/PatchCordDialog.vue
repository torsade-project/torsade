<!-- torsade/resources/js/components/network/PatchCordDialog.vue -->
<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
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
import { store } from '@/routes/patch-cords';
import type { DevicePortChoice, PanelPort, PatchDevice } from '@/types';

// Ports of a panel, one or several that follow one another, patched to as
// many ports of a device of the site, in the order of the device from a
// first port: "ports 1 to 24 of the panel on 1/0/1 to 1/0/24". A port of
// the device already patched is not offered, nor one plugged into a jack:
// a port has one connector. A port linked to another device is offered
// like the others, towards a jack as towards a riser panel: the link then
// runs through the jack.
const props = defineProps<{
    open: boolean;
    panel: { id: number; name: string };
    // The ports of the panel to patch, by number.
    ports: PanelPort[];
    devices: PatchDevice[];
    // The ports of the device chosen, asked for when it is chosen.
    devicePorts?: DevicePortChoice[];
}>();

const emit = defineEmits<{
    // Told before the window closes, once the cords are saved.
    saved: [];
    close: [];
}>();

// How many ports a refusal names at most, as the server does.
const NAMED = 10;

const form = useForm<{
    panel_port_ids: number[];
    device_id: number | null;
    port_id: number | null;
    color: string;
    description: string;
}>({
    panel_port_ids: [],
    device_id: null,
    port_id: null,
    color: '',
    description: '',
});

const loading = ref<boolean>(false);
// The device whose ports came: those of a device chosen before are not
// shown in their place while the list is on its way.
const portsOf = ref<number | null>(null);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.panel_port_ids = props.ports.map((port) => port.id);
        form.device_id = null;
        form.port_id = null;
        form.description = '';
        // The colour typed before is kept: the cords of a panel are often
        // of one colour.
        portsOf.value = null;
    },
);

const chooseDevice = (id: number | null): void => {
    form.device_id = id;
    form.port_id = null;
    form.clearErrors('device_id', 'port_id');

    if (id === null) {
        return;
    }

    // Only the ports are asked again, and the address of the page stays
    // what it is.
    router.reload({
        only: ['devicePorts'],
        data: { device: id },
        preserveUrl: true,
        onStart: () => {
            loading.value = true;
        },
        onSuccess: () => {
            portsOf.value = id;
        },
        onFinish: () => {
            loading.value = false;
        },
    });
};

const devicePorts = computed<DevicePortChoice[]>(() =>
    portsOf.value !== null && portsOf.value === form.device_id
        ? (props.devicePorts ?? [])
        : [],
);

const device = computed<PatchDevice | null>(
    () => props.devices.find((one) => one.id === form.device_id) ?? null,
);

// A port of the device is offered when it has no connector.
const offered = (port: DevicePortChoice): boolean =>
    port.cord === null && port.plugged === null;

// The first port of the device: the others follow it.
const firstChoices = computed<DevicePortChoice[]>(() =>
    props.ports.length === 0 ? [] : devicePorts.value.filter(offered),
);

type Pair = {
    panelPort: PanelPort;
    port: DevicePortChoice | null;
    problem: 'missing' | 'patched' | 'plugged' | null;
};

// Which port of the device each port of the panel goes to.
const pairs = computed<Pair[]>(() => {
    const start = devicePorts.value.findIndex(
        (port) => port.id === form.port_id,
    );

    if (start === -1) {
        return [];
    }

    return props.ports.map((panelPort, index) => {
        const port = devicePorts.value[start + index] ?? null;

        if (port === null) {
            return { panelPort, port, problem: 'missing' };
        }

        if (port.cord !== null) {
            return { panelPort, port, problem: 'patched' };
        }

        if (port.plugged !== null) {
            return { panelPort, port, problem: 'plugged' };
        }

        return { panelPort, port, problem: null };
    });
});

const named = (items: string[]): string => {
    const shown = items.slice(0, NAMED).join(', ');

    return items.length > NAMED
        ? transChoice(':items and :count more', items.length - NAMED, {
              items: shown,
          })
        : shown;
};

// What is wrong with the series, told before anything is sent: the words
// the server uses when it refuses the same series.
const hint = computed<string | null>(() => {
    const chosen = device.value;
    const first = pairs.value[0]?.port;

    if (chosen === null || first === null || first === undefined) {
        return null;
    }

    if (pairs.value.some((pair) => pair.problem === 'missing')) {
        return transChoice(
            'From :port, :device has not enough ports for :count cord.|From :port, :device has not enough ports for :count cords.',
            props.ports.length,
            { port: first.name, device: chosen.name },
        );
    }

    const patched = pairs.value
        .filter((pair) => pair.problem === 'patched')
        .map((pair) =>
            trans(':port is patched to :target', {
                port: `${chosen.name} ${pair.port?.name ?? ''}`,
                target: pair.port?.cord ?? '',
            }),
        );

    if (patched.length > 0) {
        return transChoice(
            ':cords: unpatch it first.|:cords: unpatch them first.',
            patched.length,
            { cords: named(patched) },
        );
    }

    const plugged = pairs.value
        .filter((pair) => pair.problem === 'plugged')
        .map((pair) =>
            trans(':port is plugged into the jack :jack', {
                port: `${chosen.name} ${pair.port?.name ?? ''}`,
                jack: pair.port?.plugged ?? '',
            }),
        );

    if (plugged.length > 0) {
        return transChoice(
            ':cords: a port has one connector, unplug it first.|:cords: a port has one connector, unplug them first.',
            plugged.length,
            { cords: named(plugged) },
        );
    }

    return null;
});

const submit = (): void => {
    form.post(store(props.panel.id).url, {
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
        <DialogContent class="sm:max-w-xl" data-test="patch-cord-dialog">
            <form class="grid gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{
                        ports.length === 1
                            ? $t('Patch the port :port', {
                                  port: `${panel.name} ${ports[0].number}`,
                              })
                            : $tc(
                                  'Patch :count port as a series|Patch :count ports as a series',
                                  ports.length,
                              )
                    }}</DialogTitle>
                    <DialogDescription>{{
                        ports.length === 1
                            ? $t(
                                  'A cord from this port of the panel to a port of a device of the site.',
                              )
                            : $t(
                                  'Cords from the ports :first to :last of :panel to as many ports of a device of the site, in the order of the device from a first port.',
                                  {
                                      first: ports[0].number,
                                      last: ports[ports.length - 1].number,
                                      panel: panel.name,
                                  },
                              )
                    }}</DialogDescription>
                </DialogHeader>

                <div class="grid gap-x-4 gap-y-5 sm:grid-cols-[1fr_11rem]">
                    <div class="grid content-start gap-2">
                        <Label for="cord-device">{{ $t('Device') }}</Label>
                        <NativeSelect
                            id="cord-device"
                            :model-value="form.device_id"
                            name="device_id"
                            required
                            data-test="cord-device"
                            :aria-invalid="
                                form.errors.device_id ? 'true' : undefined
                            "
                            @update:model-value="
                                (value) =>
                                    chooseDevice(
                                        typeof value === 'number'
                                            ? value
                                            : null,
                                    )
                            "
                        >
                            <option :value="null" disabled>
                                {{ $t('Choose a device') }}
                            </option>
                            <option
                                v-for="choice in devices"
                                :key="choice.id"
                                :value="choice.id"
                            >
                                {{ choice.name
                                }}<template v-if="choice.place">
                                    — {{ choice.place }}</template
                                >
                            </option>
                        </NativeSelect>
                        <InputError :message="form.errors.device_id" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="cord-port">{{
                            ports.length === 1 ? $t('Port') : $t('First port')
                        }}</Label>
                        <NativeSelect
                            id="cord-port"
                            v-model="form.port_id"
                            name="port_id"
                            required
                            class="font-mono"
                            data-test="cord-port"
                            :disabled="form.device_id === null || loading"
                            :aria-invalid="
                                hint || form.errors.port_id ? 'true' : undefined
                            "
                        >
                            <option :value="null" disabled>—</option>
                            <option
                                v-for="port in firstChoices"
                                :key="port.id"
                                :value="port.id"
                            >
                                {{ port.name
                                }}<template v-if="port.link">
                                    ↔ {{ port.link }}</template
                                >
                            </option>
                        </NativeSelect>
                    </div>
                </div>

                <div class="-mt-3 grid gap-1.5">
                    <p
                        v-if="hint"
                        class="text-sm text-error"
                        data-test="cord-hint"
                    >
                        {{ hint }}
                    </p>
                    <InputError v-else :message="form.errors.port_id" />
                    <InputError :message="form.errors.panel_port_ids" />
                    <p
                        v-if="loading"
                        class="text-sm text-muted-foreground"
                        role="status"
                    >
                        {{ $t('Loading the ports…') }}
                    </p>
                    <p
                        v-else-if="
                            form.device_id !== null &&
                            portsOf === form.device_id &&
                            firstChoices.length === 0
                        "
                        class="text-sm text-muted-foreground"
                        data-test="no-free-port"
                    >
                        {{ $t('This device has no port to offer here.') }}
                    </p>
                    <p v-else class="text-sm text-muted-foreground">
                        {{
                            $t(
                                'Only the ports without a cord, of a panel or of a jack, are offered.',
                            )
                        }}
                    </p>
                </div>

                <!-- Always there, so that a screen reader is told when the
                     pairs change. -->
                <div
                    v-if="ports.length > 1"
                    class="grid gap-1.5 rounded-md bg-sunken px-3 py-2 text-sm"
                    role="status"
                    data-test="cord-preview"
                >
                    <span class="text-muted-foreground">{{
                        $t('Cords laid')
                    }}</span>
                    <ul
                        v-if="pairs.length > 0"
                        class="grid max-h-48 grid-cols-[repeat(auto-fill,minmax(11rem,1fr))] gap-x-4 gap-y-0.5 overflow-y-auto font-mono"
                    >
                        <li
                            v-for="pair in pairs"
                            :key="pair.panelPort.id"
                            :class="pair.problem ? 'text-error' : ''"
                            data-test="cord-preview-line"
                        >
                            {{ panel.name }} {{ pair.panelPort.number }} →
                            {{ pair.port?.name ?? '—' }}
                        </li>
                    </ul>
                    <span v-else class="text-muted-foreground">{{
                        $t('No cord yet.')
                    }}</span>
                </div>

                <div class="grid gap-x-4 gap-y-5 sm:grid-cols-[11rem_1fr]">
                    <div class="grid content-start gap-2">
                        <Label for="cord-color">{{ $t('Colour') }}</Label>
                        <Input
                            id="cord-color"
                            v-model="form.color"
                            name="color"
                            maxlength="32"
                            autocomplete="off"
                            :aria-invalid="
                                form.errors.color ? 'true' : undefined
                            "
                        />
                        <InputError :message="form.errors.color" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="cord-description">{{
                            $t('Description')
                        }}</Label>
                        <Textarea
                            id="cord-description"
                            v-model="form.description"
                            name="description"
                            maxlength="255"
                            rows="1"
                            :aria-invalid="
                                form.errors.description ? 'true' : undefined
                            "
                        />
                        <InputError :message="form.errors.description" />
                    </div>
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
                        :disabled="
                            form.processing ||
                            form.port_id === null ||
                            hint !== null
                        "
                        data-test="save-patch-cord"
                    >
                        <Spinner v-if="form.processing" />
                        {{ $t('Save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
