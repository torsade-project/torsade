<!-- torsade/resources/js/pages/sites/HostnameDevices.vue -->
<script setup lang="ts">
import { Head, Link, setLayoutProps, useForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { trans } from '@/lib/i18n';
import { show as showDevice } from '@/routes/devices';
import { index, show } from '@/routes/sites';
import { create, store } from '@/routes/sites/hostname-devices';
import type {
    HostnameCandidate,
    Option,
    SiteOption,
    StatusValue,
} from '@/types';

// The devices of a site created from the host names of its addresses: each
// distinct name is a line, ready to become a device whose interfaces are
// the addresses that bear it, or to join the device of the site that
// already has that name. Everything ticked is done, or nothing is.
const props = defineProps<{
    site: SiteOption;
    candidates: HostnameCandidate[];
    types: Option[];
    statuses: Option[];
    // How many names can be done at once, and how long the name of a
    // device can be.
    maxLines: number;
    maxNameLength: number;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: trans('Sites'), href: index() },
        { title: props.site.name, href: show(props.site.id) },
        {
            title: trans('Create devices from host names'),
            href: create(props.site.id),
        },
    ],
});

// One line per host name, all ticked: the type and the status proposed
// stay free. A name too long to be the name of a device cannot be ticked.
const lines = props.candidates.map((candidate) => ({
    candidate,
    fields: reactive<{ selected: boolean; type: string; status: StatusValue }>({
        selected: !candidate.too_long,
        type: candidate.type,
        status: candidate.status,
    }),
}));

type Line = (typeof lines)[number];

const form = useForm<Record<string, never>>({});

const selectable = lines.filter((line) => !line.candidate.too_long);

const selected = computed<Line[]>(() =>
    lines.filter((line) => line.fields.selected),
);

const allSelected = computed<boolean>(
    () => selectable.length > 0 && selected.value.length === selectable.length,
);

const toggleAll = (): void => {
    const next = !allSelected.value;

    for (const line of selectable) {
        line.fields.selected = next;
    }
};

// The names as they were sent: the server tells each refusal by the place
// of its line in what it received.
let sent: string[] = [];

const errorOf = (line: Line, ...fields: string[]): string | undefined => {
    const place = sent.indexOf(line.candidate.hostname);

    if (place === -1) {
        return undefined;
    }

    const errors = form.errors as Record<string, string | undefined>;

    for (const field of fields) {
        const message = errors[`lines.${place}.${field}`];

        if (message) {
            return message;
        }
    }

    return undefined;
};

const submit = (): void => {
    const chosen = selected.value;

    sent = chosen.map((line) => line.candidate.hostname);

    form.transform(() => ({
        lines: chosen.map((line) => ({
            hostname: line.candidate.hostname,
            type: line.fields.type,
            status: line.fields.status,
        })),
    })).post(store(props.site.id).url, { preserveScroll: true });
};
</script>

