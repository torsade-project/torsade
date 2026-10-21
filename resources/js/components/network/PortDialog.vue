<!-- torsade/resources/js/components/network/PortDialog.vue -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import PortModeFields from '@/components/network/PortModeFields.vue';
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
import { update } from '@/routes/ports';
import type {
    DeviceMember,
    Port,
    PortModeValue,
    PortOptions,
    VlanGroup,
} from '@/types';

// A short form in a window: every field of one port, its mode and the
// VLANs it carries, modified without leaving the page of its device.
const props = defineProps<{
    open: boolean;
    device: { id: number; name: string };
    port: Port | null;
    members: DeviceMember[];
    options: PortOptions;
    // The VLANs a port can carry: those of the site of the device first,
    // then those of the other sites.
    vlans: VlanGroup[];
}>();

const emit = defineEmits<{
    close: [];
}>();

const form = useForm<{
    mode: PortModeValue | null;
    untagged_vlan_id: number | null;
    tagged_vlan_ids: number[];
    name: string;
    // A number once typed: the field is a numeric input.
    position: string | number;
    connector: string;
    max_speed: string;
    poe_type: string;
    poe_label: string;
    enabled: boolean;
    description: string;
}>({
    mode: null,
    untagged_vlan_id: null,
    tagged_vlan_ids: [],
    name: '',
    position: '1',
    connector: 'rj45',
    max_speed: '1g',
    poe_type: 'none',
    poe_label: '',
    enabled: true,
    description: '',
});

// The fields of the mode are drawn anew each time the window opens.
const opened = ref<number>(0);

watch(
    () => props.open,
    (open) => {
        const port = props.port;

        if (!open || !port) {
            return;
        }

        form.clearErrors();
        form.mode = port.mode;
        form.untagged_vlan_id = port.untagged_vlan_id;
        form.tagged_vlan_ids = [...port.tagged_vlan_ids];
        opened.value++;
        form.name = port.name;
        form.position = String(port.position);
        form.connector = port.connector;
        form.max_speed = port.max_speed;
        form.poe_type = port.poe_type;
        form.poe_label = port.poe_label ?? '';
        form.enabled = port.enabled;
        form.description = port.description ?? '';
    },
);

// The number of the member the port belongs to.
const memberPosition = computed<number | null>(
    () =>
        props.members.find((member) => member.id === props.port?.member_id)
            ?.position ?? null,
);

