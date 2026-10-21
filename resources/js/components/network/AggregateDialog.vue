<!-- torsade/resources/js/components/network/AggregateDialog.vue -->
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
import { Textarea } from '@/components/ui/textarea';
import { trans, transChoice } from '@/lib/i18n';
import { store, update } from '@/routes/port-aggregates';
import type {
    AggregateDraft,
    DeviceMember,
    Option,
    Port,
    PortAggregate,
    PortModeValue,
    VlanGroup,
} from '@/types';

// A short form in a window: an aggregate of ports is created or modified
// without leaving the page of its device. Its ports are ticked among those
// of the device, whatever their member; its mode and its VLANs are set
// here, and a port that joins it loses its own.
const props = defineProps<{
    open: boolean;
    device: { id: number; name: string };
    // The aggregate to modify, or null for a new one.
    aggregate: PortAggregate | null;
    members: DeviceMember[];
    // Every port of the device.
    ports: Port[];
    protocols: Option[];
    // The VLANs a port can carry: those of the site of the device first,
    // then those of the other sites.
    vlans: VlanGroup[];
    // A new aggregate in front of another one, filled in from it.
    draft?: AggregateDraft | null;
}>();

const emit = defineEmits<{
    close: [];
}>();

const form = useForm<{
    name: string;
    protocol: string;
    description: string;
    port_ids: number[];
    mode: PortModeValue | null;
    untagged_vlan_id: number | null;
    tagged_vlan_ids: number[];
}>({
    name: '',
    protocol: 'lacp',
    description: '',
    port_ids: [],
    mode: null,
    untagged_vlan_id: null,
    tagged_vlan_ids: [],
});

// The fields of the mode are drawn anew each time the window opens.
const opened = ref<number>(0);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        const aggregate = props.aggregate;
        const draft = aggregate === null ? (props.draft ?? null) : null;

        form.clearErrors();
        form.name = aggregate?.name ?? draft?.name ?? '';
        form.protocol = aggregate?.protocol ?? draft?.protocol ?? 'lacp';
        form.description = aggregate?.description ?? '';
        form.port_ids = aggregate
            ? props.ports
                  .filter((port) => port.aggregate?.id === aggregate.id)
                  .map((port) => port.id)
            : [...(draft?.port_ids ?? [])];
        form.mode = aggregate?.mode ?? draft?.mode ?? null;
        form.untagged_vlan_id =
            aggregate?.untagged_vlan_id ?? draft?.untagged_vlan_id ?? null;
        form.tagged_vlan_ids = [
            ...(aggregate?.tagged_vlan_ids ?? draft?.tagged_vlan_ids ?? []),
        ];
        opened.value++;
    },
    // Asked from a deviation, the window is open from the start.
    { immediate: true },
);

// The ports of each member, in the order of the members.
const groups = computed<{ member: DeviceMember; ports: Port[] }[]>(() =>
    props.members
        .map((member) => ({
            member,
            ports: props.ports.filter((port) => port.member_id === member.id),
        }))
        .filter((group) => group.ports.length > 0),
);

// Whether a port is already part of the aggregate the window modifies.
const inThisAggregate = (port: Port): boolean =>
    props.aggregate !== null && port.aggregate?.id === props.aggregate.id;

// A port is part of one aggregate at most: one that belongs to another
// aggregate is shown, and cannot be ticked.
const elsewhere = (port: Port): boolean =>
    port.aggregate !== null && port.aggregate.id !== props.aggregate?.id;

const togglePort = (port: Port): void => {
    form.port_ids = form.port_ids.includes(port.id)
        ? form.port_ids.filter((id) => id !== port.id)
        : [...form.port_ids, port.id];
};

