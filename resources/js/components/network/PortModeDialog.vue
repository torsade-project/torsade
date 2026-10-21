<!-- torsade/resources/js/components/network/PortModeDialog.vue -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
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
import { Spinner } from '@/components/ui/spinner';
import { transChoice } from '@/lib/i18n';
import { updateMany } from '@/routes/ports';
import type { PortModeValue, VlanGroup } from '@/types';

// The same mode and the same VLANs for every port ticked in the table of a
// device: all of them, or none when one port is in the way.
const props = defineProps<{
    open: boolean;
    device: { id: number; name: string };
    // The ports ticked.
    ports: number[];
    // The VLANs a port can carry: those of the site of the device first,
    // then those of the other sites.
    vlans: VlanGroup[];
}>();

const emit = defineEmits<{
    close: [];
}>();

const form = useForm<{
    ports: number[];
    action: 'mode';
    mode: PortModeValue | null;
    untagged_vlan_id: number | null;
    tagged_vlan_ids: number[];
}>({
    ports: [],
    action: 'mode',
    mode: 'access',
    untagged_vlan_id: null,
    tagged_vlan_ids: [],
});

// The fields are drawn anew each time the window opens.
const opened = ref<number>(0);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.clearErrors();
        form.mode = 'access';
        form.untagged_vlan_id = null;
        form.tagged_vlan_ids = [];
        opened.value++;
    },
);

const submit = (): void => {
    form.ports = props.ports;

    form.patch(updateMany(props.device.id).url, {
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
        <DialogContent class="sm:max-w-xl" data-test="port-mode-dialog">
            <form class="grid gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ $t('Mode and VLANs') }}</DialogTitle>
                    <DialogDescription>{{
                        transChoice(
                            'The same mode and the same VLANs for the port selected on :name, in place of what it has.|The same mode and the same VLANs for the :count ports selected on :name, in place of what they have.',
                            ports.length,
                            { name: device.name },
                        )
                    }}</DialogDescription>
                </DialogHeader>

                <PortModeFields
                    :key="opened"
                    v-model:mode="form.mode"
                    v-model:untagged="form.untagged_vlan_id"
                    v-model:tagged="form.tagged_vlan_ids"
                    id-prefix="bulk"
                    :vlans="vlans"
                    :errors="form.errors"
                    :allow-none="false"
                />

                <!-- The ports that stand in the way, named by the server. -->
                <InputError
                    :message="form.errors.ports"
                    data-test="port-mode-refusal"
                />

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
                        data-test="save-port-mode"
                    >
                        <Spinner v-if="form.processing" />
                        {{ $t('Apply') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
