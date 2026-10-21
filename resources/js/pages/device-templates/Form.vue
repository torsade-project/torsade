<!-- torsade/resources/js/pages/device-templates/Form.vue -->
<script setup lang="ts">
import { Head, Link, setLayoutProps, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Plus, Trash2 } from '@lucide/vue';
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
import { groupClashes, patternProblem } from '@/lib/portNaming';
import type { NamedGroup } from '@/lib/portNaming';
import { namesInWords, patternMessage } from '@/lib/portPreview';
import { create, edit, index, store, update } from '@/routes/device-templates';
import type { DeviceTemplate, Option, TemplateLimits } from '@/types';

// One page for a new template and for an existing one: the template, and
// its groups of ports as lines that can be added, removed and put in
// order. The names the ports get are shown while the lines are typed.
const props = defineProps<{
    template: DeviceTemplate | null;
    types: Option[];
    connectors: Option[];
    speeds: Option[];
    poeTypes: Option[];
    limits: TemplateLimits;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: trans('Device templates'), href: index() },
        props.template
            ? { title: props.template.name, href: edit(props.template.id) }
            : { title: trans('New template'), href: create() },
    ],
});

type GroupLine = {
    // Stable across reordering: the lines are moved, not rewritten.
    key: number;
    // Numbers once typed: the fields are numeric inputs.
    quantity: string | number;
    name_pattern: string;
    first_number: string | number;
    connector: string;
    max_speed: string;
    poe_type: string;
    poe_label: string;
};

let nextKey = 0;

const form = useForm<{
    name: string;
    device_type: string;
    manufacturer: string;
    model: string;
    poe_budget_watts: string | number;
    description: string;
    port_groups: GroupLine[];
}>({
    name: props.template?.name ?? '',
    device_type: props.template?.device_type ?? '',
    manufacturer: props.template?.manufacturer ?? '',
    model: props.template?.model ?? '',
    poe_budget_watts:
        props.template?.poe_budget_watts === null ||
        props.template?.poe_budget_watts === undefined
            ? ''
            : String(props.template.poe_budget_watts),
    description: props.template?.description ?? '',
    port_groups: (props.template?.port_groups ?? []).map((group) => ({
        key: nextKey++,
        quantity: String(group.quantity),
        name_pattern: group.name_pattern,
        first_number: String(group.first_number),
        connector: group.connector,
        max_speed: group.max_speed,
        poe_type: group.poe_type,
        poe_label: group.poe_label ?? '',
    })),
});

// How a pattern names ports. The sentence quotes patterns: it is kept out
// of the template, where its quotation marks would end an attribute.
const groupsHelp = trans(
    'Ports that are alike, named after a pattern: {m} is the number of the member of the device, {n} the number of the port. "{m}/0/{n}" gives 1/0/1, 1/0/2… on the first member, "port{n}" gives port1, port2…',
);

// --- The lines -------------------------------------------------------------

const whole = (value: string | number): number | null => {
    const text = String(value).trim();

    return /^\d+$/.test(text) ? Number(text) : null;
};

const addGroup = (): void => {
    const last = form.port_groups[form.port_groups.length - 1];
    const quantity = last ? whole(last.quantity) : null;
    const first = last ? whole(last.first_number) : null;

    // A new line starts like the one before it, since the groups of a
    // machine are often alike, and where that one ends: its names do not
    // repeat those of the line it was copied from.
    form.port_groups.push({
        key: nextKey++,
        quantity: last ? last.quantity : '24',
        name_pattern: last ? last.name_pattern : '{m}/0/{n}',
        first_number:
            quantity !== null && first !== null
                ? String(Math.min(first + quantity, props.limits.first_number))
                : '1',
        connector: last ? last.connector : 'rj45',
        max_speed: last ? last.max_speed : '1g',
        poe_type: last ? last.poe_type : 'none',
        poe_label: '',
    });
};

const removeGroup = (place: number): void => {
    form.port_groups.splice(place, 1);
    form.clearErrors();
};