const submit = (): void => {
    if (!props.port) {
        return;
    }

    form.transform((data) => ({
        ...data,
        poe_label: data.poe_label.trim() === '' ? null : data.poe_label,
    })).put(update(props.port.id).url, {
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
        <DialogContent class="sm:max-w-xl" data-test="port-dialog">
            <form v-if="port" class="grid gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{
                        $t('Port :name', { name: port.name })
                    }}</DialogTitle>
                    <DialogDescription>{{
                        $t('A port of the member :position of :name.', {
                            position: memberPosition ?? '',
                            name: device.name,
                        })
                    }}</DialogDescription>
                </DialogHeader>

                <div class="grid gap-x-4 gap-y-5 sm:grid-cols-[1fr_7rem]">
                    <div class="grid content-start gap-2">
                        <Label for="port-name">{{ $t('Name') }}</Label>
                        <Input
                            id="port-name"
                            v-model="form.name"
                            name="name"
                            required
                            :maxlength="options.limits.name"
                            autocomplete="off"
                            spellcheck="false"
                            class="font-mono"
                            aria-describedby="port-name-help"
                            :aria-invalid="
                                form.errors.name ? 'true' : undefined
                            "
                        />
                        <InputError :message="form.errors.name" />
                        <p
                            id="port-name-help"
                            class="text-sm text-muted-foreground"
                        >
                            {{
                                $t(
                                    'As the device writes it, unique within the device.',
                                )
                            }}
                        </p>
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="port-position">{{ $t('Order') }}</Label>
                        <Input
                            id="port-position"
                            v-model="form.position"
                            type="number"
                            name="position"
                            required
                            min="1"
                            :max="options.limits.position"
                            step="1"
                            class="font-mono"
                            :aria-invalid="
                                form.errors.position ? 'true' : undefined
                            "
                        />
                        <InputError :message="form.errors.position" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <label class="flex items-center gap-2.5">
                        <input
                            v-model="form.enabled"
                            type="checkbox"
                            name="enabled"
                            class="size-4.5 shrink-0 rounded-xs accent-primary"
                            aria-describedby="port-enabled-help"
                        />
                        <span>{{ $t('Port enabled') }}</span>
                    </label>
                    <InputError :message="form.errors.enabled" />
                    <p
                        id="port-enabled-help"
                        class="text-sm text-muted-foreground"
                    >
                        {{
                            $t(
                                'Unticked for a port that is shut down: say why in its description.',
                            )
                        }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="port-description">{{
                        $t('Description')
                    }}</Label>
                    <Input
                        id="port-description"
                        v-model="form.description"
                        name="description"
                        maxlength="255"
                        autocomplete="off"
                        aria-describedby="port-description-help"
                        :aria-invalid="
                            form.errors.description ? 'true' : undefined
                        "
                    />
                    <InputError :message="form.errors.description" />
                    <p
                        id="port-description-help"
                        class="text-sm text-muted-foreground"
                    >
                        {{ $t('What is plugged into the port.') }}
                    </p>
                </div>

                <!-- The mode and the VLANs of a port of an aggregate are
                     those of the aggregate. -->
                <div
                    v-if="port.aggregate"
                    class="grid gap-2 rounded-lg border border-dashed p-3 text-sm text-muted-foreground"
                    data-test="port-aggregate-note"
                >
                    <p>
                        {{
                            $t(
                                'This port is part of the aggregate :name: its mode and its VLANs are those of the aggregate.',
                                { name: port.aggregate.name },
                            )
                        }}
                    </p>
                    <InputError :message="form.errors.mode" />
                </div>
                <PortModeFields
                    v-else
                    :key="opened"
                    v-model:mode="form.mode"
                    v-model:untagged="form.untagged_vlan_id"
                    v-model:tagged="form.tagged_vlan_ids"
                    id-prefix="port"
                    :vlans="vlans"
                    :errors="form.errors"
                />

                <!-- What the port is made of: seldom changed. -->
                <div class="grid gap-x-4 gap-y-5 sm:grid-cols-3">
                    <div class="grid content-start gap-2">
                        <Label for="port-connector">{{
                            $t('Connector')
                        }}</Label>
                        <NativeSelect
                            id="port-connector"
                            v-model="form.connector"
                            name="connector"
                            :aria-invalid="
                                form.errors.connector ? 'true' : undefined
                            "
                        >
                            <option
                                v-for="option in options.connectors"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </NativeSelect>
                        <InputError :message="form.errors.connector" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="port-speed">{{ $t('Speed') }}</Label>
                        <NativeSelect
                            id="port-speed"
                            v-model="form.max_speed"
                            name="max_speed"
                            :aria-invalid="
                                form.errors.max_speed ? 'true' : undefined
                            "
                        >
                            <option
                                v-for="option in options.speeds"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </NativeSelect>
                        <InputError :message="form.errors.max_speed" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="port-poe">{{ $t('PoE') }}</Label>
                        <NativeSelect
                            id="port-poe"
                            v-model="form.poe_type"
                            name="poe_type"
                            :aria-invalid="
                                form.errors.poe_type ? 'true' : undefined
                            "
                        >
                            <option
                                v-for="option in options.poeTypes"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </NativeSelect>
                        <InputError :message="form.errors.poe_type" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="port-poe-label">{{ $t('PoE label') }}</Label>
                    <Input
                        id="port-poe-label"
                        v-model="form.poe_label"
                        name="poe_label"
                        maxlength="32"
                        autocomplete="off"
                        class="sm:max-w-56"
                        :aria-invalid="
                            form.errors.poe_label ? 'true' : undefined
                        "
                    />
                    <InputError :message="form.errors.poe_label" />
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
                        data-test="save-port"
                    >
                        <Spinner v-if="form.processing" />
                        {{ $t('Save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
