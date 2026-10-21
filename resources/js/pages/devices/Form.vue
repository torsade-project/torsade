<!-- torsade/resources/js/pages/devices/Form.vue -->
<script setup lang="ts">
import { Head, Link, setLayoutProps, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { trans } from '@/lib/i18n';
import { create, edit, index, show, store, update } from '@/routes/devices';
import type {
    EditableDevice,
    Option,
    RoomOption,
    SiteOption,
    StatusValue,
} from '@/types';

// One page for a new device and for an existing one.
const props = defineProps<{
    device: EditableDevice | null;
    sites: SiteOption[];
    // Every room, with its path and its site: only those of the site chosen
    // are offered.
    rooms: RoomOption[];
    types: Option[];
    statuses: Option[];
    // The site and the room chosen before opening the form, if any.
    site: number | null;
    room: number | null;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: trans('Devices'), href: index() },
        props.device
            ? { title: props.device.name, href: edit(props.device.id) }
            : { title: trans('New device'), href: create() },
    ],
});

type DeviceForm = {
    site_id: number | null;
    room_id: number | null;
    name: string;
    type: string;
    manufacturer: string;
    model: string;
    serial_number: string;
    status: StatusValue;
    description: string;
};

const form = useForm<DeviceForm>({
    site_id:
        props.device?.site_id ??
        props.site ??
        (props.sites.length === 1 ? props.sites[0].id : null),
    room_id: props.device?.room_id ?? props.room,
    name: props.device?.name ?? '',
    type: props.device?.type ?? '',
    manufacturer: props.device?.manufacturer ?? '',
    model: props.device?.model ?? '',
    serial_number: props.device?.serial_number ?? '',
    status: props.device?.status ?? 'active',
    description: props.device?.description ?? '',
});

// The rooms of the site chosen: a device is kept on its own site.
const siteRooms = computed<RoomOption[]>(() =>
    props.rooms.filter((room) => room.site_id === form.site_id),
);

// A room belongs to one site: changing the site leaves it.
const onSiteChange = (): void => {
    form.room_id = null;
};

const submit = (): void => {
    if (props.device) {
        form.put(update(props.device.id).url);
    } else {
        form.post(store().url);
    }
};
</script>

