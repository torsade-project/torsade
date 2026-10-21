<!-- torsade/resources/js/components/network/JackDevicePlugDialog.vue -->
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
import { trans } from '@/lib/i18n';
import { store } from '@/routes/jack-cords';
import type { PlugDevice, PlugPorts } from '@/types';

// A device of the site of a jack plugged into it, on the side of the desk:
// a device that is not retired is chosen, then one of its ports that has no
// connector yet, neither a patch cord nor a jack cord.
const props = defineProps<{
    open: boolean;
    jack: { id: number; label: string; site: { id: number } } | null;
    // Asked for when the window opens, then when a device is chosen.
    devices?: PlugDevice[];
    ports?: PlugPorts | null;
}>();

const emit = defineEmits<{
    close: [];
}>();

const form = useForm<{
    jack_id: number | null;
    device_id: number | null;
    port_id: number | null;
    color: string;
    description: string;
}>({
    jack_id: null,
    device_id: null,
    port_id: null,
    color: '',
    description: '',
});

const loadingDevices = ref<boolean>(false);
const loadingPorts = ref<boolean>(false);

watch(
    () => props.open,
    (open) => {
        if (!open || !props.jack) {
            return;
        }

        form.clearErrors();
        form.jack_id = props.jack.id;
        form.device_id = null;
        form.port_id = null;
        form.color = '';
        form.description = '';

        // Only the devices are asked again, and the address of the page,
        // which keeps the filters of the list, stays what it is. The list
        // waits from the start: that none can be plugged is not shown
        // meanwhile.
        loadingDevices.value = true;
        router.reload({
            only: ['plugDevices'],
            data: { plug_site: props.jack.site.id },
            preserveUrl: true,
            onFinish: () => {
                loadingDevices.value = false;
            },
        });
    },
);

const chooseDevice = (id: number | null): void => {
    form.device_id = id;
    form.port_id = null;
    form.clearErrors('port_id');

    if (id === null || !props.jack) {
        return;
    }

    router.reload({
        only: ['plugPorts'],
        data: { plug_site: props.jack.site.id, plug_device: id },
        preserveUrl: true,
        onStart: () => {
            loadingPorts.value = true;
        },
        onFinish: () => {
            loadingPorts.value = false;
        },
    });
};

// The free ports of the device chosen: those of a device chosen before are
// not shown in their place while the list is on its way.
const ports = computed<PlugPorts['ports']>(() =>
    !loadingPorts.value &&
    form.device_id !== null &&
    props.ports?.device.id === form.device_id
        ? props.ports.ports
        : [],
);

const devices = computed<PlugDevice[]>(() =>
    loadingDevices.value ? [] : (props.devices ?? []),
);

// What a port carries, or that it has no mode.
const configurationOf = (port: PlugPorts['ports'][number]): string =>
    port.configuration ?? trans('No mode');

const submit = (): void => {
    form.transform((data) => ({
        jack_id: data.jack_id,
        port_id: data.port_id,
        color: data.color,
        description: data.description,
    })).post(store().url, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
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
        <DialogContent data-test="jack-device-plug-dialog">
            <form class="grid gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ $t('Plug a device') }}</DialogTitle>
                    <DialogDescription v-if="jack">{{
                        $t(
                            'A port of a device of the site plugged into the jack :jack, on the side of the desk. Only the ports without a cord, of a panel or of a jack, are offered.',
                            { jack: jack.label },
                        )
                    }}</DialogDescription>
                </DialogHeader>

                <InputError :message="form.errors.jack_id" />

                <div class="grid gap-x-4 gap-y-5 sm:grid-cols-[1fr_12rem]">
                    <div class="grid content-start gap-2">
                        <Label for="plug-device">{{ $t('Device') }}</Label>
                        <NativeSelect
                            id="plug-device"
                            :model-value="form.device_id"
                            name="device_id"
                            required
                            data-test="plug-device"
                            :disabled="loadingDevices"
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
                                :disabled="choice.free_ports === 0"
                            >
                                {{ choice.name
                                }}<template v-if="choice.place">
                                    — {{ choice.place }}</template
                                >
                                ({{
                                    $tc(
                                        ':count free port|:count free ports',
                                        choice.free_ports,
                                    )
                                }})
                            </option>
                        </NativeSelect>
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="plug-port">{{ $t('Port') }}</Label>
                        <NativeSelect
                            id="plug-port"
                            v-model="form.port_id"
                            name="port_id"
                            required
                            class="font-mono"
                            data-test="plug-port"
                            :disabled="form.device_id === null || loadingPorts"
                            :aria-invalid="
                                form.errors.port_id ? 'true' : undefined
                            "
                        >
                            <option :value="null" disabled>—</option>
                            <option
                                v-for="port in ports"
                                :key="port.id"
                                :value="port.id"
                            >
                                {{ port.name }} · {{ configurationOf(port) }}
                            </option>
                        </NativeSelect>
                    </div>
                </div>

                <div class="-mt-3 grid gap-1.5">
                    <InputError :message="form.errors.port_id" />
                    <p
                        v-if="loadingDevices || loadingPorts"
                        class="text-sm text-muted-foreground"
                        role="status"
                    >
                        {{ $t('Loading…') }}
                    </p>
                    <p
                        v-else-if="devices.length === 0"
                        class="text-sm text-muted-foreground"
                        data-test="no-plug-device"
                    >
                        {{ $t('No device of this site can be plugged in.') }}
                    </p>
                </div>

                <div class="grid gap-x-4 gap-y-5 sm:grid-cols-[11rem_1fr]">
                    <div class="grid content-start gap-2">
                        <Label for="plug-device-color">{{
                            $t('Colour')
                        }}</Label>
                        <Input
                            id="plug-device-color"
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
                        <Label for="plug-device-description">{{
                            $t('Description')
                        }}</Label>
                        <Textarea
                            id="plug-device-description"
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
                        :disabled="form.processing || form.port_id === null"
                        data-test="save-jack-device-plug"
                    >
                        <Spinner v-if="form.processing" />
                        {{ $t('Plug') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
