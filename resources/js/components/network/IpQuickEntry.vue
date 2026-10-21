<!-- torsade/resources/js/components/network/IpQuickEntry.vue -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { nextTick, onMounted, ref } from 'vue';
import AlertError from '@/components/AlertError.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { trans } from '@/lib/i18n';
import { nextFreeAddress } from '@/lib/ipv4';
import { bulk } from '@/routes/ip-addresses';
import type { FreeInterval, Option, Subnet } from '@/types';

// Several addresses typed one line after the other, from the keyboard alone:
// Enter or Tab goes to the next field, Enter in the last column opens a new
// line with the next free address, Escape drops the current line. Everything
// is sent in one request, and nothing is saved unless every line is accepted.
const props = defineProps<{
    subnet: Subnet;
    freeIntervals: FreeInterval[];
    deviceTypes: Option[];
    assignments: Option[];
    statuses: Option[];
    maxLines: number;
}>();

const emit = defineEmits<{
    close: [];
}>();

const FIELDS = [
    'address',
    'hostname',
    'mac_address',
    'device_type',
    'assignment',
    'status',
    'description',
] as const;

type Field = (typeof FIELDS)[number];

type Line = Record<Field, string> & {
    // Stable identity of the line, whatever its position.
    key: number;
    // Whether anything was typed in the line.
    touched: boolean;
};

const lastColumn = FIELDS.length - 1;

const grid = ref<HTMLElement | null>(null);
const lines = ref<Line[]>([]);
// Messages of the server, keyed "<line key>.<field>".
const errors = ref<Record<string, string>>({});
const generalErrors = ref<string[]>([]);
const saving = ref<boolean>(false);

let nextKey = 0;

// Each new line is offered the lowest free address that no other line
// already uses.
const newLine = (): Line => ({
    key: nextKey++,
    address:
        nextFreeAddress(
            props.freeIntervals,
            new Set(lines.value.map((line) => line.address.trim())),
        ) ?? '',
    hostname: '',
    mac_address: '',
    device_type: '',
    assignment: 'static',
    status: 'active',
    description: '',
    touched: false,
});

const focusCell = async (line: number, column: number): Promise<void> => {
    await nextTick();

    const cell = grid.value?.querySelector<HTMLElement>(
        `[data-line="${line}"][data-column="${column}"]`,
    );

    cell?.focus();

    // The proposed address is selected: typing replaces it, Enter keeps it.
    if (cell instanceof HTMLInputElement) {
        cell.select();
    }
};

const canAddLine = (): boolean => lines.value.length < props.maxLines;

const addLine = (): void => {
    if (!canAddLine()) {
        return;
    }

    lines.value.push(newLine());

    void focusCell(lines.value.length - 1, 0);
};

const removeLine = (index: number): void => {
    const [removed] = lines.value.splice(index, 1);

    errors.value = Object.fromEntries(
        Object.entries(errors.value).filter(
            ([key]) => !key.startsWith(`${removed.key}.`),
        ),
    );

    if (lines.value.length === 0) {
        addLine();

        return;
    }

    void focusCell(Math.max(0, index - 1), index === 0 ? 0 : lastColumn);
};

// The lines to save: every line but a last one left as it was proposed.
const linesToSend = (): Line[] =>
    lines.value.filter(
        (line, index) => line.touched || index < lines.value.length - 1,
    );

const submit = (): void => {
    if (saving.value) {
        return;
    }

    const sent = linesToSend();

    errors.value = {};
    generalErrors.value = [];

    if (sent.length === 0) {
        generalErrors.value = [trans('Enter at least one address.')];

        return;
    }

    router.post(
        bulk(props.subnet.id).url,
        {
            addresses: sent.map((line) =>
                Object.fromEntries(
                    FIELDS.map((field) => [field, line[field].trim()]),
                ),
            ),
        },
        {
            preserveScroll: true,
            // The lines must survive a refusal.
            preserveState: true,
            onStart: () => {
                saving.value = true;
            },
            onFinish: () => {
                saving.value = false;
            },
            onSuccess: () => emit('close'),
            onError: (received) => {
                const mapped: Record<string, string> = {};
                const general: string[] = [];

                // "addresses.3.hostname" is about the fourth line sent.
                for (const [key, message] of Object.entries(received)) {
                    const match = /^addresses\.(\d+)\.(\w+)$/.exec(key);
                    const line = match ? sent[Number(match[1])] : undefined;

                    if (match && line) {
                        mapped[`${line.key}.${match[2]}`] = message;
                    } else {
                        general.push(message);
                    }
                }

                errors.value = mapped;
                generalErrors.value = general;

                const firstKey = Object.keys(mapped)[0];

                if (firstKey) {
                    const [lineKey, field] = firstKey.split('.');

                    void focusCell(
                        lines.value.findIndex(
                            (line) => String(line.key) === lineKey,
                        ),
                        FIELDS.indexOf(field as Field),
                    );
                }
            },
        },
    );
};

