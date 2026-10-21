<!-- torsade/resources/js/pages/vlans/Form.vue -->
<script setup lang="ts">
import { Head, Link, setLayoutProps, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
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
import { create, edit, index, show, store, update } from '@/routes/vlans';
import type {
    CatalogOption,
    EditableVlan,
    NumberedSite,
    Option,
    StatusValue,
} from '@/types';

// One page for a new VLAN and for an existing one. Only a new VLAN can be
// given its subnet in the same action; afterwards the subnet has its own page.
const props = defineProps<{
    vlan: EditableVlan | null;
    // What the addressing rule works out, or null: without a rule, or for
    // an existing VLAN, nothing is proposed.
    rule: AddressingRuleParams | null;
    // The sites, each with its number: the rule numbers a subnet by it.
    sites: NumberedSite[];
    statuses: Option[];
    prefixes: Option<number>[];
    catalogEntries: CatalogOption[];
    // The site chosen before opening the form, if any.
    site: number | null;
    // The entry of the catalogue chosen before opening the form, if any.
    catalogEntry: number | null;
}>();

const entryById = (id: number | null): CatalogOption | undefined =>
    props.catalogEntries.find((entry) => entry.id === id);

// The entry a new VLAN starts from: its number and its name are proposed,
// and stay free.
const startingEntry = props.vlan ? undefined : entryById(props.catalogEntry);

setLayoutProps({
    breadcrumbs: [
        { title: trans('VLANs'), href: index() },
        props.vlan
            ? { title: props.vlan.name, href: edit(props.vlan.id) }
            : { title: trans('New VLAN'), href: create() },
    ],
});

type VlanForm = {
    site_id: number | null;
    catalog_vlan_id: number | null;
    // A number once typed: the field is a numeric input.
    vid: string | number;
    name: string;
    description: string;
    status: StatusValue;
    with_subnet: boolean;
    subnet: {
        network: string;
        prefix_length: number | null;
        gateway: string;
        // Only the wish is sent: the server works the ranges out.
        apply_breakdown: boolean;
    };
};

// The prefix length the subnet section starts with.
const DEFAULT_PREFIX = 24;

// Whether the addressing rule has ranges to cut a subnet into.
const hasBreakdown = (props.rule?.ranges.length ?? 0) > 0;

const form = useForm<VlanForm>({
    // With a single site, there is nothing to choose.
    site_id:
        props.vlan?.site_id ??
        props.site ??
        (props.sites.length === 1 ? props.sites[0].id : null),
    catalog_vlan_id: props.vlan?.catalog_vlan_id ?? startingEntry?.id ?? null,
    vid: props.vlan
        ? String(props.vlan.vid)
        : startingEntry
          ? String(startingEntry.vid)
          : '',
    name: props.vlan?.name ?? startingEntry?.name ?? '',
    description: props.vlan?.description ?? '',
    status: props.vlan?.status ?? 'active',
    with_subnet: false,
    subnet: {
        network: '',
        prefix_length: DEFAULT_PREFIX,
        gateway: '',
        apply_breakdown: hasBreakdown,
    },
});

// --- What the addressing rule proposes for the subnet ----------------------

const typedVid = computed<number | null>(() => {
    const text = String(form.vid).trim();

    return /^\d{1,4}$/.test(text) ? Number(text) : null;
});

// The address, the prefix length and the gateway follow the site and the
// number of the VLAN, as long as they hold what was proposed.
const { addressIsProposed, gatewayIsProposed, reason } = useSubnetProposal({
    rule: () => props.rule,
    site: () => props.sites.find((site) => site.id === form.site_id) ?? null,
    vid: () => typedVid.value,
    read: () => form.subnet,
    write: (values) => Object.assign(form.subnet, values),
    defaultPrefix: DEFAULT_PREFIX,
});

// Under the address: where it comes from, or why nothing is proposed.
const addressHint = computed<string | undefined>(() =>
    addressIsProposed.value
        ? trans('Proposed by the addressing rule')
        : (reason.value ?? undefined),
);

// Choosing an entry fills in the number and the name of a new VLAN. An
// existing VLAN keeps its own: what differs is then a deviation.
const onEntryChosen = (): void => {
    const entry = entryById(form.catalog_vlan_id);

    if (props.vlan || !entry) {
        return;
    }

    form.vid = String(entry.vid);
    form.name = entry.name;
};

const submit = (): void => {
    if (props.vlan) {
        form.put(update(props.vlan.id).url);
    } else {
        // The box only counts while it is shown.
        form.transform((data) => ({
            ...data,
            subnet: {
                ...data.subnet,
                apply_breakdown: hasBreakdown && data.subnet.apply_breakdown,
            },
        })).post(store().url);
    }
};
</script>

<template>
    <Head :title="vlan ? $t('Edit the VLAN') : $t('New VLAN')" />

    <div class="flex max-w-3xl flex-col gap-7">
        <Heading
            :title="vlan ? $t('Edit the VLAN') : $t('New VLAN')"
            :description="
                $t('The number and the name of a VLAN are unique in its site.')
            "
        />

        <form
            class="panel flex flex-col gap-5 p-5 sm:p-6"
            @submit.prevent="submit"
        >
            <div class="grid gap-2">
                <Label for="site_id">{{ $t('Site') }}</Label>
                <NativeSelect
                    id="site_id"
                    v-model="form.site_id"
                    name="site_id"
                    required
                    :aria-invalid="form.errors.site_id ? 'true' : undefined"
                >
                    <option v-if="form.site_id === null" :value="null" disabled>
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

            <div v-if="catalogEntries.length > 0" class="grid gap-2">
                <Label for="catalog_vlan_id">{{ $t('Catalogue entry') }}</Label>
                <NativeSelect
                    id="catalog_vlan_id"
                    v-model="form.catalog_vlan_id"
                    name="catalog_vlan_id"
                    aria-describedby="catalog-help"
                    :aria-invalid="
                        form.errors.catalog_vlan_id ? 'true' : undefined
                    "
                    @change="onEntryChosen"
                >
                    <option :value="null">{{ $t('No entry') }}</option>
                    <option
                        v-for="entry in catalogEntries"
                        :key="entry.id"
                        :value="entry.id"
                    >
                        {{ entry.vid }} — {{ entry.name }}
                    </option>
                </NativeSelect>
                <p id="catalog-help" class="text-sm text-muted-foreground">
                    {{
                        vlan
                            ? $t(
                                  'Optional. The VLAN of the catalogue this one follows. Its number and its name may differ: it is then shown as a deviation.',
                              )
                            : $t(
                                  'Optional. Choosing an entry fills in the number and the name, which you can still change.',
                              )
                    }}
                </p>
                <InputError :message="form.errors.catalog_vlan_id" />
            </div>

            <div class="grid gap-5 sm:grid-cols-[8rem_1fr]">
                <div class="grid content-start gap-2">
                    <Label for="vid">{{ $t('VID') }}</Label>
                    <Input
                        id="vid"
                        v-model="form.vid"
                        type="number"
                        name="vid"
                        required
                        min="1"
                        max="4094"
                        step="1"
                        v-focus="vlan === null"
                        class="font-mono"
                        :aria-invalid="form.errors.vid ? 'true' : undefined"
                    />
                    <InputError :message="form.errors.vid" />
                </div>

                <div class="grid content-start gap-2">
                    <Label for="name">{{ $t('Name') }}</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        name="name"
                        required
                        maxlength="64"
                        autocomplete="off"
                        :aria-invalid="form.errors.name ? 'true' : undefined"
                    />
                    <InputError :message="form.errors.name" />
                </div>
            </div>

            <div class="grid gap-2 sm:max-w-xs">
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

            <fieldset
                v-if="vlan === null"
                class="flex flex-col gap-5 rounded-lg border p-5"
                data-test="subnet-section"
            >
                <legend class="px-1.5 text-sm font-medium">
                    {{ $t('Subnet') }}
                </legend>

                <Label
                    for="with_subnet"
                    class="flex items-center gap-2.5 text-base leading-snug font-normal"
                >
                    <input
                        id="with_subnet"
                        v-model="form.with_subnet"
                        type="checkbox"
                        name="with_subnet"
                        class="size-4.5 shrink-0 rounded-xs accent-primary"
                    />
                    <span>{{ $t('Create the subnet of this VLAN now') }}</span>
                </Label>

                <template v-if="form.with_subnet">
                    <CidrInput
                        v-model:network="form.subnet.network"
                        v-model:prefix-length="form.subnet.prefix_length"
                        id-prefix="subnet"
                        :prefixes="prefixes"
                        :network-error="form.errors['subnet.network']"
                        :prefix-error="form.errors['subnet.prefix_length']"
                        :network-hint="addressHint"
                    />

                    <div class="grid gap-2 sm:max-w-xs">
                        <Label for="subnet-gateway">{{ $t('Gateway') }}</Label>
                        <Input
                            id="subnet-gateway"
                            v-model="form.subnet.gateway"
                            name="gateway"
                            autocomplete="off"
                            class="font-mono"
                            :aria-invalid="
                                form.errors['subnet.gateway']
                                    ? 'true'
                                    : undefined
                            "
                        />
                        <InputError :message="form.errors['subnet.gateway']" />
                        <p
                            v-if="gatewayIsProposed"
                            class="text-sm text-muted-foreground"
                            data-test="gateway-hint"
                        >
                            {{ $t('Proposed by the addressing rule') }}
                        </p>
                    </div>

                    <div v-if="hasBreakdown" class="grid gap-2">
                        <label class="flex items-center gap-2.5">
                            <input
                                v-model="form.subnet.apply_breakdown"
                                type="checkbox"
                                name="apply_breakdown"
                                class="size-4.5 shrink-0 rounded-xs accent-primary"
                                aria-describedby="breakdown-help"
                                :aria-invalid="
                                    form.errors['subnet.apply_breakdown']
                                        ? 'true'
                                        : undefined
                                "
                            />
                            <span>{{
                                $t('Apply the standard breakdown')
                            }}</span>
                        </label>
                        <p
                            id="breakdown-help"
                            class="text-sm text-muted-foreground"
                        >
                            {{
                                $t(
                                    'The subnet is cut into the ranges of the addressing rule, shifted from its network address.',
                                )
                            }}
                        </p>
                        <InputError
                            :message="form.errors['subnet.apply_breakdown']"
                        />
                    </div>

                    <p class="text-sm text-muted-foreground">
                        {{
                            $t(
                                'The VLAN and its subnet are saved together: if the subnet is refused, nothing is created.',
                            )
                        }}
                    </p>
                </template>
            </fieldset>

            <div class="flex flex-wrap items-center gap-2.5">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    data-test="save-vlan"
                >
                    <Spinner v-if="form.processing" />
                    {{ $t('Save') }}
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="vlan ? show(vlan.id) : index()">{{
                        $t('Cancel')
                    }}</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