const moveGroup = (place: number, by: -1 | 1): void => {
    const target = place + by;

    if (target < 0 || target >= form.port_groups.length) {
        return;
    }

    const [line] = form.port_groups.splice(place, 1);

    form.port_groups.splice(target, 0, line);
    // The messages of the server name lines by their place: they no longer
    // match once a line has moved.
    form.clearErrors();
};

// --- What the lines give ---------------------------------------------------

// The lines as numbers, where they are complete.
const named = computed<(NamedGroup | null)[]>(() =>
    form.port_groups.map((line) => {
        const quantity = whole(line.quantity);
        const first = whole(line.first_number);

        return quantity === null ||
            first === null ||
            quantity < 1 ||
            quantity > props.limits.quantity ||
            patternProblem(line.name_pattern) !== null
            ? null
            : {
                  name_pattern: line.name_pattern,
                  first_number: first,
                  quantity,
              };
    }),
);

// The names are only compared between lines that can all name ports.
const clashes = computed(() =>
    named.value.every((group) => group !== null)
        ? groupClashes(named.value as NamedGroup[])
        : {},
);

// What is wrong with a line, told while it is typed: the words the server
// uses when it refuses the same line.
const hintOf = (place: number): string | null => {
    const line = form.port_groups[place];

    if (line.name_pattern.trim() === '') {
        return null;
    }

    const message = patternMessage(line.name_pattern);

    if (message !== null) {
        return message;
    }

    const clash = clashes.value[place];

    return clash
        ? trans(
              'This group gives the name :name, which the group :number already gives.',
              { name: clash.name, number: clash.with + 1 },
          )
        : null;
};

const preview = computed<string>(() =>
    namesInWords(
        named.value.filter((group): group is NamedGroup => group !== null),
    ),
);

const total = computed<number>(() =>
    named.value.reduce((sum, group) => sum + (group?.quantity ?? 0), 0),
);

// --- Saving ----------------------------------------------------------------

const errorOf = (key: string): string | undefined =>
    (form.errors as Record<string, string | undefined>)[key];

const GROUP_FIELDS = [
    'quantity',
    'name_pattern',
    'first_number',
    'connector',
    'max_speed',
    'poe_type',
    'poe_label',
] as const;

const submit = (): void => {
    form.transform((data) => ({
        ...data,
        poe_budget_watts:
            String(data.poe_budget_watts).trim() === ''
                ? null
                : data.poe_budget_watts,
        port_groups: data.port_groups.map((line) => ({
            quantity: line.quantity,
            name_pattern: line.name_pattern.trim(),
            first_number: line.first_number,
            connector: line.connector,
            max_speed: line.max_speed,
            poe_type: line.poe_type,
            poe_label: line.poe_label.trim() === '' ? null : line.poe_label,
        })),
    }));

    if (props.template) {
        form.put(update(props.template.id).url, { preserveScroll: true });
    } else {
        form.post(store().url, { preserveScroll: true });
    }
};
</script>