<template>
    <Head
        :title="
            $t('Create the devices of :site from host names', {
                site: site.name,
            })
        "
    />

    <div class="flex max-w-4xl flex-col gap-7">
        <Heading
            :title="
                $t('Create the devices of :site from host names', {
                    site: site.name,
                })
            "
            :description="
                $t(
                    'Each host name of the addresses of this site that belong to no device can become a device, and its addresses the interfaces of that device. The type and the status are proposed from those addresses, and can be changed. What is ticked is done together, or nothing is. No host name is changed.',
                )
            "
        />

        <div
            v-if="candidates.length === 0"
            class="flex flex-col items-start gap-4 rounded-lg border border-dashed p-5"
            data-test="hostnames-empty"
        >
            <p class="text-muted-foreground">
                {{
                    $t(
                        'Nothing is left to do: every address of this site that has a host name belongs to a device, or none has a host name.',
                    )
                }}
            </p>
            <Button variant="outline" as-child>
                <Link :href="show(site.id)">{{ $t('Back to the site') }}</Link>
            </Button>
        </div>

        <form v-else class="flex flex-col gap-5" @submit.prevent="submit">
            <div class="flex flex-wrap items-center gap-x-5 gap-y-3">
                <Button type="button" variant="outline" @click="toggleAll">
                    {{ allSelected ? $t('Untick all') : $t('Tick all') }}
                </Button>
                <p
                    class="text-sm text-muted-foreground"
                    role="status"
                    data-test="hostnames-ticked"
                >
                    {{
                        $tc(
                            ':count host name ticked|:count host names ticked',
                            selected.length,
                        )
                    }}
                </p>
            </div>

            <p
                v-if="candidates.length > maxLines"
                class="text-sm text-muted-foreground"
                data-test="hostnames-limit"
            >
                {{
                    $tc(
                        'Tick :count host name at most at a time.|Tick :count host names at most at a time.',
                        maxLines,
                    )
                }}
            </p>

            <div class="table-frame">
                <table
                    class="table-dense"
                    :aria-label="$t('Host names')"
                    data-test="hostname-lines"
                >
                    <thead>
                        <tr>
                            <th scope="col">{{ $t('Host name') }}</th>
                            <th scope="col">{{ $t('Addresses') }}</th>
                            <th scope="col">{{ $t('Type') }}</th>
                            <th scope="col">{{ $t('Status') }}</th>
                            <th scope="col">{{ $t('Action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="line in lines"
                            :key="line.candidate.hostname"
                            :data-test="`hostname-line-${line.candidate.hostname}`"
                        >
                            <td>
                                <label class="flex items-center gap-2.5">
                                    <input
                                        v-model="line.fields.selected"
                                        type="checkbox"
                                        class="size-4.5 shrink-0 rounded-xs accent-primary"
                                        :disabled="line.candidate.too_long"
                                        :aria-invalid="
                                            errorOf(line, 'hostname')
                                                ? 'true'
                                                : undefined
                                        "
                                    />
                                    <span class="font-medium break-all">{{
                                        line.candidate.hostname
                                    }}</span>
                                </label>
                                <InputError
                                    class="mt-1"
                                    :message="errorOf(line, 'hostname')"
                                />
                                <p
                                    v-if="line.candidate.too_long"
                                    class="mt-1 text-sm text-muted-foreground"
                                >
                                    {{
                                        $tc(
                                            'Longer than the :count character of the name of a device: create the device by hand.|Longer than the :count characters of the name of a device: create the device by hand.',
                                            maxNameLength,
                                        )
                                    }}
                                </p>
                            </td>
                            <td>
                                <div class="whitespace-nowrap">
                                    {{
                                        $tc(
                                            ':count address|:count addresses',
                                            line.candidate.addresses_count,
                                        )
                                    }}
                                </div>
                                <div
                                    class="font-mono text-note text-muted-foreground"
                                >
                                    {{ line.candidate.addresses.join(', ')
                                    }}<template
                                        v-if="
                                            line.candidate.addresses_count >
                                            line.candidate.addresses.length
                                        "
                                        >, …</template
                                    >
                                </div>
                            </td>
                            <td>
                                <!-- The device is already there: its type
                                     is its own. -->
                                <span v-if="line.candidate.device">{{
                                    line.candidate.device.type_label
                                }}</span>
                                <template v-else>
                                    <NativeSelect
                                        v-model="line.fields.type"
                                        class="h-9.5 min-w-40"
                                        :aria-label="
                                            $t('Type of :hostname', {
                                                hostname:
                                                    line.candidate.hostname,
                                            })
                                        "
                                        :disabled="!line.fields.selected"
                                        :aria-invalid="
                                            errorOf(line, 'type')
                                                ? 'true'
                                                : undefined
                                        "
                                    >
                                        <option
                                            v-for="type in types"
                                            :key="type.value"
                                            :value="type.value"
                                        >
                                            {{ type.label }}
                                        </option>
                                    </NativeSelect>
                                    <InputError
                                        class="mt-1"
                                        :message="errorOf(line, 'type')"
                                    />
                                </template>
                            </td>
                            <td>
                                <!-- The device is already there: its
                                     status is its own. -->
                                <span v-if="line.candidate.device">{{
                                    line.candidate.device.status_label
                                }}</span>
                                <template v-else>
                                    <NativeSelect
                                        v-model="line.fields.status"
                                        class="h-9.5 min-w-28"
                                        :aria-label="
                                            $t('Status of :hostname', {
                                                hostname:
                                                    line.candidate.hostname,
                                            })
                                        "
                                        :disabled="!line.fields.selected"
                                        :aria-invalid="
                                            errorOf(line, 'status')
                                                ? 'true'
                                                : undefined
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
                                    <InputError
                                        class="mt-1"
                                        :message="errorOf(line, 'status')"
                                    />
                                </template>
                            </td>
                            <td data-test="hostname-action">
                                <template v-if="line.candidate.device">
                                    {{ $t('Attach to the existing device') }}
                                    <Link
                                        :href="
                                            showDevice(line.candidate.device.id)
                                        "
                                        class="link font-medium"
                                        >{{ line.candidate.device.name }}</Link
                                    >
                                </template>
                                <template v-else>{{ $t('Create') }}</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <InputError
                :message="
                    (form.errors as Record<string, string | undefined>).lines
                "
            />

            <div class="flex flex-wrap items-center gap-2.5">
                <Button
                    type="submit"
                    :disabled="form.processing || selected.length === 0"
                    data-test="create-hostname-devices"
                >
                    <Spinner v-if="form.processing" />
                    {{
                        $tc(
                            'Do the ticked line|Do the :count ticked lines',
                            selected.length,
                        )
                    }}
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="show(site.id)">{{ $t('Cancel') }}</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
