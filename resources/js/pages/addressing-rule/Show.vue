<!-- torsade/resources/js/pages/addressing-rule/Show.vue -->
<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import Deviation from '@/components/network/Deviation.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    breakdownOf,
    cidr,
    gatewayOf,
    hasSiteLevel,
    isCoherent,
    maxSiteNumber,
    maxVid,
    vlanSubnet,
} from '@/lib/addressingRule';
import type {
    AddressingRuleParams,
    GatewayPositionValue,
    RangeKindValue,
} from '@/lib/addressingRule';
import { trans } from '@/lib/i18n';
import { formatCount } from '@/lib/numbers';
import {
    create as createBlock,
    show as showBlock,
} from '@/routes/address-blocks';
import { destroy, show, update } from '@/routes/addressing-rule';
import { show as showSite } from '@/routes/sites';
import { store as storeExpectedBlock } from '@/routes/sites/expected-block';
import type {
    AddressingRulePage,
    Deviation as DeviationData,
    Option,
    OrganisationBlockOption,
    RuleApplicationRow,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: trans('Addressing rule'),
                href: show(),
            },
        ],
    },
});

// The addressing rule of the organisation: the form that sets it, what it
// gives on an example, and how it applies to each site.
const props = defineProps<{
    // The rule in force, or null when the organisation has none.
    rule: AddressingRulePage | null;
    // The blocks a rule can start from: those of the organisation.
    blocks: OrganisationBlockOption[];
    gatewayPositions: Option[];
    rangeKinds: Option[];
    maxRanges: number;
    // How the rule in force applies to each site.
    application: RuleApplicationRow[];
}>();

const page = usePage();

// Only decides which buttons are shown: the server checks every action.
const canEdit = computed<boolean>(() => page.props.auth.can.editNetwork);

// --- The form ---------------------------------------------------------------

type RangeLine = {
    // Tells the lines apart while they are added and removed.
    key: number;
    kind: RangeKindValue;
    // Numbers once typed: the fields are numeric inputs.
    start_offset: string | number;
    end_offset: string | number;
    name: string;
};

type RuleForm = {
    aggregate_block_id: number | null;
    // Null for a rule without a level of sites.
    site_prefix_length: number | null;
    vlan_prefix_length: string | number;
    gateway_position: GatewayPositionValue;
    ranges: RangeLine[];
};

let nextKey = 0;

// The rule in force or, for a first rule, the usual one for the first
// block: one block of sites per third of an address, one subnet per fourth.
const initialValues = (): RuleForm => {
    if (props.rule) {
        return {
            aggregate_block_id: props.rule.aggregate_block_id,
            site_prefix_length: props.rule.site_prefix_length,
            vlan_prefix_length: props.rule.vlan_prefix_length,
            gateway_position: props.rule.gateway_position,
            ranges: props.rule.ranges.map((range) => ({
                key: nextKey++,
                kind: range.kind,
                start_offset: range.start_offset,
                end_offset: range.end_offset,
                name: range.name ?? '',
            })),
        };
    }

    const block = props.blocks[0] ?? null;
    const length = block?.prefix_length ?? 8;

    return {
        aggregate_block_id: block?.id ?? null,
        site_prefix_length: Math.min(length + 8, 29),
        vlan_prefix_length: Math.min(length + 16, 30),
        gateway_position: 'first',
        ranges: [],
    };
};

const form = useForm<RuleForm>(initialValues());

// Without a rule, the form waits to be asked for.
const formOpen = ref<boolean>(props.rule !== null);

const errorOf = (field: string): string | undefined =>
    (form.errors as Record<string, string | undefined>)[field];

const addRange = (): void => {
    const last = form.ranges[form.ranges.length - 1];
    const start = last ? Number(last.end_offset) + 1 : 2;

    form.ranges.push({
        key: nextKey++,
        kind: 'static',
        start_offset: Number.isInteger(start) ? start : '',
        end_offset: '',
        name: '',
    });
};

