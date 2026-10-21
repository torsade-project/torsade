<!-- torsade/resources/js/pages/sites/Standard.vue -->
<script setup lang="ts">
import { Head, Link, setLayoutProps, useForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import RangeKindLabel from '@/components/network/RangeKindLabel.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useSubnetProposal } from '@/composables/useSubnetProposal';
import { breakdownFits, breakdownOf } from '@/lib/addressingRule';
import type { AddressingRuleParams, PlacedRange } from '@/lib/addressingRule';
import { trans } from '@/lib/i18n';
import { index, show } from '@/routes/sites';
import { create, store } from '@/routes/sites/standard';
import type { CatalogOption, NumberedSite, Option, StatusValue } from '@/types';

// The standard of the organisation applied to a site in one action: each
// entry of the catalogue the site does not follow yet is a line, ready to
// become a VLAN of the site with its subnet. Everything ticked is created,
// or nothing is.
const props = defineProps<{
    site: NumberedSite;
    // The entries of the catalogue no VLAN of the site follows yet.
    entries: CatalogOption[];
    // What the addressing rule works out, or null: the subnets are then
    // typed by hand, or not created.
    rule: AddressingRuleParams | null;
    statuses: Option[];
    rangeKinds: Option[];
    defaultStatus: StatusValue;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: trans('Sites'), href: index() },
        { title: props.site.name, href: show(props.site.id) },
        { title: trans('Apply the standard'), href: create(props.site.id) },
    ],
});

// Whether the addressing rule has ranges to cut a subnet into.
const hasBreakdown = (props.rule?.ranges.length ?? 0) > 0;

type LineFields = {
    selected: boolean;
    // A number once typed: the field is a numeric input.
    vid: string | number;
    name: string;
    with_subnet: boolean;
    // The subnet in full, as "10.30.10.0/24".
    subnet: string;
    gateway: string;
    apply_breakdown: boolean;
};

// One line per entry. The number and the name come from the entry and stay
// free; the subnet and the gateway come from the addressing rule and follow
// the number as long as they hold what was proposed.
const lines = props.entries.map((entry) => {
    const fields = reactive<LineFields>({
        selected: true,
        vid: String(entry.vid),
        name: entry.name,
        with_subnet: false,
        subnet: '',
        gateway: '',
        apply_breakdown: hasBreakdown,
    });

    const proposal = useSubnetProposal({
        rule: () => props.rule,
        site: () => props.site,
        vid: () => {
            const text = String(fields.vid).trim();

            return /^\d{1,4}$/.test(text) ? Number(text) : null;
        },
        // The subnet is one field here: its two halves are read from it
        // and written back together.
        read: () => {
            const [address, length] = fields.subnet.trim().split('/', 2);

            return {
                network: address,
                prefix_length:
                    length !== undefined && /^\d{1,2}$/.test(length)
                        ? Number(length)
                        : null,
                gateway: fields.gateway,
            };
        },
        write: (values) => {
            if (values.network !== undefined) {
                fields.subnet =
                    values.network === ''
                        ? ''
                        : `${values.network}/${values.prefix_length}`;
            }

            if (values.gateway !== undefined) {
                fields.gateway = values.gateway;
            }
        },
        defaultPrefix: props.rule?.vlan_prefix_length ?? 24,
    });

    // The subnet is created by default when the rule proposes one.
    fields.with_subnet = fields.subnet !== '';

    // The ranges the standard breakdown would create in the subnet typed,
    // worked out as the forms do, again as soon as the subnet is
    // corrected; null while it is not a subnet.
    const ranges = computed<PlacedRange[] | null>(() => {
        const [address, length] = fields.subnet.trim().split('/', 2);
        const prefixLength =
            length !== undefined && /^\d{1,2}$/.test(length)
                ? Number(length)
                : null;

        if (props.rule === null || !hasBreakdown || prefixLength === null) {
            return null;
        }

        const placed = breakdownOf(props.rule, address, prefixLength);

        return placed.length > 0 ? placed : null;
    });

    // Whether the breakdown can be asked for: it has ranges, and they all
    // fit in the subnet. The server checks again.
    const breakdownApplies = computed<boolean>(() => {
        const [address, length] = fields.subnet.trim().split('/', 2);

        return (
            props.rule !== null &&
            ranges.value !== null &&
            breakdownFits(props.rule, address, Number(length))
        );
    });

    return { entry, fields, ranges, breakdownApplies, ...proposal };
});