const onKeydown = (
    event: KeyboardEvent,
    line: number,
    column: number,
): void => {
    if (event.key === 'Escape') {
        event.preventDefault();
        removeLine(line);

        return;
    }

    if (event.key !== 'Enter' || event.shiftKey || event.altKey) {
        return;
    }

    event.preventDefault();

    if (event.ctrlKey || event.metaKey) {
        submit();
    } else if (column < lastColumn) {
        void focusCell(line, column + 1);
    } else if (line < lines.value.length - 1) {
        void focusCell(line + 1, 0);
    } else {
        addLine();
    }
};

const error = (line: Line, field: Field): string | undefined =>
    errors.value[`${line.key}.${field}`];

onMounted(addLine);
</script>

<template>
    <section
        class="panel flex flex-col gap-4 p-4"
        :aria-label="$t('Add several addresses')"
        data-test="quick-entry"
    >
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="font-semibold">
                {{ $t('Add several addresses') }}
            </h3>
            <p class="text-note text-muted-foreground">
                {{
                    $t(
                        'Enter or Tab: next field. Enter in the last column: new line. Escape: drop the line. Ctrl+Enter: save.',
                    )
                }}
            </p>
        </div>

        <AlertError
            v-if="generalErrors.length > 0"
            :errors="generalErrors"
            :title="$t('Nothing was saved.')"
        />
        <p
            v-else-if="Object.keys(errors).length > 0"
            class="text-sm text-muted-foreground"
            data-test="quick-entry-refused"
        >
            {{
                $t(
                    'Nothing was saved: correct the lines in error, then save again.',
                )
            }}
        </p>

        <div ref="grid" class="overflow-x-auto" data-dense>
            <table class="w-full min-w-[68rem] text-left text-table">
                <thead class="text-note text-muted-foreground">
                    <tr>
                        <th scope="col" class="w-36 px-1 pb-2 font-medium">
                            {{ $t('Address') }}
                        </th>
                        <th scope="col" class="min-w-40 px-1 pb-2 font-medium">
                            {{ $t('Host name') }}
                        </th>
                        <th scope="col" class="w-44 px-1 pb-2 font-medium">
                            {{ $t('MAC address') }}
                        </th>
                        <th scope="col" class="w-36 px-1 pb-2 font-medium">
                            {{ $t('Type') }}
                        </th>
                        <th scope="col" class="w-44 px-1 pb-2 font-medium">
                            {{ $t('Assignment') }}
                        </th>
                        <th scope="col" class="w-24 px-1 pb-2 font-medium">
                            {{ $t('Status') }}
                        </th>
                        <th scope="col" class="min-w-40 px-1 pb-2 font-medium">
                            {{ $t('Description') }}
                        </th>
                        <th scope="col" class="w-9 pb-2">
                            <span class="sr-only">{{ $t('Actions') }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(line, index) in lines"
                        :key="line.key"
                        class="align-top"
                        :data-test="`quick-entry-line-${index}`"
                    >
                        <td class="p-1">
                            <Input
                                v-model="line.address"
                                :data-line="index"
                                data-column="0"
                                autocomplete="off"
                                class="font-mono"
                                :aria-label="$t('Address')"
                                :aria-invalid="
                                    error(line, 'address') ? 'true' : undefined
                                "
                                @input="line.touched = true"
                                @keydown="onKeydown($event, index, 0)"
                            />
                            <InputError :message="error(line, 'address')" />
                        </td>
                        <td class="p-1">
                            <Input
                                v-model="line.hostname"
                                :data-line="index"
                                data-column="1"
                                autocomplete="off"
                                maxlength="253"
                                :aria-label="$t('Host name')"
                                :aria-invalid="
                                    error(line, 'hostname') ? 'true' : undefined
                                "
                                @input="line.touched = true"
                                @keydown="onKeydown($event, index, 1)"
                            />
                            <InputError :message="error(line, 'hostname')" />
                        </td>
                        <td class="p-1">
                            <Input
                                v-model="line.mac_address"
                                :data-line="index"
                                data-column="2"
                                autocomplete="off"
                                class="font-mono"
                                :aria-label="$t('MAC address')"
                                :aria-invalid="
                                    error(line, 'mac_address')
                                        ? 'true'
                                        : undefined
                                "
                                @input="line.touched = true"
                                @keydown="onKeydown($event, index, 2)"
                            />
                            <InputError :message="error(line, 'mac_address')" />
                        </td>
                        <td class="p-1">
                            <NativeSelect
                                v-model="line.device_type"
                                :data-line="index"
                                data-column="3"
                                :aria-label="$t('Type')"
                                @change="line.touched = true"
                                @keydown="onKeydown($event, index, 3)"
                                :aria-invalid="
                                    error(line, 'device_type')
                                        ? 'true'
                                        : undefined
                                "
                            >
                                <option value=""></option>
                                <option
                                    v-for="type in deviceTypes"
                                    :key="type.value"
                                    :value="type.value"
                                >
                                    {{ type.label }}
                                </option>
                            </NativeSelect>
                            <InputError :message="error(line, 'device_type')" />
                        </td>
                        <td class="p-1">
                            <NativeSelect
                                v-model="line.assignment"
                                :data-line="index"
                                data-column="4"
                                :aria-label="$t('Assignment')"
                                @change="line.touched = true"
                                @keydown="onKeydown($event, index, 4)"
                                :aria-invalid="
                                    error(line, 'assignment')
                                        ? 'true'
                                        : undefined
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
                            <InputError :message="error(line, 'assignment')" />
                        </td>
                        <td class="p-1">
                            <NativeSelect
                                v-model="line.status"
                                :data-line="index"
                                data-column="5"
                                :aria-label="$t('Status')"
                                @change="line.touched = true"
                                @keydown="onKeydown($event, index, 5)"
                                :aria-invalid="
                                    error(line, 'status') ? 'true' : undefined
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
                            <InputError :message="error(line, 'status')" />
                        </td>
                        <td class="p-1">
                            <Input
                                v-model="line.description"
                                :data-line="index"
                                data-column="6"
                                autocomplete="off"
                                maxlength="1000"
                                :aria-label="$t('Description')"
                                @input="line.touched = true"
                                @keydown="onKeydown($event, index, 6)"
                                :aria-invalid="
                                    error(line, 'description')
                                        ? 'true'
                                        : undefined
                                "
                            />
                            <InputError :message="error(line, 'description')" />
                        </td>
                        <td class="p-1">
                            <!-- Out of the tab order: Escape does the same
                                 from any field of the line. -->
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                tabindex="-1"
                                :aria-label="$t('Drop this line')"
                                @click="removeLine(index)"
                            >
                                <X aria-hidden="true" />
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <Button
                type="button"
                :disabled="saving"
                data-test="save-quick-entry"
                @click="submit"
            >
                <Spinner v-if="saving" />
                {{ $t('Save the addresses') }}
            </Button>
            <Button
                type="button"
                variant="outline"
                :disabled="!canAddLine()"
                data-test="add-quick-entry-line"
                @click="addLine"
            >
                {{ $t('Add a line') }}
            </Button>
            <Button
                type="button"
                variant="ghost"
                data-test="close-quick-entry"
                @click="emit('close')"
            >
                {{ $t('Cancel') }}
            </Button>
            <span class="ml-auto text-note text-muted-foreground">
                {{
                    $tc(
                        ':count line of :max|:count lines of :max',
                        lines.length,
                        { max: maxLines },
                    )
                }}
            </span>
        </div>
    </section>
</template>
