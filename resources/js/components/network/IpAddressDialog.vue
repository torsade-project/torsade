<!-- torsade/resources/js/components/network/IpAddressDialog.vue -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import JackPicker from '@/components/network/JackPicker.vue';
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
import { trans } from '@/lib/i18n';
import { nextFreeAddress } from '@/lib/ipv4';
import { store, update } from '@/routes/ip-addresses';
import type {
    DeviceOption,
    FreeInterval,
    IpAddress,
    JackChoices,
    Option,
    SubnetChoice,
} from '@/types';

// What the window says of the subnet an address lies in.
type SubnetBounds = {
    id: number;
    cidr: string;
    first_usable: string;
    last_usable: string;
};

// One address, added or modified in a window. Only the address is required.
//
// The window is opened from the page of a subnet, where the subnet is known
// and the device is chosen, or from the page of a device, where the device
// is known and the subnet is chosen.
const props = withDefaults(
    defineProps<{
        open: boolean;
        // The subnet of the address, on the page of a subnet, and the
        // site of that subnet, if it has one.
        subnet?: SubnetBounds | null;
        siteId?: number | null;
        // The address to modify, or null for a new one.
        address: IpAddress | null;
        // What the field of a new address starts with, if anything.
        suggestedAddress?: string | null;
        freeIntervals?: FreeInterval[];
        deviceTypes: Option[];
        assignments: Option[];
        statuses: Option[];
        // On the page of a subnet: the devices the address can be attached
        // to, those of the site of the subnet offered first.
        devices?: DeviceOption[];
        // On the page of a device: that device, and the subnets the new
        // address can be created in, those of its site first.
        device?: { id: number; name: string; site_id: number } | null;
        subnets?: SubnetChoice[];
        // The jacks the field "Jack" offers for what is typed in it, asked
        // for when it is used.
        jackChoices?: JackChoices;
    }>(),
    {
        subnet: null,
        siteId: null,
        suggestedAddress: null,
        freeIntervals: () => [],
        devices: () => [],
        device: null,
        subnets: () => [],
    },
);

const emit = defineEmits<{
    close: [];
}>();

type AddressForm = {
    address: string;
    hostname: string;
    mac_address: string;
    device_type: string;
    assignment: string;
    device_id: number | null;
    jack_id: number | null;
    status: string;
    description: string;
};

const form = useForm<AddressForm>({
    address: '',
    hostname: '',
    mac_address: '',
    device_type: '',
    assignment: 'static',
    device_id: null,
    jack_id: null,
    status: 'active',
    description: '',
});

// --- The subnet of the address ---------------------------------------------

// From the page of a device, the subnet is chosen in the window. It is not
// a field of the address: it says where the address is created.
const chosenSubnetId = ref<number | null>(null);
const subnetError = ref<string>('');

const currentSubnet = computed<SubnetBounds | null>(() =>
    props.device === null
        ? props.subnet
        : (props.subnets.find((one) => one.id === chosenSubnetId.value) ??
          null),
);

const subnetGroups = computed<{ label: string; subnets: SubnetChoice[] }[]>(
    () => {
        const own = props.subnets.filter(
            (one) => one.site_id === props.device?.site_id,
        );
        const others = props.subnets.filter(
            (one) => one.site_id !== props.device?.site_id,
        );

        return [
            { label: trans('Site of the device'), subnets: own },
            { label: trans('Other subnets'), subnets: others },
        ].filter((group) => group.subnets.length > 0);
    },
);

const subnetText = (one: SubnetChoice): string =>
    [
        one.cidr,
        one.label,
        one.site_id === props.device?.site_id ? null : one.site,
    ]
        .filter((part) => part !== null && part !== '')
        .join(' — ');

// --- The device of the address ---------------------------------------------

// The devices of the site of the subnet first: an interface is most often
// on a network of the site of its device.
const deviceGroups = computed<{ label: string; devices: DeviceOption[] }[]>(
    () => {
        const own = props.devices.filter((one) => one.site_id === props.siteId);
        const others = props.devices.filter(
            (one) => one.site_id !== props.siteId,
        );

        return [
            { label: trans('Site of the subnet'), devices: own },
            { label: trans('Other sites'), devices: others },
        ].filter((group) => group.devices.length > 0);
    },
);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        subnetError.value = '';
        chosenSubnetId.value = null;
        form.device_id = props.device?.id ?? props.address?.device?.id ?? null;
        form.jack_id = props.address?.jack?.id ?? null;
        form.address = props.address?.address ?? props.suggestedAddress ?? '';
        form.hostname = props.address?.hostname ?? '';
        form.mac_address = props.address?.mac_address ?? '';
        form.device_type = props.address?.device_type ?? '';
        form.assignment = props.address?.assignment ?? 'static';
        form.status = props.address?.status ?? 'active';
        form.description = props.address?.description ?? '';
    },
);