type Line = (typeof lines)[number];

const form = useForm<{ status: StatusValue }>({
    status: props.defaultStatus,
});

const selected = computed<Line[]>(() =>
    lines.filter((line) => line.fields.selected),
);

const allSelected = computed<boolean>(
    () => lines.length > 0 && selected.value.length === lines.length,
);

const toggleAll = (): void => {
    const next = !allSelected.value;

    for (const line of lines) {
        line.fields.selected = next;
    }
};

// The entries as they were sent: the server tells each refusal by the place
// of its line in what it received.
let sent: number[] = [];

const errorOf = (line: Line, ...fields: string[]): string | undefined => {
    const place = sent.indexOf(line.entry.id);

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

// Under the subnet of a line: where it comes from, or why none is proposed.
const subnetHint = (line: Line): string | null =>
    line.addressIsProposed.value
        ? trans('Proposed by the addressing rule')
        : line.reason.value;

const submit = (): void => {
    const chosen = selected.value;

    sent = chosen.map((line) => line.entry.id);

    form.transform((data) => ({
        ...data,
        lines: chosen.map((line) => ({
            catalog_vlan_id: line.entry.id,
            vid: line.fields.vid,
            name: line.fields.name,
            with_subnet: line.fields.with_subnet,
            network: line.fields.with_subnet ? line.fields.subnet.trim() : null,
            gateway:
                line.fields.with_subnet && line.fields.gateway.trim() !== ''
                    ? line.fields.gateway.trim()
                    : null,
            // Only the wish is sent: the server works the ranges out.
            apply_breakdown:
                line.breakdownApplies.value &&
                line.fields.with_subnet &&
                line.fields.apply_breakdown,
        })),
    })).post(store(props.site.id).url, { preserveScroll: true });
};
</script>

<template>
    <Head :title="$t('Apply the standard to :site', { site: site.name })" />

    <div class="flex max-w-4xl flex-col gap-7">
        <Heading
            :title="$t('Apply the standard to :site', { site: site.name })"
            :description="
                $t(
                    'Each entry of the catalogue this site does not follow yet can become one of its VLANs, with its subnet. What is ticked is created together, or nothing is.',
                )
            "
        />

        <div
            v-if="entries.length === 0"
            class="flex flex-col items-start gap-4 rounded-lg border border-dashed p-5"
            data-test="standard-empty"
        >
            <p class="text-muted-foreground">
                {{
                    $t(
                        'Nothing is left to apply: this site follows every entry of the catalogue, or the catalogue is empty.',
                    )
                }}
            </p>
            <Button variant="outline" as-child>
                <Link :href="show(site.id)">{{ $t('Back to the site') }}</Link>
            </Button>
        </div>

        <form v-else class="flex flex-col gap-5" @submit.prevent="submit">
            <p
                v-if="!rule"
                class="max-w-prose text-sm text-muted-foreground"
                data-test="standard-no-rule"
            >
                {{
                    $t(
                        'There is no addressing rule: no subnet is proposed. Type them, or create the VLANs alone.',
                    )
                }}
            </p>

            <div class="panel flex flex-wrap items-end gap-5 p-5">
                <div class="grid gap-2 sm:w-56">
                    <Label for="standard-status">{{
                        $t('Status of what is created')
                    }}</Label>
                    <NativeSelect
                        id="standard-status"
                        v-model="form.status"
                        name="status"
                        :aria-invalid="form.errors.status ? 'true' : undefined"
                    >
                        <option
                            v-for="status in statuses"
                            :key="status.value"
                            :value="status.value"
                        >
                            {{ status.label }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.status" />
                </div>
                <Button type="button" variant="outline" @click="toggleAll">
                    {{ allSelected ? $t('Untick all') : $t('Tick all') }}
                </Button>
            </div>

            <ul class="flex flex-col gap-3">
                <li
                    v-for="line in lines"
                    :key="line.entry.id"
                    class="panel flex flex-col gap-4 p-4 sm:p-5"
                    :data-test="`standard-line-${line.entry.vid}`"
                >
                    <label class="flex items-center gap-2.5">
                        <input
                            v-model="line.fields.selected"
                            type="checkbox"
                            class="size-4.5 shrink-0 rounded-xs accent-primary"
                        />
                        <span class="font-medium">
                            <span class="font-mono text-sm">{{
                                line.entry.vid
                            }}</span>
                            — {{ line.entry.name }}
                        </span>
                    </label>
                    <InputError :message="errorOf(line, 'catalog_vlan_id')" />

                    <template v-if="line.fields.selected">
                        <div class="grid gap-4 sm:grid-cols-[8rem_1fr]">
                            <div class="grid content-start gap-2">
                                <Label :for="`standard-vid-${line.entry.id}`">{{
                                    $t('VID')
                                }}</Label>
                                <Input
                                    :id="`standard-vid-${line.entry.id}`"
                                    v-model="line.fields.vid"
                                    type="number"
                                    required
                                    min="1"
                                    max="4094"
                                    step="1"
                                    class="font-mono"
                                    :aria-invalid="
                                        errorOf(line, 'vid')
                                            ? 'true'
                                            : undefined
                                    "
                                />
                            </div>
                            <div class="grid content-start gap-2">
                                <Label
                                    :for="`standard-name-${line.entry.id}`"
                                    >{{ $t('Name') }}</Label
                                >
                                <Input
                                    :id="`standard-name-${line.entry.id}`"
                                    v-model="line.fields.name"
                                    required
                                    maxlength="64"
                                    autocomplete="off"
                                    :aria-invalid="
                                        errorOf(line, 'name')
                                            ? 'true'
                                            : undefined
                                    "
                                />
                            </div>
                        </div>
                        <InputError :message="errorOf(line, 'vid')" />
                        <InputError :message="errorOf(line, 'name')" />

                        <label class="flex items-center gap-2.5">
                            <input
                                v-model="line.fields.with_subnet"
                                type="checkbox"
                                class="size-4.5 shrink-0 rounded-xs accent-primary"
                            />
                            <span>{{ $t('Create the subnet') }}</span>
                        </label>

                        <template v-if="line.fields.with_subnet">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="grid content-start gap-2">
                                    <Label
                                        :for="`standard-subnet-${line.entry.id}`"
                                        >{{ $t('Subnet') }}</Label
                                    >
                                    <Input
                                        :id="`standard-subnet-${line.entry.id}`"
                                        v-model="line.fields.subnet"
                                        required
                                        autocomplete="off"
                                        class="font-mono"
                                        placeholder="192.168.100.0/24"
                                        :aria-invalid="
                                            errorOf(
                                                line,
                                                'network',
                                                'prefix_length',
                                            )
                                                ? 'true'
                                                : undefined
                                        "
                                    />
                                    <InputError
                                        :message="
                                            errorOf(
                                                line,
                                                'network',
                                                'prefix_length',
                                            )
                                        "
                                    />
                                    <!-- Where the subnet comes from, or why
                                         the rule proposes none. -->
                                    <p
                                        v-if="subnetHint(line)"
                                        class="text-sm text-muted-foreground"
                                        data-test="network-hint"
                                    >
                                        {{ subnetHint(line) }}
                                    </p>
                                </div>
                                <div class="grid content-start gap-2">
                                    <Label
                                        :for="`standard-gateway-${line.entry.id}`"
                                        >{{ $t('Gateway') }}</Label
                                    >
                                    <Input
                                        :id="`standard-gateway-${line.entry.id}`"
                                        v-model="line.fields.gateway"
                                        autocomplete="off"
                                        class="font-mono"
                                        :aria-invalid="
                                            errorOf(line, 'gateway')
                                                ? 'true'
                                                : undefined
                                        "
                                    />
                                    <InputError
                                        :message="errorOf(line, 'gateway')"
                                    />
                                    <p
                                        v-if="line.gatewayIsProposed.value"
                                        class="text-sm text-muted-foreground"
                                        data-test="gateway-hint"
                                    >
                                        {{
                                            $t(
                                                'Proposed by the addressing rule',
                                            )
                                        }}
                                    </p>
                                </div>
                            </div>

                            <div
                                class="flex flex-col gap-2"
                                data-test="standard-breakdown"
                            >
                                <!-- Inactive, and left unticked, when there
                                     is nothing to create or it does not fit. -->
                                <label
                                    class="flex items-center gap-2.5"
                                    :class="{
                                        'text-muted-foreground':
                                            !line.breakdownApplies.value,
                                    }"
                                >
                                    <input
                                        type="checkbox"
                                        class="size-4.5 shrink-0 rounded-xs accent-primary"
                                        :checked="
                                            line.breakdownApplies.value &&
                                            line.fields.apply_breakdown
                                        "
                                        :disabled="!line.breakdownApplies.value"
                                        :aria-invalid="
                                            errorOf(line, 'apply_breakdown')
                                                ? 'true'
                                                : undefined
                                        "
                                        @change="
                                            line.fields.apply_breakdown = (
                                                $event.target as HTMLInputElement
                                            ).checked
                                        "
                                    />
                                    <span>{{ $t('Standard breakdown') }}</span>
                                </label>
                                <InputError
                                    :message="errorOf(line, 'apply_breakdown')"
                                />

                                <p
                                    v-if="!hasBreakdown"
                                    class="text-sm text-muted-foreground"
                                    data-test="breakdown-hint"
                                >
                                    {{
                                        $t(
                                            'The addressing rule has no standard breakdown: no range is created.',
                                        )
                                    }}
                                </p>
                                <p
                                    v-else-if="line.ranges.value === null"
                                    class="text-sm text-muted-foreground"
                                    data-test="breakdown-hint"
                                >
                                    {{
                                        $t(
                                            'Type the subnet to see the ranges of the standard breakdown.',
                                        )
                                    }}
                                </p>
                                <template v-else>
                                    <ul
                                        class="flex flex-col gap-1.5"
                                        :aria-label="
                                            $t(
                                                'Ranges of the standard breakdown',
                                            )
                                        "
                                    >
                                        <li
                                            v-for="(range, position) in line
                                                .ranges.value"
                                            :key="position"
                                            class="flex flex-wrap items-center gap-x-3 gap-y-1"
                                            data-test="breakdown-range"
                                        >
                                            <RangeKindLabel
                                                :kind="range.kind"
                                                :label="
                                                    rangeKinds.find(
                                                        (kind) =>
                                                            kind.value ===
                                                            range.kind,
                                                    )?.label ?? range.kind
                                                "
                                            />
                                            <span
                                                class="font-mono text-sm"
                                                data-test="breakdown-bounds"
                                                >{{ range.start_address }} –
                                                {{ range.end_address }}</span
                                            >
                                            <span
                                                v-if="range.name"
                                                class="text-sm text-muted-foreground"
                                                >{{ range.name }}</span
                                            >
                                            <span
                                                v-if="!range.fits"
                                                class="text-sm text-error"
                                                >{{
                                                    $t('outside of the subnet')
                                                }}</span
                                            >
                                        </li>
                                    </ul>
                                    <p
                                        v-if="!line.breakdownApplies.value"
                                        class="text-sm text-muted-foreground"
                                        data-test="breakdown-hint"
                                    >
                                        {{
                                            $t(
                                                'The standard breakdown does not fit in this subnet: no range is created.',
                                            )
                                        }}
                                    </p>
                                </template>
                            </div>
                        </template>

                        <!-- Without the subnet, why the rule proposes none
                             is still worth knowing. -->
                        <p
                            v-else-if="line.reason.value"
                            class="text-sm text-muted-foreground"
                            data-test="network-hint"
                        >
                            {{ line.reason.value }}
                        </p>
                    </template>
                </li>
            </ul>

            <InputError
                :message="
                    (form.errors as Record<string, string | undefined>).lines
                "
            />

            <div class="flex flex-wrap items-center gap-2.5">
                <Button
                    type="submit"
                    :disabled="form.processing || selected.length === 0"
                    data-test="apply-standard"
                >
                    <Spinner v-if="form.processing" />
                    {{
                        $tc(
                            'Create the ticked VLAN|Create the :count ticked VLANs',
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