// The refusals are told by the place of each line: they no longer match
// once a line is gone.
const removeRange = (index: number): void => {
    form.ranges.splice(index, 1);
    form.clearErrors();
};

const submit = (): void => {
    form.transform((data) => ({
        ...data,
        ranges: data.ranges.map((range) => ({
            kind: range.kind,
            start_offset: range.start_offset,
            end_offset: range.end_offset,
            name: range.name.trim() === '' ? null : range.name,
        })),
    })).put(update().url, {
        preserveScroll: true,
        // The server keeps the ranges from the lowest: the form follows.
        onSuccess: () => {
            form.defaults(initialValues());
            form.reset();
        },
    });
};

// --- What the rule being typed gives ----------------------------------------

const asLength = (value: string | number): number =>
    String(value).trim() === '' ? Number.NaN : Number(value);

const typedBlock = computed<OrganisationBlockOption | null>(
    () =>
        props.blocks.find((option) => option.id === form.aggregate_block_id) ??
        null,
);

// The levels of sites the block and the length of the subnets leave: from
// one bit below the block to one bit above the subnets. The chosen one stays
// on the list when the other lengths no longer leave it, so that it is not
// lost while they are typed: the server tells what is wrong with it.
const siteLengths = computed<number[]>(() => {
    const block = typedBlock.value;
    const vlan = asLength(form.vlan_prefix_length);
    const highest = Number.isInteger(vlan) ? Math.min(vlan - 1, 29) : 29;
    const lengths: number[] = [];

    for (
        let length = (block?.prefix_length ?? 0) + 1;
        length <= highest;
        length++
    ) {
        lengths.push(length);
    }

    const chosen = form.site_prefix_length;

    if (chosen !== null && !lengths.includes(chosen)) {
        lengths.push(chosen);
        lengths.sort((a, b) => a - b);
    }

    return lengths;
});

// The rule as the form holds it, whether it makes sense or not.
const typed = computed<AddressingRuleParams | null>(() => {
    const block = typedBlock.value;

    if (!block) {
        return null;
    }

    return {
        network: block.network,
        prefix_length: block.prefix_length,
        site_prefix_length: form.site_prefix_length,
        vlan_prefix_length: asLength(form.vlan_prefix_length),
        gateway_position: form.gateway_position,
        ranges: form.ranges.map((range) => ({
            kind: range.kind,
            start_offset: asLength(range.start_offset),
            end_offset: asLength(range.end_offset),
            name: range.name.trim() === '' ? null : range.name,
        })),
    };
});

// A site and a VLAN to show the rule on: the site 20 and the VLAN 10 when
// the rule reaches them, the highest ones it reaches otherwise. Without a
// level of sites, no site, and the VLAN 20 of the example of the
// documentation.
const summaryOf = (rule: AddressingRuleParams | null) => {
    if (rule === null || !isCoherent(rule)) {
        return null;
    }

    const highestSite = maxSiteNumber(rule);
    const lastSite = highestSite === null ? null : Math.min(highestSite, 65535);
    const lastVid = Math.min(maxVid(rule), 4094);
    const site = lastSite === null ? null : Math.min(20, lastSite);
    const vid = Math.min(lastSite === null ? 20 : 10, lastVid);
    const subnet = vlanSubnet(rule, site, vid);

    if (subnet === null) {
        return null;
    }

    return {
        rule,
        block: `${rule.network}/${rule.prefix_length}`,
        lastSite,
        lastVid,
        site,
        vid,
        subnet,
        gateway: gatewayOf(rule, subnet.network, subnet.prefix_length),
        ranges: breakdownOf(rule, subnet.network, subnet.prefix_length),
    };
};

const summary = computed(() => summaryOf(typed.value));

// What the rule in force gives, for those who only read it.
const savedSummary = computed(() => summaryOf(props.rule));

type Summary = NonNullable<ReturnType<typeof summaryOf>>;