<template>
    <Head :title="device ? $t('Edit the device') : $t('New device')" />

    <div class="flex max-w-3xl flex-col gap-7">
        <Heading
            :title="device ? $t('Edit the device') : $t('New device')"
            :description="
                $t(
                    'A machine of a site. Its IP addresses are attached to it from its page.',
                )
            "
        />

        <form
            class="panel flex flex-col gap-5 p-5 sm:p-6"
            @submit.prevent="submit"
        >
            <div class="grid gap-x-5 gap-y-5 sm:grid-cols-2">
                <div class="grid content-start gap-2">
                    <Label for="site_id">{{ $t('Site') }}</Label>
                    <NativeSelect
                        id="site_id"
                        v-model="form.site_id"
                        name="site_id"
                        required
                        :aria-invalid="form.errors.site_id ? 'true' : undefined"
                        @change="onSiteChange"
                    >
                        <option :value="null" disabled>
                            {{ $t('Choose a site') }}
                        </option>
                        <option
                            v-for="option in sites"
                            :key="option.id"
                            :value="option.id"
                        >
                            {{ option.name }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.site_id" />
                </div>

                <div class="grid content-start gap-2">
                    <Label for="room_id">{{ $t('Room') }}</Label>
                    <NativeSelect
                        id="room_id"
                        v-model="form.room_id"
                        name="room_id"
                        aria-describedby="room-help"
                        :disabled="form.site_id === null"
                        :aria-invalid="form.errors.room_id ? 'true' : undefined"
                    >
                        <option :value="null">
                            {{ $t('Not specified') }}
                        </option>
                        <option
                            v-for="option in siteRooms"
                            :key="option.id"
                            :value="option.id"
                        >
                            {{ option.path
                            }}{{
                                option.is_technical
                                    ? ` (${$t('Technical')})`
                                    : ''
                            }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.room_id" />
                    <p id="room-help" class="text-sm text-muted-foreground">
                        {{
                            form.site_id !== null && siteRooms.length === 0
                                ? $t(
                                      'This site has no room yet: buildings, floors and rooms are declared on the page of the site.',
                                  )
                                : $t(
                                      'Optional. A room of the site of the device; changing the site leaves the room.',
                                  )
                        }}
                    </p>
                </div>
            </div>

            <div class="grid gap-x-5 gap-y-5 sm:grid-cols-2">
                <div class="grid content-start gap-2">
                    <Label for="name">{{ $t('Name') }}</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        name="name"
                        required
                        maxlength="100"
                        autocomplete="off"
                        aria-describedby="name-help"
                        :aria-invalid="form.errors.name ? 'true' : undefined"
                    />
                    <InputError :message="form.errors.name" />
                    <p id="name-help" class="text-sm text-muted-foreground">
                        {{
                            $t(
                                'Free text, unique within the site whatever its case: "Reception printer" as well as "HV-MAI-01".',
                            )
                        }}
                    </p>
                </div>

                <div class="grid content-start gap-2">
                    <Label for="type">{{ $t('Type') }}</Label>
                    <NativeSelect
                        id="type"
                        v-model="form.type"
                        name="type"
                        required
                        :aria-invalid="form.errors.type ? 'true' : undefined"
                    >
                        <option value="" disabled>
                            {{ $t('Choose a type') }}
                        </option>
                        <option
                            v-for="option in types"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.type" />
                </div>
            </div>

            <div
                class="grid gap-x-5 gap-y-5"
                :class="
                    device?.has_members ? 'sm:grid-cols-2' : 'sm:grid-cols-3'
                "
            >
                <div class="grid content-start gap-2">
                    <Label for="manufacturer">{{ $t('Manufacturer') }}</Label>
                    <Input
                        id="manufacturer"
                        v-model="form.manufacturer"
                        name="manufacturer"
                        maxlength="100"
                        autocomplete="off"
                        :aria-invalid="
                            form.errors.manufacturer ? 'true' : undefined
                        "
                    />
                    <InputError :message="form.errors.manufacturer" />
                </div>

                <div class="grid content-start gap-2">
                    <Label for="model">{{ $t('Model') }}</Label>
                    <Input
                        id="model"
                        v-model="form.model"
                        name="model"
                        maxlength="100"
                        autocomplete="off"
                        :aria-invalid="form.errors.model ? 'true' : undefined"
                    />
                    <InputError :message="form.errors.model" />
                </div>

                <!-- A device made of members has no serial number of its
                     own: each member has one. -->
                <div
                    v-if="!device?.has_members"
                    class="grid content-start gap-2"
                >
                    <Label for="serial_number">{{ $t('Serial number') }}</Label>
                    <Input
                        id="serial_number"
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
            </div>

            <p
                v-if="device?.has_members"
                class="text-sm text-muted-foreground"
                data-test="serial-number-note"
            >
                {{
                    $t(
                        'The serial numbers are those of the members of the device, on its page.',
                    )
                }}
            </p>

            <div class="grid gap-2 sm:max-w-56">
                <Label for="status">{{ $t('Status') }}</Label>
                <NativeSelect
                    id="status"
                    v-model="form.status"
                    name="status"
                    :aria-invalid="form.errors.status ? 'true' : undefined"
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

            <div class="grid gap-2">
                <Label for="description">{{ $t('Description') }}</Label>
                <Textarea
                    id="description"
                    v-model="form.description"
                    name="description"
                    maxlength="1000"
                    rows="3"
                    :aria-invalid="form.errors.description ? 'true' : undefined"
                />
                <InputError :message="form.errors.description" />
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    data-test="save-device"
                >
                    <Spinner v-if="form.processing" />
                    {{ $t('Save') }}
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="device ? show(device.id) : index()">{{
                        $t('Cancel')
                    }}</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