// On the page of a device, the jacks offered are those of the site of the
// subnet chosen in the window, or of every site for a subnet without one.
// On the page of a subnet, the page knows its site.
const jackQuery = computed<Record<string, number>>(() => {
    if (props.device === null) {
        return {};
    }

    const site = props.subnets.find(
        (one) => one.id === chosenSubnetId.value,
    )?.site_id;
    const query: Record<string, number> = {};

    if (site !== null && site !== undefined) {
        query.jack_site = site;
    }

    return query;
});

const freeAddress = computed<string | null>(() =>
    nextFreeAddress(props.freeIntervals),
);

const useNextFreeAddress = (): void => {
    if (freeAddress.value) {
        form.address = freeAddress.value;
    }
};

const submit = (): void => {
    const options = {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    };

    // Created from the page of a device, the address comes back to it.
    form.transform((data) => ({
        ...data,
        from_device: props.device !== null,
    }));

    if (props.address) {
        form.put(update(props.address.id).url, options);

        return;
    }

    const subnet = currentSubnet.value;

    if (subnet === null) {
        subnetError.value = trans('Choose the subnet of the address.');

        return;
    }

    form.post(store(subnet.id).url, options);
};

const onOpenChange = (open: boolean): void => {
    if (!open) {
        emit('close');
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent class="sm:max-w-xl" data-test="ip-address-dialog">
            <form class="grid gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{
                        address
                            ? $t('Edit the IP address')
                            : $t('New IP address')
                    }}</DialogTitle>
                    <DialogDescription>
                        <template v-if="currentSubnet">
                            {{
                                $t(
                                    'Usable addresses of :cidr: :first – :last.',
                                    {
                                        cidr: currentSubnet.cidr,
                                        first: currentSubnet.first_usable,
                                        last: currentSubnet.last_usable,
                                    },
                                )
                            }}
                        </template>
                        <template v-else-if="device">
                            {{
                                $t(
                                    'An address of :device: choose the subnet it belongs to.',
                                    { device: device.name },
                                )
                            }}
                        </template>
                    </DialogDescription>
                </DialogHeader>

                <div v-if="device" class="grid gap-2">
                    <Label for="ip-subnet">{{ $t('Subnet') }}</Label>
                    <NativeSelect
                        id="ip-subnet"
                        v-model="chosenSubnetId"
                        name="subnet"
                        required
                        class="font-mono"
                        :aria-invalid="subnetError ? 'true' : undefined"
                        @change="subnetError = ''"
                    >
                        <option :value="null" disabled>
                            {{ $t('Choose a subnet') }}
                        </option>
                        <optgroup
                            v-for="group in subnetGroups"
                            :key="group.label"
                            :label="group.label"
                        >
                            <option
                                v-for="one in group.subnets"
                                :key="one.id"
                                :value="one.id"
                            >
                                {{ subnetText(one) }}
                            </option>
                        </optgroup>
                    </NativeSelect>
                    <InputError :message="subnetError" />
                </div>

                <div class="grid gap-2">
                    <Label for="ip-address">{{ $t('Address') }}</Label>
                    <div class="flex flex-wrap gap-2">
                        <Input
                            id="ip-address"
                            v-model="form.address"
                            name="address"
                            required
                            autocomplete="off"
                            class="min-w-40 flex-1 font-mono"
                            :aria-invalid="
                                form.errors.address ? 'true' : undefined
                            "
                        />
                        <Button
                            v-if="address === null && device === null"
                            type="button"
                            variant="outline"
                            :disabled="freeAddress === null"
                            data-test="next-free-address"
                            @click="useNextFreeAddress"
                        >
                            {{ $t('Next free address') }}
                        </Button>
                    </div>
                    <InputError :message="form.errors.address" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid content-start gap-2">
                        <Label for="ip-hostname">{{ $t('Host name') }}</Label>
                        <Input
                            id="ip-hostname"
                            v-model="form.hostname"
                            name="hostname"
                            maxlength="253"
                            autocomplete="off"
                            :aria-invalid="
                                form.errors.hostname ? 'true' : undefined
                            "
                        />
                        <InputError :message="form.errors.hostname" />
                    </div>

                    <div class="grid content-start gap-2">
                        <Label for="ip-mac">{{ $t('MAC address') }}</Label>
                        <Input
                            id="ip-mac"
                            v-model="form.mac_address"
                            name="mac_address"
                            autocomplete="off"
                            class="font-mono"
                            :aria-invalid="
                                form.errors.mac_address ? 'true' : undefined
                            "
                        />
                        <InputError :message="form.errors.mac_address" />
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="grid content-start gap-2">
                        <Label for="ip-type">{{ $t('Type') }}</Label>
                        <NativeSelect
                            id="ip-type"
                            v-model="form.device_type"
                            name="device_type"
                            :aria-invalid="
                                form.errors.device_type ? 'true' : undefined
                            "
                        >
                            <option value="">{{ $t('Not specified') }}</option>
                            <option
                                v-for="type in deviceTypes"
                                :key="type.value"
                                :value="type.value"
                            >
                                {{ type.label }}
                            </option>
                        </NativeSelect>
                        <InputError :message="form.errors.device_type" />
                    </div>

                    <div class="grid content-start gap-2">
                        <Label for="ip-assignment">{{
                            $t('Assignment')
                        }}</Label>
                        <NativeSelect
                            id="ip-assignment"
                            v-model="form.assignment"
                            name="assignment"
                            :aria-invalid="
                                form.errors.assignment ? 'true' : undefined
                            "
                        >
                            <option
                                v-for="assignment in assignments"
                                :key="assignment.value"
                                :value="assignment.value"
                            >
                                {{ assignment.label }}
                            </option>
                        </NativeSelect>
                        <InputError :message="form.errors.assignment" />
                    </div>

                    <div class="grid content-start gap-2">
                        <Label for="ip-status">{{ $t('Status') }}</Label>
                        <NativeSelect
                            id="ip-status"
                            v-model="form.status"
                            name="status"
                            :aria-invalid="
                                form.errors.status ? 'true' : undefined
                            "
                        >
                            <option
                                v-for="status in statuses"
                                :key="status.value"
                                :value="status.value"
                            >
                                {{ status.label }}
                            </option>
                        </NativeSelect>
                        <InputError :message="form.errors.status" />
                    </div>
                </div>

                <!-- On the page of a device, the device is known: it
                     is not asked again. -->
                <div v-if="device === null" class="grid gap-2">
                    <Label for="ip-device">{{ $t('Device') }}</Label>
                    <NativeSelect
                        id="ip-device"
                        v-model="form.device_id"
                        name="device_id"
                        aria-describedby="ip-device-help"
                        :aria-invalid="
                            form.errors.device_id ? 'true' : undefined
                        "
                    >
                        <option :value="null">{{ $t('No device') }}</option>
                        <optgroup
                            v-for="group in deviceGroups"
                            :key="group.label"
                            :label="group.label"
                        >
                            <option
                                v-for="one in group.devices"
                                :key="one.id"
                                :value="one.id"
                            >
                                {{ one.name }} — {{ one.site }}
                            </option>
                        </optgroup>
                    </NativeSelect>
                    <InputError :message="form.errors.device_id" />
                    <p
                        id="ip-device-help"
                        class="text-sm text-muted-foreground"
                    >
                        {{
                            $t(
                                'Optional. The address is then an interface of the device; it keeps its host name and its type.',
                            )
                        }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="ip-jack">{{ $t('Jack') }}</Label>
                    <JackPicker
                        id="ip-jack"
                        v-model="form.jack_id"
                        :open="open"
                        :initial="address?.jack ?? null"
                        :choices="jackChoices"
                        :query="jackQuery"
                        :invalid="form.errors.jack_id !== undefined"
                    />
                    <InputError :message="form.errors.jack_id" />
                    <p class="text-sm text-muted-foreground">
                        {{
                            $t(
                                'Optional. The wall jack the machine is plugged into, among those of the site of the subnet.',
                            )
                        }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="ip-description">{{ $t('Description') }}</Label>
                    <Input
                        id="ip-description"
                        v-model="form.description"
                        name="description"
                        maxlength="1000"
                        autocomplete="off"
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
                        :disabled="form.processing"
                        data-test="save-ip-address"
                    >
                        <Spinner v-if="form.processing" />
                        {{ $t('Save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
