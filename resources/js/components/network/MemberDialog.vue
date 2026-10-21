<!-- torsade/resources/js/components/network/MemberDialog.vue -->
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
import { Textarea } from '@/components/ui/textarea';
import { namesInWords } from '@/lib/portPreview';
import { store, update } from '@/routes/device-members';
import type {
    DeviceMember,
    MemberLimits,
    Option,
    Place,
    TemplateChoice,
} from '@/types';

// A short form in a window: a member is added to a device, or modified,
// without leaving the page of the device. A new member made from a
// template gets its ports with it.
const props = defineProps<{
    open: boolean;
    device: { id: number; name: string };
    // The member to modify, or null for a new one.
    member: DeviceMember | null;
    // The members the device already has: the next number is proposed.
    members: DeviceMember[];
    templates: TemplateChoice[];
    roles: Option[];
    // The rooms of the site of the device.
    rooms: Place[];
    limits: MemberLimits;
}>();

const emit = defineEmits<{
    close: [];
}>();

const form = useForm<{
    // A number once typed: the field is a numeric input.
    position: string | number;
    role: string;
    device_template_id: number | null;
    serial_number: string;
    room_id: number | null;
    description: string;
}>({
    position: '1',
    role: 'member',
    device_template_id: null,
    serial_number: '',
    room_id: null,
    description: '',
});

// The number after the highest one, or the lowest free number once the
// highest is the last a device can have.
const nextPosition = (): number => {
    const taken = props.members.map((member) => member.position);
    const next = Math.max(0, ...taken) + 1;

    if (next <= props.limits.max) {
        return next;
    }

    for (let free = props.limits.min; free <= props.limits.max; free++) {
        if (!taken.includes(free)) {
            return free;
        }
    }

    return props.limits.max;
};

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        const member = props.member;
        const last = props.members[props.members.length - 1];

        form.clearErrors();
        form.position = String(member?.position ?? nextPosition());
        form.role = member?.role ?? 'member';
        // The boxes of a stack are often alike: a new member is proposed
        // the template of the last one.
        form.device_template_id = member
            ? (member.template?.id ?? null)
            : (last?.template?.id ?? null);
        form.serial_number = member?.serial_number ?? '';
        form.room_id = member?.room?.id ?? null;
        form.description = member?.description ?? '';
    },
);

const template = computed<TemplateChoice | null>(
    () =>
        props.templates.find(
            (choice) => choice.id === form.device_template_id,
        ) ?? null,
);

// The names the ports of a new member get, with the number typed.
const preview = computed<string>(() => {
    const position = Number(form.position);

    return template.value && Number.isInteger(position) && position >= 1
        ? namesInWords(template.value.port_groups, position)
        : '';
});

const submit = (): void => {
    const options = {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    };

    if (props.member) {
        form.put(update(props.member.id).url, options);
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
        <DialogContent class="sm:max-w-lg" data-test="member-dialog">
            <form class="grid gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{
                        member ? $t('Edit the member') : $t('Add a member')
                    }}</DialogTitle>
                    <DialogDescription>{{
                        $t(
                            'One of the boxes :name is made of. A stack has several members, managed as one switch.',
                            { name: device.name },
                        )
                    }}</DialogDescription>
                </DialogHeader>

                <div class="grid gap-x-5 gap-y-5 sm:grid-cols-[8rem_1fr]">
                    <div class="grid content-start gap-2">
                        <Label for="member-position">{{ $t('Number') }}</Label>
                        <Input
                            id="member-position"
                            v-model="form.position"
                            type="number"
                            name="position"
                            required
                            :min="limits.min"
                            :max="limits.max"
                            step="1"
                            class="font-mono"
                            :aria-invalid="
                                form.errors.position ? 'true' : undefined
                            "
                        />
                        <InputError :message="form.errors.position" />
                    </div>

                    <div class="grid content-start gap-2">
                        <Label for="member-role">{{ $t('Role') }}</Label>
                        <NativeSelect
                            id="member-role"
                            v-model="form.role"
                            name="role"
                            required
                            aria-describedby="member-role-help"
                            :aria-invalid="
                                form.errors.role ? 'true' : undefined
                            "
                        >
                            <option
                                v-for="option in roles"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </NativeSelect>
                        <InputError :message="form.errors.role" />
                        <p
                            id="member-role-help"
                            class="text-sm text-muted-foreground"
                        >
                            {{
                                $t(
                                    'A device has one primary member and one secondary member at most.',
                                )
                            }}
                        </p>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="member-template">{{ $t('Template') }}</Label>
                    <NativeSelect
                        id="member-template"
                        v-model="form.device_template_id"
                        name="device_template_id"
                        aria-describedby="member-template-help"
                        :aria-invalid="
                            form.errors.device_template_id ? 'true' : undefined
                        "
                    >
                        <option :value="null">{{ $t('None') }}</option>
                        <option
                            v-for="choice in templates"
                            :key="choice.id"
                            :value="choice.id"
                        >
                            {{ choice.name }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.device_template_id" />

                    <!-- A new member gets the ports of its template; an
                         existing one keeps the ports it has. -->
                    <p
                        v-if="member"
                        id="member-template-help"
                        class="text-sm text-muted-foreground"
                    >
                        {{
                            $t(
                                'Another template changes none of the ports of the member.',
                            )
                        }}
                    </p>
                    <div
                        v-else-if="template && preview"
                        id="member-template-help"
                        class="rounded-md bg-sunken px-3 py-2 text-sm"
                        role="status"
                        data-test="member-preview"
                    >
                        <span class="text-muted-foreground">{{
                            $t('Ports created')
                        }}</span>
                        <span class="block font-mono">{{ preview }}</span>
                        <span class="block font-medium">{{
                            $tc(
                                ':count port|:count ports',
                                template.ports_count,
                            )
                        }}</span>
                    </div>
                    <p
                        v-else
                        id="member-template-help"
                        class="text-sm text-muted-foreground"
                    >
                        {{
                            $t(
                                'Optional. Without a template, the member has no port until some are added.',
                            )
                        }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="member-serial">{{ $t('Serial number') }}</Label>
                    <Input
                        id="member-serial"
                        v-model="form.serial_number"
                        name="serial_number"
                        maxlength="100"
                        autocomplete="off"
                        class="font-mono"
                        :aria-invalid="
                            form.errors.serial_number ? 'true' : undefined
                        "
                    />
                    <InputError :message="form.errors.serial_number" />
                </div>

                <div class="grid gap-2">
                    <Label for="member-room">{{ $t('Room') }}</Label>
                    <NativeSelect
                        id="member-room"
                        v-model="form.room_id"
                        name="room_id"
                        :aria-invalid="form.errors.room_id ? 'true' : undefined"
                    >
                        <option :value="null">
                            {{ $t('Same as the device') }}
                        </option>
                        <option
                            v-for="room in rooms"
                            :key="room.id"
                            :value="room.id"
                        >
                            {{ room.path }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.room_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="member-description">{{
                        $t('Description')
                    }}</Label>
                    <Textarea
                        id="member-description"
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
                        data-test="save-member"
                    >
                        <Spinner v-if="form.processing" />
                        {{ $t('Save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