// The ports that join the aggregate with a mode of their own: they lose
// it, and the window says so before anything is saved.
const losing = computed<string>(() => {
    const names = props.ports
        .filter(
            (port) =>
                form.port_ids.includes(port.id) &&
                !inThisAggregate(port) &&
                port.mode !== null,
        )
        .map((port) => port.name);

    if (names.length === 0) {
        return '';
    }

    const shown = names.slice(0, 10).join(', ');

    return transChoice(
        'The port :names will lose its own mode and its own VLANs: those of the aggregate apply to it.|The ports :names will lose their own mode and their own VLANs: those of the aggregate apply to them.',
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

const submit = (): void => {
    const options = {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    };

    if (props.aggregate) {
        form.put(update(props.aggregate.id).url, options);
    } else {
        form.post(store(props.device.id).url, options);
    }
};

const onOpenChange = (open: boolean): void => {
    if (!open) {
        emit('close');
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent class="sm:max-w-2xl" data-test="aggregate-dialog">
            <form class="grid gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{
                        aggregate
                            ? $t('Edit the aggregate')
                            : $t('New aggregate')
                    }}</DialogTitle>
                    <DialogDescription>{{
                        $t(
                            'Several ports of :name used as one link, on one or several of its members.',
                            { name: device.name },
                        )
                    }}</DialogDescription>
                </DialogHeader>

                <!-- In front of another aggregate: where it comes from, and
                     what its ports lose. -->
                <p
                    v-if="aggregate === null && draft"
                    class="rounded-lg border border-dashed p-3 text-sm"
                    data-test="aggregate-draft"
                >
                    {{
                        $t(
                            'In front of :origin, with its protocol, its mode and its VLANs. The ports ticked lose their own mode and their own VLANs: those of the aggregate apply to them.',
                            { origin: draft.origin },
                        )
                    }}
                </p>

                <div class="grid gap-x-4 gap-y-5 sm:grid-cols-[1fr_12rem]">
                    <div class="grid content-start gap-2">
                        <Label for="aggregate-name">{{ $t('Name') }}</Label>
                        <Input
                            id="aggregate-name"
                            v-model="form.name"
                            name="name"
                            required
                            maxlength="32"
                            autocomplete="off"
                            spellcheck="false"
                            class="font-mono"
                            aria-describedby="aggregate-name-help"
                            :aria-invalid="
                                form.errors.name ? 'true' : undefined
                            "
                        />
                        <InputError :message="form.errors.name" />
                        <p
                            id="aggregate-name-help"
                            class="text-sm text-muted-foreground"
                        >
                            {{
                                $t('As the device writes it: Po1, lag 1, Trk1.')
                            }}
                        </p>
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="aggregate-protocol">{{
                            $t('Protocol')
                        }}</Label>
                        <NativeSelect
                            id="aggregate-protocol"
                            v-model="form.protocol"
                            name="protocol"
                            :aria-invalid="
                                form.errors.protocol ? 'true' : undefined
                            "
                        >
                            <option
                                v-for="option in protocols"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </NativeSelect>
                        <InputError :message="form.errors.protocol" />
                    </div>
                </div>

                <div
                    class="grid gap-2"
                    role="group"
                    aria-labelledby="aggregate-ports-label"
                >
                    <p id="aggregate-ports-label" class="text-sm font-medium">
                        {{ $t('Ports') }}
                        <span class="font-normal text-muted-foreground">{{
                            form.port_ids.length
                        }}</span>
                    </p>
                    <div
                        class="grid max-h-56 gap-3 overflow-y-auto rounded-md border p-3"
                        data-test="aggregate-ports"
                    >
                        <div
                            v-for="group in groups"
                            :key="group.member.id"
                            class="grid gap-2"
                        >
                            <p class="text-sm text-muted-foreground">
                                {{
                                    $t('Member :position', {
                                        position: group.member.position,
                                    })
                                }}
                            </p>
                            <div
                                class="grid grid-cols-2 gap-x-4 gap-y-2 sm:grid-cols-4"
                            >
                                <label
                                    v-for="port in group.ports"
                                    :key="port.id"
                                    class="flex min-w-0 items-center gap-2"
                                    :class="
                                        elsewhere(port)
                                            ? 'text-muted-foreground'
                                            : undefined
                                    "
                                >
                                    <input
                                        type="checkbox"
                                        class="size-4.5 shrink-0 rounded-xs accent-primary"
                                        :checked="
                                            form.port_ids.includes(port.id)
                                        "
                                        :disabled="elsewhere(port)"
                                        @change="togglePort(port)"
                                    />
                                    <span class="truncate font-mono text-sm"
                                        >{{ port.name
                                        }}<template v-if="elsewhere(port)">
                                            ({{
                                                port.aggregate?.name
                                            }})</template
                                        ></span
                                    >
                                </label>
                            </div>
                        </div>
                        <p
                            v-if="groups.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            {{ $t('This device has no port yet.') }}
                        </p>
                    </div>
                    <InputError :message="form.errors.port_ids" />
                    <p class="text-sm text-muted-foreground">
                        {{
                            $t(
                                'The mode and the VLANs are set on the aggregate: a port that joins it loses its own.',
                            )
                        }}
                    </p>
                    <!-- Said before anything is saved. -->
                    <p
                        v-if="losing"
                        role="status"
                        class="rounded-md border border-border-strong bg-sunken px-3 py-2 text-sm"
                        data-test="aggregate-losing"
                    >
                        {{ losing }}
                    </p>
                </div>

                <PortModeFields
                    :key="opened"
                    v-model:mode="form.mode"
                    v-model:untagged="form.untagged_vlan_id"
                    v-model:tagged="form.tagged_vlan_ids"
                    id-prefix="aggregate"
                    :vlans="vlans"
                    :errors="form.errors"
                />

                <div class="grid gap-2">
                    <Label for="aggregate-description">{{
                        $t('Description')
                    }}</Label>
                    <Textarea
                        id="aggregate-description"
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
                        :disabled="form.processing"
                        data-test="save-aggregate"
                    >
                        <Spinner v-if="form.processing" />
                        {{ $t('Save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