<template>
    <Head :title="template ? $t('Edit the template') : $t('New template')" />

    <div class="flex max-w-5xl flex-col gap-7">
        <Heading
            :title="template ? $t('Edit the template') : $t('New template')"
            :description="
                $t(
                    'A kind of machine and its ports. Changing a template changes no device: the ports of a member were copied when the member was added.',
                )
            "
        />

        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <div class="panel flex flex-col gap-5 p-5 sm:p-6">
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
                            :aria-invalid="
                                form.errors.name ? 'true' : undefined
                            "
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid content-start gap-2">
                        <Label for="device_type">{{ $t('Type') }}</Label>
                        <NativeSelect
                            id="device_type"
                            v-model="form.device_type"
                            name="device_type"
                            required
                            :aria-invalid="
                                form.errors.device_type ? 'true' : undefined
                            "
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
                        <InputError :message="form.errors.device_type" />
                    </div>
                </div>

                <div class="grid gap-x-5 gap-y-5 sm:grid-cols-3">
                    <div class="grid content-start gap-2">
                        <Label for="manufacturer">{{
                            $t('Manufacturer')
                        }}</Label>
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
                            :aria-invalid="
                                form.errors.model ? 'true' : undefined
                            "
                        />
                        <InputError :message="form.errors.model" />
                    </div>

                    <div class="grid content-start gap-2">
                        <Label for="poe_budget_watts">{{
                            $t('PoE budget (W)')
                        }}</Label>
                        <Input
                            id="poe_budget_watts"
                            v-model="form.poe_budget_watts"
                            type="number"
                            name="poe_budget_watts"
                            min="1"
                            step="1"
                            class="font-mono"
                            aria-describedby="poe-budget-help"
                            :aria-invalid="
                                form.errors.poe_budget_watts
                                    ? 'true'
                                    : undefined
                            "
                        />
                        <InputError :message="form.errors.poe_budget_watts" />
                        <p
                            id="poe-budget-help"
                            class="text-sm text-muted-foreground"
                        >
                            {{
                                $t(
                                    'Optional. What the machine can give to everything it powers.',
                                )
                            }}
                        </p>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="description">{{ $t('Description') }}</Label>
                    <Textarea
                        id="description"
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
            </div>

            <section
                class="panel flex flex-col gap-4 p-5 sm:p-6"
                :aria-label="$t('Groups of ports')"
                data-test="template-groups"
            >
                <Heading
                    variant="small"
                    :title="$t('Groups of ports')"
                    :description="groupsHelp"
                />

                <p
                    v-if="form.port_groups.length === 0"
                    class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
                >
                    {{ $t('No group: the template has no port.') }}
                </p>

                <ul v-else class="flex flex-col gap-3">
                    <li
                        v-for="(line, place) in form.port_groups"
                        :key="line.key"
                        class="flex flex-col gap-2 rounded-md border p-3"
                        :data-test="`template-group-${place}`"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm font-medium">{{
                                $t('Group :number', { number: place + 1 })
                            }}</span>
                            <div class="flex gap-1">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    :disabled="place === 0"
                                    :aria-label="
                                        $t('Move the group :number up', {
                                            number: place + 1,
                                        })
                                    "
                                    @click="moveGroup(place, -1)"
                                >
                                    <ArrowUp aria-hidden="true" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    :disabled="
                                        place === form.port_groups.length - 1
                                    "
                                    :aria-label="
                                        $t('Move the group :number down', {
                                            number: place + 1,
                                        })
                                    "
                                    @click="moveGroup(place, 1)"
                                >
                                    <ArrowDown aria-hidden="true" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    :aria-label="
                                        $t('Remove the group :number', {
                                            number: place + 1,
                                        })
                                    "
                                    @click="removeGroup(place)"
                                >
                                    <Trash2 aria-hidden="true" />
                                </Button>
                            </div>
                        </div>

                        <!-- One row on a wide screen, two on a laptop. -->
                        <div
                            class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-[4.5rem_minmax(7.5rem,1fr)_6.5rem_7rem_8.5rem_10rem_minmax(6rem,1fr)]"
                        >
                            <div class="grid gap-1.5">
                                <Label :for="`group-quantity-${line.key}`">{{
                                    $t('Quantity')
                                }}</Label>
                                <Input
                                    :id="`group-quantity-${line.key}`"
                                    v-model="line.quantity"
                                    type="number"
                                    required
                                    min="1"
                                    :max="limits.quantity"
                                    step="1"
                                    class="font-mono"
                                    :aria-invalid="
                                        errorOf(`port_groups.${place}.quantity`)
                                            ? 'true'
                                            : undefined
                                    "
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label :for="`group-pattern-${line.key}`">{{
                                    $t('Pattern')
                                }}</Label>
                                <Input
                                    :id="`group-pattern-${line.key}`"
                                    v-model="line.name_pattern"
                                    required
                                    :maxlength="limits.pattern"
                                    autocomplete="off"
                                    spellcheck="false"
                                    class="font-mono"
                                    :aria-invalid="
                                        hintOf(place) ||
                                        errorOf(
                                            `port_groups.${place}.name_pattern`,
                                        )
                                            ? 'true'
                                            : undefined
                                    "
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label :for="`group-first-${line.key}`">{{
                                    $t('First number')
                                }}</Label>
                                <Input
                                    :id="`group-first-${line.key}`"
                                    v-model="line.first_number"
                                    type="number"
                                    required
                                    min="0"
                                    :max="limits.first_number"
                                    step="1"
                                    class="font-mono"
                                    :aria-invalid="
                                        errorOf(
                                            `port_groups.${place}.first_number`,
                                        )
                                            ? 'true'
                                            : undefined
                                    "
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label :for="`group-connector-${line.key}`">{{
                                    $t('Connector')
                                }}</Label>
                                <NativeSelect
                                    :id="`group-connector-${line.key}`"
                                    v-model="line.connector"
                                    :aria-invalid="
                                        errorOf(
                                            `port_groups.${place}.connector`,
                                        )
                                            ? 'true'
                                            : undefined
                                    "
                                >
                                    <option
                                        v-for="option in connectors"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option>
                                </NativeSelect>
                            </div>
                            <div class="grid gap-1.5">
                                <Label :for="`group-speed-${line.key}`">{{
                                    $t('Speed')
                                }}</Label>
                                <NativeSelect
                                    :id="`group-speed-${line.key}`"
                                    v-model="line.max_speed"
                                    :aria-invalid="
                                        errorOf(
                                            `port_groups.${place}.max_speed`,
                                        )
                                            ? 'true'
                                            : undefined
                                    "
                                >
                                    <option
                                        v-for="option in speeds"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option>
                                </NativeSelect>
                            </div>
                            <div class="grid gap-1.5">
                                <Label :for="`group-poe-${line.key}`">{{
                                    $t('PoE')
                                }}</Label>
                                <NativeSelect
                                    :id="`group-poe-${line.key}`"
                                    v-model="line.poe_type"
                                    :aria-invalid="
                                        errorOf(`port_groups.${place}.poe_type`)
                                            ? 'true'
                                            : undefined
                                    "
                                >
                                    <option
                                        v-for="option in poeTypes"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option>
                                </NativeSelect>
                            </div>
                            <div class="grid gap-1.5">
                                <Label :for="`group-poe-label-${line.key}`">{{
                                    $t('PoE label')
                                }}</Label>
                                <Input
                                    :id="`group-poe-label-${line.key}`"
                                    v-model="line.poe_label"
                                    maxlength="32"
                                    autocomplete="off"
                                    :aria-invalid="
                                        errorOf(
                                            `port_groups.${place}.poe_label`,
                                        )
                                            ? 'true'
                                            : undefined
                                    "
                                />
                            </div>
                        </div>

                        <!-- What is wrong with the line, as it is typed. -->
                        <p
                            v-if="hintOf(place)"
                            class="text-sm text-error"
                            :data-test="`template-group-hint-${place}`"
                        >
                            {{ hintOf(place) }}
                        </p>

                        <InputError
                            v-for="field in GROUP_FIELDS"
                            :key="field"
                            :message="
                                field === 'name_pattern' && hintOf(place)
                                    ? undefined
                                    : errorOf(`port_groups.${place}.${field}`)
                            "
                        />
                    </li>
                </ul>

                <InputError :message="errorOf('port_groups')" />

                <div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="form.port_groups.length >= limits.groups"
                        data-test="add-template-group"
                        @click="addGroup"
                    >
                        <Plus aria-hidden="true" />
                        {{ $t('Add a group') }}
                    </Button>
                </div>

                <!-- Always there, so that a screen reader is told when the
                     names change. -->
                <div
                    class="rounded-md bg-sunken px-4 py-3"
                    role="status"
                    data-test="template-preview"
                >
                    <p class="text-sm text-muted-foreground">
                        {{ $t('Names on the member 1') }}
                    </p>
                    <p v-if="preview" class="font-mono text-sm">
                        {{ preview }}
                    </p>
                    <p v-else class="text-sm text-muted-foreground">
                        {{ $t('No port yet.') }}
                    </p>
                    <p class="mt-1 text-sm font-medium">
                        {{ $tc(':count port|:count ports', total) }}
                    </p>
                </div>
            </section>

            <div class="flex flex-wrap items-center gap-2.5">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    data-test="save-template"
                >
                    <Spinner v-if="form.processing" />
                    {{ $t('Save') }}
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="index()">{{ $t('Cancel') }}</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