const sentences = (found: Summary): string[] => {
    const subnet = cidr(found.subnet);

    if (found.lastSite === null || found.site === null) {
        return [
            trans('No level of sites.'),
            trans(
                'Subnets of VLANs in /:length within :block: VIDs :min to :max.',
                {
                    length: found.rule.vlan_prefix_length,
                    block: found.block,
                    min: 1,
                    max: formatCount(found.lastVid),
                },
            ),
            found.gateway === null
                ? trans('Example: VLAN :vid → :subnet, no gateway.', {
                      vid: found.vid,
                      subnet,
                  })
                : trans('Example: VLAN :vid → :subnet, gateway :gateway.', {
                      vid: found.vid,
                      subnet,
                      gateway: found.gateway,
                  }),
        ];
    }

    return [
        trans(
            'Blocks of sites in /:length within :block: numbers :min to :max.',
            {
                length: found.rule.site_prefix_length ?? '',
                block: found.block,
                min: 0,
                max: formatCount(found.lastSite),
            },
        ),
        trans('Subnets of VLANs in /:length: VIDs :min to :max.', {
            length: found.rule.vlan_prefix_length,
            min: 1,
            max: formatCount(found.lastVid),
        }),
        found.gateway === null
            ? trans('Example: site :site, VLAN :vid → :subnet, no gateway.', {
                  site: found.site,
                  vid: found.vid,
                  subnet,
              })
            : trans(
                  'Example: site :site, VLAN :vid → :subnet, gateway :gateway.',
                  {
                      site: found.site,
                      vid: found.vid,
                      subnet,
                      gateway: found.gateway,
                  },
              ),
    ];
};

// An address of the example as it is told on a line: its last number in a
// subnet no larger than a /24, the whole address beyond.
const short = (address: string, prefixLength: number): string =>
    prefixLength >= 24 ? `.${address.split('.')[3]}` : address;

// What a line of the ranges gives in the subnet of the example.
const exampleOf = (
    found: Summary | null,
    index: number,
): { text: string; fits: boolean } | null => {
    const placed = found?.ranges[index];
    const range = found?.rule.ranges[index];

    if (
        !found ||
        !placed ||
        !range ||
        !Number.isInteger(range.start_offset) ||
        !Number.isInteger(range.end_offset)
    ) {
        return null;
    }

    return placed.fits
        ? {
              text: trans(':start to :end', {
                  start: short(
                      placed.start_address,
                      found.subnet.prefix_length,
                  ),
                  end: short(placed.end_address, found.subnet.prefix_length),
              }),
              fits: true,
          }
        : {
              text: trans('Does not fit in a /:length subnet', {
                  length: found.subnet.prefix_length,
              }),
              fits: false,
          };
};

const kindLabel = (kind: string): string =>
    props.rangeKinds.find((option) => option.value === kind)?.label ?? kind;

// --- How the rule applies to the sites ---------------------------------------

// The state of a site as a deviation, in the words of the table.
const deviationOf = (row: RuleApplicationRow): DeviationData[] => {
    if (row.state === 'missing') {
        return [
            {
                kind: 'site_block_missing',
                reason: trans('Expected block not declared'),
            },
        ];
    }

    if (row.state === 'outside' && props.rule) {
        return [
            {
                kind: 'site_number_outside_rule',
                reason: trans(
                    'Number beyond the limits of the rule (:min to :max)',
                    {
                        min: 0,
                        max: formatCount(
                            Math.min(maxSiteNumber(props.rule) ?? 0, 65535),
                        ),
                    },
                ),
            },
        ];
    }

    return [];
};

const creatingBlockFor = ref<number | null>(null);

const createExpectedBlock = (row: RuleApplicationRow): void => {
    // The server works the block out by itself. A block in the way refuses
    // it, with a message that names it.
    router.post(
        storeExpectedBlock(row.id).url,
        { from_rule: true },
        {
            preserveScroll: true,
            onStart: () => {
                creatingBlockFor.value = row.id;
            },
            onFinish: () => {
                creatingBlockFor.value = null;
            },
            onError: (errors) =>
                toast.error(
                    Object.values(errors)[0] ?? trans('Something went wrong.'),
                ),
        },
    );
};

