<!-- torsade/resources/js/components/network/JackSeriesDialog.vue -->
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
import { trans, transChoice } from '@/lib/i18n';
import {
    jackLabels,
    MAX_DIGITS,
    MAX_NUMBER,
    MAX_QUANTITY,
} from '@/lib/jackLabels';
import { store } from '@/routes/jacks/series';
import type { Option, PanelChoice } from '@/types';

// Jacks of a floor labelled as a series: a prefix, numbers from a first to a
// last one written with at least so many digits, and a suffix; and,
// optionally, the panel they are cabled to in order from one of its ports.
// The labels and their ports are shown while the series is typed; the
// server creates all of them, or none.
const props = defineProps<{
    open: boolean;
    // The floor the jacks are on, with where it is.
    floor: { id: number; path: string } | null;
    statuses: Option[];
    // The panels of the site with their free ports, asked for when the
    // window opens: they are not there before.
    panelChoices?: PanelChoice[];
}>();

const emit = defineEmits<{
    close: [];
}>();

// The longest label a jack has.
const MAX_LABEL_LENGTH = 32;

// How many ports a refusal names at most, as the server does.
const NAMED = 10;

const form = useForm<{
    prefix: string;
    suffix: string;
    // Numbers once typed: the fields are numeric inputs.
    first_number: string | number;
    last_number: string | number;
    digits: string | number;
    patch_panel_id: number | null;
    first_port: string | number;
    status: string;
}>({
    prefix: '',
    suffix: '',
    first_number: '1',
    last_number: '24',
    digits: '2',
    patch_panel_id: null,
    first_port: '1',
    status: 'active',
});

const loading = ref<boolean>(false);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        form.clearErrors();

        // The series typed before is kept: the next floor is often labelled
        // like the last one. The panel is chosen again, since it is of
        // another floor.
        form.patch_panel_id = null;
        form.first_port = '1';

        router.reload({
            only: ['panelChoices'],
            preserveUrl: true,
            onStart: () => {
                loading.value = true;
            },
            onFinish: () => {
                loading.value = false;
            },
        });
    },
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

const tooMany = computed<boolean>(
    () => quantity.value !== null && quantity.value > MAX_QUANTITY,
);

// The labels of the series, worked out as the server does: the spaces
// around the prefix and the suffix are left out.
const labels = computed<string[]>(() => {
    const first = whole(form.first_number);
    const last = whole(form.last_number);
    const digits = whole(form.digits);

    if (
        first === null ||
        last === null ||
        digits === null ||
        digits < 1 ||
        digits > MAX_DIGITS ||
        last > MAX_NUMBER ||
        quantity.value === null ||
        tooMany.value
    ) {
        return [];
    }

    return jackLabels(
        form.prefix.trim(),
        form.suffix.trim(),
        first,
        last,
        digits,
    );
});

// The last label bears the highest number: it is the longest.
const tooLong = computed<boolean>(
    () =>
        labels.value.length > 0 &&
        labels.value[labels.value.length - 1].length > MAX_LABEL_LENGTH,
);

const panel = computed<PanelChoice | null>(
    () =>
        props.panelChoices?.find(
            (choice) => choice.id === form.patch_panel_id,
        ) ?? null,
);

// What each label is cabled to: a port of the panel, a port already taken
// by another jack, or a number past the last port of the panel.
type Line = {
    label: string;
    port: number | null;
    problem: 'taken' | 'beyond' | null;
};

const lines = computed<Line[]>(() => {
    const chosen = panel.value;
    const first = whole(form.first_port);

    return labels.value.map((label, index) => {
        if (chosen === null || first === null) {
            return { label, port: null, problem: null };
        }

        const number = first + index;

        if (number > chosen.port_count) {
            return { label, port: number, problem: 'beyond' };
        }

        return {
            label,
            port: number,
            problem: chosen.free.some((port) => port.number === number)
                ? null
                : 'taken',
        };
    });
});

// What is wrong with the ports, told while the series is typed: the words
// the server uses when it refuses the same series.
const portHint = computed<string | null>(() => {
    const chosen = panel.value;

    if (chosen === null || lines.value.length === 0) {
        return null;
    }

    const last = lines.value[lines.value.length - 1];

    if (last.problem === 'beyond' && last.port !== null) {
        return trans(
            'The series would end on the port :last of :panel, past its last port, the :max.',
            { last: last.port, panel: chosen.name, max: chosen.port_count },
        );
    }

    const taken = lines.value
        .filter((line) => line.problem === 'taken')
        .map((line) => String(line.port));

    if (taken.length === 0) {
        return null;
    }

    const shown = taken.slice(0, NAMED).join(', ');
    const named =
        taken.length > NAMED
            ? transChoice(':items and :count more', taken.length - NAMED, {
                  items: shown,
              })
            : shown;

    return transChoice(
        'The port :ports of :panel already has a jack.|The ports :ports of :panel already have a jack.',
        taken.length,
        { ports: named, panel: chosen.name },
    );
});

