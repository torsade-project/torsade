<!-- torsade/resources/js/components/network/PortSeriesDialog.vue -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
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
import { trans } from '@/lib/i18n';
import { patternProblem } from '@/lib/portNaming';
import { namesInWords, patternMessage } from '@/lib/portPreview';
import { store } from '@/routes/ports';
import type { DeviceMember, PortOptions } from '@/types';

// A series of ports added to a member, without a template: a pattern for
// their names, a first and a last number, and what they all are. The names
// are shown while the series is typed.
const props = defineProps<{
    open: boolean;
    // With its serial number when it has one: the number goes to the
    // first member, when the ports create it.
    device: { id: number; name: string; serial_number?: string | null };
    // Empty: the device gets its first member with the ports.
    members: DeviceMember[];
    options: PortOptions;
}>();

const emit = defineEmits<{
    close: [];
}>();

const form = useForm<{
    device_member_id: number | null;
    name_pattern: string;
    // Numbers once typed: the fields are numeric inputs.
    first_number: string | number;
    last_number: string | number;
    connector: string;
    max_speed: string;
    poe_type: string;
    poe_label: string;
}>({
    device_member_id: null,
    name_pattern: '{m}/0/{n}',
    first_number: '1',
    last_number: '24',
    connector: 'rj45',
    max_speed: '1g',
    poe_type: 'none',
    poe_label: '',
});

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.clearErrors();

        // The series typed before is kept: a second one is often like the
        // first. Only the member is chosen again when it is gone.
        if (
            !props.members.some((member) => member.id === form.device_member_id)
        ) {
            form.device_member_id = props.members[0]?.id ?? null;
        }
    },
);

// The number that takes the place of "{m}": the one of the member chosen,
// or 1 for the member a device without one is about to get.
const position = computed<number>(
    () =>
        props.members.find((member) => member.id === form.device_member_id)
            ?.position ?? 1,
);

const whole = (value: string | number): number | null => {
    const text = String(value).trim();

    return /^\d+$/.test(text) ? Number(text) : null;
};

const quantity = computed<number | null>(() => {
    const first = whole(form.first_number);
    const last = whole(form.last_number);

    return first === null || last === null || last < first
        ? null
        : last - first + 1;
});

// One port alone, its first and its last number being the same: its
// pattern can do without "{n}".
const single = computed<boolean>(() => quantity.value === 1);

// What is wrong with the series, told while it is typed: the words the
// server uses when it refuses the same series.
const hint = computed<string | null>(() => {
    if (form.name_pattern.trim() === '') {
        return null;
    }

    return patternMessage(form.name_pattern, { single: single.value });
});

const tooMany = computed<boolean>(
    () =>
        quantity.value !== null &&
        quantity.value > props.options.limits.quantity,
);

const preview = computed<string>(() => {
    const first = whole(form.first_number);

    return first === null ||
        quantity.value === null ||
        tooMany.value ||
        patternProblem(form.name_pattern, single.value) !== null
        ? ''
        : namesInWords(
              [
                  {
                      name_pattern: form.name_pattern,
                      first_number: first,
                      quantity: quantity.value,
                  },
              ],
              position.value,
          );
});

