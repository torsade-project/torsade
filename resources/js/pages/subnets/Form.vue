<!-- torsade/resources/js/pages/subnets/Form.vue -->
<script setup lang="ts">
import { Head, Link, setLayoutProps, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import CidrInput from '@/components/network/CidrInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useSubnetProposal } from '@/composables/useSubnetProposal';
import type { AddressingRuleParams } from '@/lib/addressingRule';
import { trans } from '@/lib/i18n';
import { create, edit, index, show, store, update } from '@/routes/subnets';
import type {
    EditableSubnet,
    Option,
    SiteOption,
    StatusValue,
    SubnetKindOption,
    SubnetKindValue,
    VlanOption,
} from '@/types';

// One page for a new subnet and for an existing one.
const props = defineProps<{
    subnet: EditableSubnet | null;
    // What the addressing rule works out, or null: without a rule, or for
    // an existing subnet, nothing is proposed.
    rule: AddressingRuleParams | null;
    // The VLANs that have no subnet yet, plus the one of this subnet.
    vlans: VlanOption[];
    sites: SiteOption[];
    // The kinds a subnet can be, each with what it accepts.
    kinds: SubnetKindOption[];
    statuses: Option[];
    prefixes: Option<number>[];
    // The VLAN or the site chosen before opening the form, if any.
    vlan: number | null;
    site: number | null;
}>();

setLayoutProps({
    breadcrumbs: [
        { title: trans('Subnets'), href: index() },
        props.subnet
            ? { title: props.subnet.cidr, href: edit(props.subnet.id) }
            : { title: trans('New subnet'), href: create() },
    ],
});

type SubnetForm = {
    network: string;
    prefix_length: number | null;
    kind: SubnetKindValue;
    vlan_id: number | null;
    site_id: number | null;
    gateway: string;
    name: string;
    description: string;
    status: StatusValue;
    // Only the wish is sent: the server works the ranges out.
    apply_breakdown: boolean;
};

// The prefix length a new subnet starts with.
const DEFAULT_PREFIX = 24;

// Whether the addressing rule has ranges to cut a subnet into.
const hasBreakdown = (props.rule?.ranges.length ?? 0) > 0;

const form = useForm<SubnetForm>({
    network: props.subnet?.network ?? '',
    prefix_length: props.subnet?.prefix_length ?? DEFAULT_PREFIX,
    kind: props.subnet?.kind ?? 'segment',
    vlan_id: props.subnet?.vlan_id ?? props.vlan,
    site_id: props.subnet ? props.subnet.site_id : props.site,
    gateway: props.subnet?.gateway ?? '',
    name: props.subnet?.name ?? '',
    description: props.subnet?.description ?? '',
    status: props.subnet?.status ?? 'active',
    apply_breakdown: hasBreakdown,
});

// The list of VLANs, one group per site.
const vlansBySite = computed<{ site: string; vlans: VlanOption[] }[]>(() => {
    const groups = new Map<string, VlanOption[]>();

    for (const vlan of props.vlans) {
        groups.set(vlan.site, [...(groups.get(vlan.site) ?? []), vlan]);
    }

    return [...groups].map(([site, vlans]) => ({ site, vlans }));
});

const kind = computed<SubnetKindOption>(
    () =>
        props.kinds.find((option) => option.value === form.kind) ??
        props.kinds[0],
);

// What a kind excludes is hidden, unless a value is still there: it is then
// shown with the reason, so that it can be emptied. The server refuses it
// whatever the form shows.
const showsVlan = computed<boolean>(
    () => kind.value.allows_vlan || form.vlan_id !== null,
);
const showsGateway = computed<boolean>(
    () => kind.value.allows_gateway || form.gateway.trim() !== '',
);

// A subnet carried by a VLAN is on the site of that VLAN.
const vlan = computed<VlanOption | null>(
    () => props.vlans.find((option) => option.id === form.vlan_id) ?? null,
);

watch(
    vlan,
    (chosen) => {
        if (chosen) {
            form.site_id = chosen.site_id;
        }
    },
    { immediate: true },
);

// A kind whose size is fixed moves the prefix length to a size it accepts:
// a /31 for a link between two routers, a /32 for a loopback.
watch(
    () => form.kind,
    () => {
        const lengths = kind.value.prefix_lengths;

        if (
            form.prefix_length === null ||
            !lengths.includes(form.prefix_length)
        ) {
            form.prefix_length = lengths[lengths.length - 1];
        }
    },
);

// --- What the addressing rule proposes --------------------------------------

// The rule expects a segment for a VLAN, from the number of its site and
// from its VID: with a VLAN chosen, the address, the prefix length and the
// gateway are proposed, and follow the VLAN as long as they hold what was
// proposed.
const { addressIsProposed, gatewayIsProposed, reason } = useSubnetProposal({
    rule: () => props.rule,
    site: () =>
        vlan.value && form.kind === 'segment'
            ? { name: vlan.value.site, number: vlan.value.site_number }
            : null,
    vid: () => vlan.value?.vid ?? null,
    read: () => form,
    write: (values) => Object.assign(form, values),
    defaultPrefix: DEFAULT_PREFIX,
});

// Under the address: where it comes from, or why nothing is proposed.
const addressHint = computed<string | undefined>(() =>
    addressIsProposed.value
        ? trans('Proposed by the addressing rule')
        : (reason.value ?? undefined),
);

// The ranges of the rule are offered along with what it proposes: for a
// new segment carried by a VLAN.
const offersBreakdown = computed<boolean>(
    () => hasBreakdown && vlan.value !== null && form.kind === 'segment',
);

const submit = (): void => {
    if (props.subnet) {
        form.put(update(props.subnet.id).url);
    } else {
        // The box only counts while it is shown.
        form.transform((data) => ({
            ...data,
            apply_breakdown: offersBreakdown.value && data.apply_breakdown,
        })).post(store().url);
    }
};
</script>

<template>
    <Head :title="subnet ? $t('Edit the subnet') : $t('New subnet')" />

    <div class="flex max-w-3xl flex-col gap-7">
        <Heading
            :title="subnet ? $t('Edit the subnet') : $t('New subnet')"
            :description="
                $t(
                    'Type the address in full, such as 192.168.100.0/24, or an address and choose the prefix length.',
                )
            "
        />

        <form
            class="panel flex flex-col gap-5 p-5 sm:p-6"
            @submit.prevent="submit"
        >
            <CidrInput
                v-model:network="form.network"
                v-model:prefix-length="form.prefix_length"
                id-prefix="subnet"
                :prefixes="prefixes"
                :network-error="form.errors.network"
                :prefix-error="form.errors.prefix_length"
                :network-hint="addressHint"
            />

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="grid content-start gap-2">
                    <Label for="kind">{{ $t('Type') }}</Label>
                    <NativeSelect
                        id="kind"
                        v-model="form.kind"
                        name="kind"
                        aria-describedby="kind-help"
                        :aria-invalid="form.errors.kind ? 'true' : undefined"
                    >
                        <option
                            v-for="option in kinds"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </NativeSelect>
                    <p id="kind-help" class="text-sm text-muted-foreground">
                        {{
                            $t(
                                'A point-to-point link is a /30 or a /31, a loopback a /32: neither has a gateway or ranges.',
                            )
                        }}
                    </p>
                    <InputError :message="form.errors.kind" />
                </div>

                <div class="grid content-start gap-2">
                    <Label for="status">{{ $t('Status') }}</Label>
                    <NativeSelect
                        id="status"
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
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div v-if="showsVlan" class="grid content-start gap-2">
                    <Label for="vlan_id">{{ $t('VLAN') }}</Label>
                    <NativeSelect
                        id="vlan_id"
                        v-model="form.vlan_id"
                        name="vlan_id"
                        :aria-describedby="
                            kind.allows_vlan ? undefined : 'vlan-excluded'
                        "
                        :aria-invalid="form.errors.vlan_id ? 'true' : undefined"
                    >
                        <option :value="null">{{ $t('No VLAN') }}</option>
                        <optgroup
                            v-for="group in vlansBySite"
                            :key="group.site"
                            :label="group.site"
                        >
                            <option
                                v-for="option in group.vlans"
                                :key="option.id"
                                :value="option.id"
                            >
                                {{ option.label }}
                            </option>
                        </optgroup>
                    </NativeSelect>
                    <p
                        v-if="!kind.allows_vlan"
                        id="vlan-excluded"
                        class="text-sm text-pair-orange"
                    >
                        {{
                            $t(
                                'A subnet of this kind is not carried by a VLAN: choose "No VLAN".',
                            )
                        }}
                    </p>
                    <InputError :message="form.errors.vlan_id" />
                </div>

                <div class="grid content-start gap-2">
                    <Label for="site_id">{{ $t('Site') }}</Label>
                    <!-- With a VLAN, the site is the one of the VLAN: it is
                         shown, and cannot be changed here. -->
                    <NativeSelect
                        id="site_id"
                        v-model="form.site_id"
                        name="site_id"
                        :disabled="vlan !== null"
                        aria-describedby="site-help"
                        :aria-invalid="form.errors.site_id ? 'true' : undefined"
                    >
                        <option :value="null">{{ $t('No site') }}</option>
                        <option
                            v-for="option in sites"
                            :key="option.id"
                            :value="option.id"
                        >
                            {{ option.name }}
                        </option>
                    </NativeSelect>
                    <p id="site-help" class="text-sm text-muted-foreground">
                        {{
                            vlan
                                ? $t(
                                      'The site of the VLAN: a subnet is on the site of the VLAN that carries it.',
                                  )
                                : $t(
                                      'Optional. Where a subnet without a VLAN belongs: a carrier link, a tunnel.',
                                  )
                        }}
                    </p>
                    <InputError :message="form.errors.site_id" />
                </div>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div v-if="showsGateway" class="grid content-start gap-2">
                    <Label for="gateway">{{ $t('Gateway') }}</Label>
                    <Input
                        id="gateway"
                        v-model="form.gateway"
                        name="gateway"
                        autocomplete="off"
                        class="font-mono"
                        :aria-describedby="
                            kind.allows_gateway ? undefined : 'gateway-excluded'
                        "
                        :aria-invalid="form.errors.gateway ? 'true' : undefined"
                    />
                    <p
                        v-if="!kind.allows_gateway"
                        id="gateway-excluded"
                        class="text-sm text-pair-orange"
                    >
                        {{
                            $t(
                                'A subnet of this kind has no gateway: empty this field.',
                            )
                        }}
                    </p>
                    <InputError :message="form.errors.gateway" />
                    <p
                        v-if="gatewayIsProposed"
                        class="text-sm text-muted-foreground"
                        data-test="gateway-hint"
                    >
                        {{ $t('Proposed by the addressing rule') }}
                    </p>
                </div>

                <div class="grid content-start gap-2">
                    <Label for="name">{{ $t('Name') }}</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        name="name"
                        maxlength="100"
                        autocomplete="off"
                        aria-describedby="name-help"
                        :aria-invalid="form.errors.name ? 'true' : undefined"
                    />
                    <p id="name-help" class="text-sm text-muted-foreground">
                        {{
                            $t(
                                'Optional. Useful for a subnet without a VLAN: carrier link, VPN.',
                            )
                        }}
                    </p>
                    <InputError :message="form.errors.name" />
                </div>
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

            <div v-if="offersBreakdown" class="grid gap-2">
                <label class="flex items-center gap-2.5">
                    <input
                        v-model="form.apply_breakdown"
                        type="checkbox"
                        name="apply_breakdown"
                        class="size-4.5 shrink-0 rounded-xs accent-primary"
                        aria-describedby="breakdown-help"
                        :aria-invalid="
                            form.errors.apply_breakdown ? 'true' : undefined
                        "
                    />
                    <span>{{ $t('Apply the standard breakdown') }}</span>
                </label>
                <p id="breakdown-help" class="text-sm text-muted-foreground">
                    {{
                        $t(
                            'The subnet is cut into the ranges of the addressing rule, shifted from its network address.',
                        )
                    }}
                </p>
                <InputError :message="form.errors.apply_breakdown" />
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    data-test="save-subnet"
                >
                    <Spinner v-if="form.processing" />
                    {{ $t('Save') }}
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="subnet ? show(subnet.id) : index()">{{
                        $t('Cancel')
                    }}</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