const choosePanel = (value: number | null): void => {
    form.patch_panel_id = value;

    // The first free port of the panel, where a series most often starts.
    const free = props.panelChoices?.find((choice) => choice.id === value)
        ?.free[0];

    form.first_port = String(free?.number ?? 1);
};

const submit = (): void => {
    if (!props.floor) {
        return;
    }

    form.transform((data) => ({
        ...data,
        prefix: data.prefix.trim(),
        suffix: data.suffix.trim(),
        first_port: data.patch_panel_id === null ? null : data.first_port,
    })).post(store(props.floor.id).url, {
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
        <DialogContent class="sm:max-w-2xl" data-test="jack-series-dialog">
            <form class="grid gap-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ $t('Add jacks as a series') }}</DialogTitle>
                    <DialogDescription v-if="floor">{{
                        $t(
                            'Wall jacks on :floor, labelled from a first number to a last one.',
                            { floor: floor.path },
                        )
                    }}</DialogDescription>
                </DialogHeader>

                <div
                    class="grid grid-cols-2 gap-x-4 gap-y-5 sm:grid-cols-[1fr_1fr_7.5rem_7.5rem_5rem]"
                >
                    <div class="grid content-start gap-2">
                        <Label for="jacks-prefix">{{
                            $t('Start of the label')
                        }}</Label>
                        <Input
                            id="jacks-prefix"
                            v-model="form.prefix"
                            name="prefix"
                            maxlength="31"
                            autocomplete="off"
                            spellcheck="false"
                            class="font-mono"
                            data-test="jacks-prefix"
                            :aria-invalid="
                                tooLong || form.errors.prefix
                                    ? 'true'
                                    : undefined
                            "
                        />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="jacks-suffix">{{
                            $t('End of the label')
                        }}</Label>
                        <Input
                            id="jacks-suffix"
                            v-model="form.suffix"
                            name="suffix"
                            maxlength="31"
                            autocomplete="off"
                            spellcheck="false"
                            class="font-mono"
                            :aria-invalid="
                                form.errors.suffix ? 'true' : undefined
                            "
                        />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="jacks-first">{{
                            $t('First number')
                        }}</Label>
                        <Input
                            id="jacks-first"
                            v-model="form.first_number"
                            type="number"
                            name="first_number"
                            required
                            min="0"
                            :max="MAX_NUMBER"
                            step="1"
                            class="font-mono"
                            data-test="jacks-first"
                            :aria-invalid="
                                form.errors.first_number ? 'true' : undefined
                            "
                        />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="jacks-last">{{ $t('Last number') }}</Label>
                        <Input
                            id="jacks-last"
                            v-model="form.last_number"
                            type="number"
                            name="last_number"
                            required
                            min="0"
                            :max="MAX_NUMBER"
                            step="1"
                            class="font-mono"
                            data-test="jacks-last"
                            :aria-invalid="
                                tooMany || form.errors.last_number
                                    ? 'true'
                                    : undefined
                            "
                        />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="jacks-digits">{{ $t('Digits') }}</Label>
                        <Input
                            id="jacks-digits"
                            v-model="form.digits"
                            type="number"
                            name="digits"
                            required
                            min="1"
                            :max="MAX_DIGITS"
                            step="1"
                            class="font-mono"
                            :aria-invalid="
                                form.errors.digits ? 'true' : undefined
                            "
                        />
                    </div>
                </div>

                <div class="-mt-3 grid gap-1.5">
                    <p
                        v-if="tooLong"
                        class="text-sm text-error"
                        data-test="jacks-too-long"
                    >
                        {{
                            $tc(
                                'The labels of this series are longer than :count character.|The labels of this series are longer than :count characters.',
                                MAX_LABEL_LENGTH,
                            )
                        }}
                    </p>
                    <InputError v-else :message="form.errors.prefix" />
                    <InputError :message="form.errors.suffix" />
                    <InputError :message="form.errors.first_number" />
                    <p
                        v-if="tooMany"
                        class="text-sm text-error"
                        data-test="jacks-too-many"
                    >
                        {{
                            $tc(
                                'A series holds :count jack at most.|A series holds :count jacks at most.',
                                MAX_QUANTITY,
                            )
                        }}
                    </p>
                    <InputError v-else :message="form.errors.last_number" />
                    <InputError :message="form.errors.digits" />
                    <p class="text-sm text-muted-foreground">
                        {{
                            $t(
                                'The number is written with at least so many digits, zeros in front: B. and 2 digits give B.01, B.02…',
                            )
                        }}
                    </p>
                </div>

                <div class="grid gap-x-4 gap-y-5 sm:grid-cols-[1fr_8rem_10rem]">
                    <div class="grid content-start gap-2">
                        <Label for="jacks-panel">{{ $t('Patch panel') }}</Label>
                        <NativeSelect
                            id="jacks-panel"
                            :model-value="form.patch_panel_id"
                            name="patch_panel_id"
                            data-test="jacks-panel"
                            :aria-invalid="
                                form.errors.patch_panel_id ? 'true' : undefined
                            "
                            @update:model-value="
                                (value) =>
                                    choosePanel(
                                        typeof value === 'number'
                                            ? value
                                            : null,
                                    )
                            "
                        >
                            <option :value="null">
                                {{ $t('Not cabled to a panel') }}
                            </option>
                            <option
                                v-for="choice in panelChoices ?? []"
                                :key="choice.id"
                                :value="choice.id"
                            >
                                {{ choice.name }} — {{ choice.place }}
                            </option>
                        </NativeSelect>
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="jacks-first-port">{{
                            $t('First port')
                        }}</Label>
                        <Input
                            id="jacks-first-port"
                            v-model="form.first_port"
                            type="number"
                            name="first_port"
                            min="1"
                            :max="panel?.port_count ?? 96"
                            step="1"
                            class="font-mono"
                            data-test="jacks-first-port"
                            :disabled="form.patch_panel_id === null"
                            :aria-invalid="
                                portHint || form.errors.first_port
                                    ? 'true'
                                    : undefined
                            "
                        />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="jacks-status">{{ $t('Status') }}</Label>
                        <NativeSelect
                            id="jacks-status"
                            v-model="form.status"
                            name="status"
                            :aria-invalid="
                                form.errors.status ? 'true' : undefined
                            "
                        >
                            <option
                                v-for="option in statuses"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </NativeSelect>
                    </div>
                </div>

                <div class="-mt-3 grid gap-1.5">
                    <InputError :message="form.errors.patch_panel_id" />
                    <p
                        v-if="portHint"
                        class="text-sm text-error"
                        data-test="jacks-port-hint"
                    >
                        {{ portHint }}
                    </p>
                    <InputError v-else :message="form.errors.first_port" />
                    <InputError :message="form.errors.status" />
                    <p
                        v-if="loading"
                        class="text-sm text-muted-foreground"
                        role="status"
                    >
                        {{ $t('Loading the panels…') }}
                    </p>
                    <p v-else class="text-sm text-muted-foreground">
                        {{
                            $t(
                                'Optional. The jacks are cabled in order to the ports of the panel, from the first port.',
                            )
                        }}
                    </p>
                </div>

                <!-- Always there, so that a screen reader is told when the
                     labels change. -->
                <div
                    class="grid gap-1.5 rounded-md bg-sunken px-3 py-2 text-sm"
                    role="status"
                    data-test="jacks-preview"
                >
                    <span class="text-muted-foreground">{{
                        $t('Jacks created')
                    }}</span>
                    <template v-if="lines.length > 0 && !tooLong">
                        <ul
                            class="grid max-h-48 grid-cols-[repeat(auto-fill,minmax(9rem,1fr))] gap-x-4 gap-y-0.5 overflow-y-auto font-mono"
                        >
                            <li
                                v-for="line in lines"
                                :key="line.label"
                                :class="line.problem ? 'text-error' : ''"
                                data-test="jacks-preview-line"
                            >
                                {{ line.label
                                }}<template v-if="line.port !== null">
                                    → {{ line.port }}</template
                                >
                            </li>
                        </ul>
                        <span class="font-medium">{{
                            panel
                                ? $tc(
                                      ':count jack, cabled to :panel|:count jacks, cabled to :panel',
                                      lines.length,
                                      { panel: panel.name },
                                  )
                                : $tc(':count jack|:count jacks', lines.length)
                        }}</span>
                    </template>
                    <span v-else class="text-muted-foreground">{{
                        $t('No jack yet.')
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
                        data-test="save-jack-series"
                    >
                        <Spinner v-if="form.processing" />
                        {{ $t('Save') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