const submit = (): void => {
    form.transform((data) => ({
        ...data,
        name_pattern: data.name_pattern.trim(),
        poe_label: data.poe_label.trim() === '' ? null : data.poe_label,
    })).post(store(props.device.id).url, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
};

const onOpenChange = (open: boolean): void => {
    if (!open) {
        emit('close');
    }
};

// Kept out of the template, where its quotation marks would end an
// attribute.
const patternHelp = trans(
    '{m} is the number of the member, {n} the number of the port: "{m}/0/{n}", "Gi{m}/0/{n}", "port{n}". One port alone, with the same first and last number, can do without {n}: "MGMT", "WAN".',
);
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent class="sm:max-w-xl" data-test="port-series-dialog">
            <form class="grid gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ $t('Add ports') }}</DialogTitle>
                    <DialogDescription>{{
                        members.length === 0
                            ? $t(
                                  'A series of ports for :name, which gets its first member with them: the member 1, primary.',
                                  { name: device.name },
                              )
                            : $t(
                                  'A series of ports for a member of :name, added after those it has.',
                                  { name: device.name },
                              )
                    }}</DialogDescription>
                </DialogHeader>

                <p
                    v-if="members.length === 0 && device.serial_number"
                    class="text-sm text-muted-foreground"
                    data-test="serial-number-moves"
                >
                    {{
                        $t(
                            'Its serial number, :serial, goes to the member 1 and leaves the device.',
                            { serial: device.serial_number },
                        )
                    }}
                </p>

                <div v-if="members.length > 1" class="grid gap-2">
                    <Label for="series-member">{{ $t('Member') }}</Label>
                    <NativeSelect
                        id="series-member"
                        v-model="form.device_member_id"
                        name="device_member_id"
                        required
                        :aria-invalid="
                            form.errors.device_member_id ? 'true' : undefined
                        "
                    >
                        <option
                            v-for="member in members"
                            :key="member.id"
                            :value="member.id"
                        >
                            {{
                                $t('Member :position', {
                                    position: member.position,
                                })
                            }}
                            — {{ member.role_label }}
                        </option>
                    </NativeSelect>
                </div>
                <InputError :message="form.errors.device_member_id" />

                <div class="grid gap-x-4 gap-y-5 sm:grid-cols-[1fr_7rem_7rem]">
                    <div class="grid content-start gap-2">
                        <Label for="series-pattern">{{ $t('Pattern') }}</Label>
                        <Input
                            id="series-pattern"
                            v-model="form.name_pattern"
                            name="name_pattern"
                            required
                            :maxlength="options.limits.name"
                            autocomplete="off"
                            spellcheck="false"
                            class="font-mono"
                            aria-describedby="series-pattern-help"
                            :aria-invalid="
                                hint || form.errors.name_pattern
                                    ? 'true'
                                    : undefined
                            "
                        />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="series-first">{{
                            $t('First number')
                        }}</Label>
                        <Input
                            id="series-first"
                            v-model="form.first_number"
                            type="number"
                            name="first_number"
                            required
                            min="0"
                            :max="options.limits.first_number"
                            step="1"
                            class="font-mono"
                            :aria-invalid="
                                form.errors.first_number ? 'true' : undefined
                            "
                        />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="series-last">{{ $t('Last number') }}</Label>
                        <Input
                            id="series-last"
                            v-model="form.last_number"
                            type="number"
                            name="last_number"
                            required
                            min="0"
                            step="1"
                            class="font-mono"
                            :aria-invalid="
                                tooMany || form.errors.last_number
                                    ? 'true'
                                    : undefined
                            "
                        />
                    </div>
                </div>

                <div class="-mt-3 grid gap-1.5">
                    <p
                        v-if="hint"
                        class="text-sm text-error"
                        data-test="series-hint"
                    >
                        {{ hint }}
                    </p>
                    <InputError v-else :message="form.errors.name_pattern" />
                    <InputError :message="form.errors.first_number" />
                    <p
                        v-if="tooMany"
                        class="text-sm text-error"
                        data-test="series-too-many"
                    >
                        {{
                            $tc(
                                'A series holds :count port at most.|A series holds :count ports at most.',
                                options.limits.quantity,
                            )
                        }}
                    </p>
                    <InputError v-else :message="form.errors.last_number" />
                    <p
                        id="series-pattern-help"
                        class="text-sm text-muted-foreground"
                    >
                        {{ patternHelp }}
                    </p>
                </div>

                <div class="grid gap-x-4 gap-y-5 sm:grid-cols-3">
                    <div class="grid content-start gap-2">
                        <Label for="series-connector">{{
                            $t('Connector')
                        }}</Label>
                        <NativeSelect
                            id="series-connector"
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
                        <Label for="series-speed">{{ $t('Speed') }}</Label>
                        <NativeSelect
                            id="series-speed"
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
                        <Label for="series-poe">{{ $t('PoE') }}</Label>
                        <NativeSelect
                            id="series-poe"
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
                    <Label for="series-poe-label">{{ $t('PoE label') }}</Label>
                    <Input
                        id="series-poe-label"
                        v-model="form.poe_label"
                        name="poe_label"
                        maxlength="32"
                        autocomplete="off"
                        class="sm:max-w-56"
                        aria-describedby="series-poe-label-help"
                        :aria-invalid="
                            form.errors.poe_label ? 'true' : undefined
                        "
                    />
                    <InputError :message="form.errors.poe_label" />
                    <p
                        id="series-poe-label-help"
                        class="text-sm text-muted-foreground"
                    >
                        {{
                            $t(
                                'Optional. The word of the manufacturer, such as PoE+.',
                            )
                        }}
                    </p>
                </div>

                <!-- Always there, so that a screen reader is told when the
                     names change. -->
                <div
                    class="rounded-md bg-sunken px-3 py-2 text-sm"
                    role="status"
                    data-test="series-preview"
                >
                    <span class="text-muted-foreground">{{
                        $t('Ports created')
                    }}</span>
                    <template v-if="preview && quantity !== null">
                        <span class="block font-mono">{{ preview }}</span>
                        <span class="block font-medium">{{
                            $tc(':count port|:count ports', quantity)
                        }}</span>
                    </template>
                    <span v-else class="block text-muted-foreground">{{
                        $t('No port yet.')
                    }}</span>
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
                        data-test="save-port-series"
                    >
                        <Spinner v-if="form.processing" />
                        {{ $t('Save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