// --- Deleting the rule -------------------------------------------------------

const confirmingDeletion = ref<boolean>(false);

const confirmDeletion = (): void => {
    confirmingDeletion.value = false;

    router.delete(destroy().url, {
        onSuccess: () => {
            formOpen.value = false;
            form.defaults(initialValues());
            form.reset();
        },
    });
};
</script>

<template>
    <Head :title="$t('Addressing rule')" />

    <div class="flex flex-col gap-7">
        <Heading
            :title="$t('Addressing rule')"
            :description="
                $t(
                    'How the organisation numbers its networks: its block is cut into one block per site, in the order of the numbers of the sites, and each one into one subnet per VLAN, in the order of the VIDs. The rule proposes addresses and points out what differs from it: it never changes anything by itself.',
                )
            "
        />

        <!-- A rule starts from a block of the organisation. -->
        <div
            v-if="blocks.length === 0"
            class="flex flex-col items-start gap-4 rounded-lg border border-dashed p-5"
            data-test="rule-needs-block"
        >
            <p class="max-w-prose text-muted-foreground">
                {{
                    $t(
                        'A rule starts from a block of the organisation, such as 10.0.0.0/8, and none is declared yet.',
                    )
                }}
            </p>
            <Button v-if="canEdit" variant="outline" as-child>
                <Link :href="createBlock()">{{ $t('New block') }}</Link>
            </Button>
        </div>

        <!-- No rule yet: what a rule is, and the way to one. -->
        <div
            v-else-if="!rule && !formOpen"
            class="flex flex-col items-start gap-4 rounded-lg border border-dashed p-5"
            data-test="rule-missing"
        >
            <p class="max-w-prose text-muted-foreground">
                {{
                    $t(
                        'There is no addressing rule. With one, Torsade proposes the block of each site from its number, the subnet of each VLAN from its VID, a gateway and ranges; without one, it proposes nothing and reports no deviation of addressing.',
                    )
                }}
            </p>
            <Button
                v-if="canEdit"
                type="button"
                data-test="create-rule"
                @click="formOpen = true"
            >
                {{ $t('Create a rule') }}
            </Button>
        </div>

        <!-- The form, for those who may write the rule. -->
        <form
            v-if="canEdit && formOpen && blocks.length > 0"
            class="panel flex max-w-4xl flex-col gap-6 p-5 sm:p-6"
            data-test="rule-form"
            @submit.prevent="submit"
        >
            <div class="grid gap-5 sm:grid-cols-2">
                <div class="grid content-start gap-2">
                    <Label for="rule-block">{{
                        $t('Block of the organisation')
                    }}</Label>
                    <NativeSelect
                        id="rule-block"
                        v-model="form.aggregate_block_id"
                        name="aggregate_block_id"
                        class="font-mono"
                        :aria-invalid="
                            errorOf('aggregate_block_id') ? 'true' : undefined
                        "
                    >
                        <option
                            v-for="block in blocks"
                            :key="block.id"
                            :value="block.id"
                        >
                            {{ block.cidr
                            }}{{ block.name ? ` — ${block.name}` : '' }}
                        </option>
                    </NativeSelect>
                    <InputError :message="errorOf('aggregate_block_id')" />
                </div>

                <div class="grid content-start gap-2">
                    <Label for="rule-gateway">{{
                        $t('Position of the gateway')
                    }}</Label>
                    <NativeSelect
                        id="rule-gateway"
                        v-model="form.gateway_position"
                        name="gateway_position"
                        :aria-invalid="
                            errorOf('gateway_position') ? 'true' : undefined
                        "
                    >
                        <option
                            v-for="position in gatewayPositions"
                            :key="position.value"
                            :value="position.value"
                        >
                            {{ position.label }}
                        </option>
                    </NativeSelect>
                    <InputError :message="errorOf('gateway_position')" />
                </div>

                <div class="grid content-start gap-2">
                    <Label for="rule-site-length">{{
                        $t('Level of sites')
                    }}</Label>
                    <NativeSelect
                        id="rule-site-length"
                        v-model="form.site_prefix_length"
                        name="site_prefix_length"
                        class="sm:max-w-56"
                        aria-describedby="rule-site-length-help"
                        :aria-invalid="
                            errorOf('site_prefix_length') ? 'true' : undefined
                        "
                    >
                        <option :value="null">
                            {{ $t('None (a single site)') }}
                        </option>
                        <option
                            v-for="length in siteLengths"
                            :key="length"
                            :value="length"
                        >
                            {{ $t('Blocks of sites in /:length', { length }) }}
                        </option>
                    </NativeSelect>
                    <p
                        id="rule-site-length-help"
                        class="text-sm text-muted-foreground"
                    >
                        {{
                            $t(
                                'Without a level of sites, every site expects the same subnets: this suits an installation with a single site.',
                            )
                        }}
                    </p>
                    <InputError :message="errorOf('site_prefix_length')" />
                </div>

                <div class="grid content-start gap-2">
                    <Label for="rule-vlan-length">{{
                        $t('Length of the subnets of VLANs')
                    }}</Label>
                    <Input
                        id="rule-vlan-length"
                        v-model="form.vlan_prefix_length"
                        type="number"
                        name="vlan_prefix_length"
                        required
                        min="1"
                        max="32"
                        step="1"
                        class="font-mono sm:max-w-32"
                        :aria-invalid="
                            errorOf('vlan_prefix_length') ? 'true' : undefined
                        "
                    />
                    <InputError :message="errorOf('vlan_prefix_length')" />
                </div>
            </div>

            <!-- What the values being typed give. Always there, so that a
                 screen reader is told when it changes. -->
            <div
                role="status"
                class="rounded-md bg-sunken p-4 text-sm"
                data-test="rule-summary"
            >
                <ul v-if="summary" class="flex flex-col gap-1">
                    <li v-for="sentence in sentences(summary)" :key="sentence">
                        {{ sentence }}
                    </li>
                </ul>
                <p v-else class="text-muted-foreground">
                    {{
                        $t(
                            'The lengths must grow from the block of the organisation to the blocks of sites, then to the subnets of VLANs, a /30 at most: what the rule gives is then shown here.',
                        )
                    }}
                </p>
            </div>

            <fieldset class="flex flex-col gap-3">
                <legend class="text-lg font-semibold">
                    {{ $t('Standard breakdown') }}
                </legend>
                <p class="max-w-prose text-sm text-muted-foreground">
                    {{
                        $t(
                            'The ranges a subnet of a VLAN is usually cut into, as offsets from its network address: 2 to 20 is .2 to .20 in a /24. Optional.',
                        )
                    }}
                </p>

                <p
                    v-if="form.ranges.length === 0"
                    class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
                >
                    {{ $t('No range: subnets are proposed without any.') }}
                </p>

                <ul v-else class="flex flex-col gap-3">
                    <li
                        v-for="(range, index) in form.ranges"
                        :key="range.key"
                        class="flex flex-col gap-2 rounded-md border p-3"
                        :data-test="`rule-range-${index}`"
                    >
                        <div
                            class="grid items-end gap-3 sm:grid-cols-[9rem_6rem_6rem_1fr_auto]"
                        >
                            <div class="grid gap-1.5">
                                <Label :for="`rule-range-kind-${range.key}`">{{
                                    $t('Type')
                                }}</Label>
                                <NativeSelect
                                    :id="`rule-range-kind-${range.key}`"
                                    v-model="range.kind"
                                    :aria-invalid="
                                        errorOf(`ranges.${index}.kind`)
                                            ? 'true'
                                            : undefined
                                    "
                                >
                                    <option
                                        v-for="kind in rangeKinds"
                                        :key="kind.value"
                                        :value="kind.value"
                                    >
                                        {{ kind.label }}
                                    </option>
                                </NativeSelect>
                            </div>
                            <div class="grid gap-1.5">
                                <Label :for="`rule-range-start-${range.key}`">{{
                                    $t('Start')
                                }}</Label>
                                <Input
                                    :id="`rule-range-start-${range.key}`"
                                    v-model="range.start_offset"
                                    type="number"
                                    required
                                    min="0"
                                    step="1"
                                    class="font-mono"
                                    :aria-invalid="
                                        errorOf(`ranges.${index}.start_offset`)
                                            ? 'true'
                                            : undefined
                                    "
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label :for="`rule-range-end-${range.key}`">{{
                                    $t('End')
                                }}</Label>
                                <Input
                                    :id="`rule-range-end-${range.key}`"
                                    v-model="range.end_offset"
                                    type="number"
                                    required
                                    min="0"
                                    step="1"
                                    class="font-mono"
                                    :aria-invalid="
                                        errorOf(`ranges.${index}.end_offset`)
                                            ? 'true'
                                            : undefined
                                    "
                                />
                            </div>
                            <div class="grid gap-1.5">
                                <Label :for="`rule-range-name-${range.key}`">{{
                                    $t('Name')
                                }}</Label>
                                <Input
                                    :id="`rule-range-name-${range.key}`"
                                    v-model="range.name"
                                    maxlength="64"
                                    autocomplete="off"
                                    :aria-invalid="
                                        errorOf(`ranges.${index}.name`)
                                            ? 'true'
                                            : undefined
                                    "
                                />
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                :aria-label="
                                    $t('Remove the range :number', {
                                        number: index + 1,
                                    })
                                "
                                @click="removeRange(index)"
                            >
                                <Trash2 aria-hidden="true" />
                            </Button>
                        </div>

                        <!-- What the line gives in the subnet of the
                             example. -->
                        <p
                            v-if="exampleOf(summary, index)"
                            class="font-mono text-sm"
                            :class="
                                exampleOf(summary, index)?.fits
                                    ? 'text-muted-foreground'
                                    : 'text-pair-orange'
                            "
                            :data-test="`rule-range-example-${index}`"
                        >
                            {{ exampleOf(summary, index)?.text }}
                        </p>

                        <InputError
                            v-for="field in [
                                'kind',
                                'start_offset',
                                'end_offset',
                                'name',
                            ]"
                            :key="field"
                            :message="errorOf(`ranges.${index}.${field}`)"
                        />
                    </li>
                </ul>

                <InputError :message="errorOf('ranges')" />

                <div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="form.ranges.length >= maxRanges"
                        data-test="add-rule-range"
                        @click="addRange"
                    >
                        <Plus aria-hidden="true" />
                        {{ $t('Add a range') }}
                    </Button>
                </div>
            </fieldset>

            <div class="flex flex-wrap items-center gap-2.5">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    data-test="save-rule"
                >
                    <Spinner v-if="form.processing" />
                    {{ $t('Save') }}
                </Button>
                <Button
                    v-if="rule"
                    type="button"
                    variant="outline"
                    data-test="delete-rule"
                    @click="confirmingDeletion = true"
                >
                    {{ $t('Delete the rule') }}
                </Button>
                <Button
                    v-else
                    type="button"
                    variant="outline"
                    @click="formOpen = false"
                >
                    {{ $t('Cancel') }}
                </Button>
            </div>
        </form>

        <!-- The rule in force, for those who only read it. -->
        <section
            v-if="rule && !canEdit"
            class="panel flex max-w-4xl flex-col gap-4 p-5 sm:p-6"
            :aria-label="$t('Rule in force')"
            data-test="rule-read"
        >
            <ul v-if="savedSummary" class="flex flex-col gap-1">
                <li v-for="sentence in sentences(savedSummary)" :key="sentence">
                    {{ sentence }}
                </li>
            </ul>

            <div v-if="rule.ranges.length > 0" class="flex flex-col gap-2">
                <h2 class="text-lg font-semibold">
                    {{ $t('Standard breakdown') }}
                </h2>
                <ul class="flex flex-col gap-1 text-sm">
                    <li
                        v-for="(range, index) in rule.ranges"
                        :key="index"
                        class="flex flex-wrap gap-x-3"
                    >
                        <span class="font-medium">{{
                            kindLabel(range.kind)
                        }}</span>
                        <span class="font-mono"
                            >{{ range.start_offset }} –
                            {{ range.end_offset }}</span
                        >
                        <span v-if="range.name">{{ range.name }}</span>
                        <span
                            v-if="exampleOf(savedSummary, index)"
                            class="font-mono text-muted-foreground"
                            >{{ exampleOf(savedSummary, index)?.text }}</span
                        >
                    </li>
                </ul>
            </div>
        </section>

        <!-- Site by site: the block the rule in force expects, and whether
             it is declared. -->
        <section
            v-if="rule"
            class="flex flex-col gap-4"
            :aria-label="$t('Application of the rule')"
            data-test="rule-application"
        >
            <Heading
                variant="small"
                :title="$t('Application of the rule')"
                :description="
                    hasSiteLevel(rule)
                        ? $t(
                              'For each site, the block the rule in force expects from its number, and whether the site has declared it.',
                          )
                        : undefined
                "
            />

            <!-- Without a level of sites, there is no block to expect. -->
            <p
                v-if="!hasSiteLevel(rule)"
                class="rounded-lg border border-dashed p-4 text-muted-foreground"
                data-test="rule-application-none"
            >
                {{ $t('The rule expects no block of a site.') }}
            </p>

            <p
                v-else-if="application.length === 0"
                class="rounded-lg border border-dashed p-4 text-muted-foreground"
            >
                {{ $t('No site yet.') }}
            </p>

            <div v-else class="table-frame">
                <table
                    class="table-dense"
                    :aria-label="$t('Application of the rule')"
                >
                    <thead>
                        <tr>
                            <th scope="col">{{ $t('Site') }}</th>
                            <th scope="col" class="text-right">
                                {{ $t('Number') }}
                            </th>
                            <th scope="col">{{ $t('Expected block') }}</th>
                            <th scope="col">{{ $t('State') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in application"
                            :key="row.id"
                            :data-state="row.state"
                            data-test="rule-application-row"
                        >
                            <th scope="row" class="font-medium">
                                <Link :href="showSite(row.id)" class="link">{{
                                    row.name
                                }}</Link>
                            </th>
                            <td class="text-right font-mono text-sm">
                                {{ row.number ?? '—' }}
                            </td>
                            <td class="font-mono text-sm whitespace-nowrap">
                                {{ row.expected_block ?? '—' }}
                            </td>
                            <td>
                                <div
                                    class="flex flex-wrap items-center gap-x-4 gap-y-2"
                                >
                                    <Link
                                        v-if="
                                            row.state === 'declared' &&
                                            row.block_id !== null
                                        "
                                        :href="showBlock(row.block_id)"
                                        class="link"
                                        >{{ $t('Block declared') }}</Link
                                    >
                                    <span
                                        v-else-if="row.state === 'unnumbered'"
                                        class="text-muted-foreground"
                                        >{{ $t('No number') }}</span
                                    >
                                    <Deviation
                                        v-else
                                        :deviations="deviationOf(row)"
                                    />
                                    <Button
                                        v-if="
                                            canEdit && row.state === 'missing'
                                        "
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        :disabled="creatingBlockFor === row.id"
                                        data-test="create-expected-block"
                                        @click="createExpectedBlock(row)"
                                    >
                                        <Spinner
                                            v-if="creatingBlockFor === row.id"
                                        />
                                        {{ $t('Create this block') }}
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <ConfirmDialog
        :open="confirmingDeletion"
        :title="$t('Delete the addressing rule?')"
        :description="
            $t(
                'The rule and its standard breakdown will be deleted, and nothing else: blocks, subnets and ranges stay as they are. Torsade will no longer propose addresses nor report deviations of addressing.',
            )
        "
        :confirm-label="$t('Delete')"
        destructive
        @confirm="confirmDeletion"
        @cancel="confirmingDeletion = false"
    />
</template>
