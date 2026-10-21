<!-- torsade/resources/js/pages/blocks/Form.vue -->
<script setup lang="ts">
import { Head, Link, setLayoutProps, useForm } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
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
import { trans } from '@/lib/i18n';
import { prefixRange, rangeHolds } from '@/lib/ipv4';
import type { PrefixRange } from '@/lib/ipv4';
import { formatCount } from '@/lib/numbers';
import {
    create,
    edit,
    index,
    show,
    store,
    update,
} from '@/routes/address-blocks';
import type {
    EditableAddressBlock,
    Option,
    SiteBlockPrefix,
    SiteOption,
    SitePrefix,
    StatusValue,
} from '@/types';

// One page for a new block and for an existing one.
const props = defineProps<{
    block: EditableAddressBlock | null;
    sites: SiteOption[];
    statuses: Option[];
    prefixes: Option<number>[];
    // The site chosen before opening the form, if any.
    site: number | null;
    // The segments of every site, and the blocks the sites already have.
    segments: SitePrefix[];
    siteBlocks: SiteBlockPrefix[];
}>();

setLayoutProps({
    breadcrumbs: [
        { title: trans('Address blocks'), href: index() },
        props.block
            ? { title: props.block.cidr, href: edit(props.block.id) }
            : { title: trans('New block'), href: create() },
    ],
});

type BlockForm = {
    network: string;
    prefix_length: number | null;
    site_id: number | null;
    name: string;
    description: string;
    status: StatusValue;
};

const form = useForm<BlockForm>({
    network: props.block?.network ?? '',
    prefix_length: props.block?.prefix_length ?? 16,
    site_id: props.block ? props.block.site_id : props.site,
    name: props.block?.name ?? '',
    description: props.block?.description ?? '',
    status: props.block?.status ?? 'active',
});

// --- What the block being typed would hold ---------------------------------

// The prefix as it would be sent: the length typed after the address wins
// over the one of the list, as on the server.
const typedPrefix = computed<PrefixRange | null>(() => {
    const [address, length] = form.network.trim().split('/', 2);

    if (length === undefined) {
        return form.prefix_length === null
            ? null
            : prefixRange(address, form.prefix_length);
    }

    return /^\d{1,2}$/.test(length)
        ? prefixRange(address, Number(length))
        : null;
});

// The block of a site is expected to hold its segments. While the block is
// typed, the form says how many it would hold: declaring one network of the
// site as its block is seen before it is saved, not after, on five
// deviations. It is told, never refused.
const coverage = computed<{
    site: string;
    total: number;
    held: number;
    stillOutside: number;
} | null>(() => {
    const site = props.sites.find((option) => option.id === form.site_id);
    const typed = typedPrefix.value;

    if (!site || typed === null) {
        return null;
    }

    const ranges = (prefixes: SitePrefix[]): PrefixRange[] =>
        prefixes
            .filter((prefix) => prefix.site_id === site.id)
            .map((prefix) => prefixRange(prefix.network, prefix.prefix_length))
            .filter((range) => range !== null);

    const segments = ranges(props.segments);

    if (segments.length === 0) {
        return null;
    }

    // The other blocks of the site: a segment one of them holds is where it
    // should be, whatever this block holds.
    const others = ranges(
        props.siteBlocks.filter((block) => block.id !== props.block?.id),
    );

    const outside = segments.filter((segment) => !rangeHolds(typed, segment));

    return {
        site: site.name,
        total: segments.length,
        held: segments.length - outside.length,
        stillOutside: outside.filter(
            (segment) => !others.some((other) => rangeHolds(other, segment)),
        ).length,
    };
});

const coverageSentence = computed<string>(() => {
    const found = coverage.value;

    if (found === null) {
        return '';
    }

    if (found.total === 1) {
        return found.held === 1
            ? trans(
                  'This block would hold the only subnet of the site :site.',
                  {
                      site: found.site,
                  },
              )
            : trans(
                  'This block would not hold the only subnet of the site :site.',
                  { site: found.site },
              );
    }

    return trans(
        'This block would hold :held of the :total subnets of the site :site.',
        {
            held: formatCount(found.held),
            total: formatCount(found.total),
            site: found.site,
        },
    );
});

const submit = (): void => {
    if (props.block) {
        form.put(update(props.block.id).url);
    } else {
        form.post(store().url);
    }
};
</script>

<template>
    <Head :title="block ? $t('Edit the block') : $t('New block')" />

    <div class="flex max-w-3xl flex-col gap-7">
        <Heading
            :title="block ? $t('Edit the block') : $t('New block')"
            :description="
                $t(
                    'Type the block in full, such as 10.1.0.0/16, or an address and choose the prefix length.',
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
                id-prefix="block"
                :prefixes="prefixes"
                :network-error="form.errors.network"
                :prefix-error="form.errors.prefix_length"
            />

            <!-- In one column, the help follows the choice of the site; in
                 two, it runs under both fields, over the whole width. -->
            <div class="grid gap-x-5 gap-y-3 sm:grid-cols-2">
                <div class="grid content-start gap-2">
                    <Label for="site_id">{{ $t('Site') }}</Label>
                    <NativeSelect
                        id="site_id"
                        v-model="form.site_id"
                        name="site_id"
                        aria-describedby="site-help"
                        :aria-invalid="form.errors.site_id ? 'true' : undefined"
                    >
                        <option :value="null">
                            {{ $t('Organisation (no site)') }}
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

                <div
                    class="flex flex-col gap-2 text-sm sm:col-span-2 sm:row-start-2"
                >
                    <div
                        id="site-help"
                        class="flex flex-col gap-1 text-muted-foreground"
                    >
                        <p>
                            {{
                                $t(
                                    'The block of a site is the envelope of all of its subnets, for example 192.168.0.0/16 for VLANs in 192.168.10.0/24 and 192.168.20.0/24. The network of a LAN is declared as the subnet of its VLAN, not as a block. A site without a hierarchical plan may have no block at all.',
                                )
                            }}
                        </p>
                        <p>
                            {{
                                $t(
                                    'A block of the organisation can hold other blocks. The blocks of sites never overlap.',
                                )
                            }}
                        </p>
                    </div>

                    <!-- Always there, so that a screen reader is told when
                         the count appears or changes. -->
                    <div role="status" data-test="block-coverage">
                        <template v-if="coverage">
                            <p>{{ coverageSentence }}</p>
                            <p
                                v-if="
                                    coverage.held === 0 &&
                                    coverage.stillOutside > 0
                                "
                                class="mt-1 flex items-start gap-1.5 text-pair-orange"
                                data-test="block-coverage-none"
                            >
                                <TriangleAlert
                                    class="mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <span>{{
                                    $tc(
                                        'It would still be reported outside of the blocks of the site.|None would lie in it: they would still be reported outside of the blocks of the site.',
                                        coverage.total,
                                    )
                                }}</span>
                            </p>
                        </template>
                    </div>
                </div>

                <div
                    class="grid content-start gap-2 max-sm:mt-2 sm:col-start-2 sm:row-start-1"
                >
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

            <div class="grid gap-2">
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
                            'Optional. What the block is for: interconnections, a site, a future extension.',
                        )
                    }}
                </p>
                <InputError :message="form.errors.name" />
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
                    data-test="save-block"
                >
                    <Spinner v-if="form.processing" />
                    {{ $t('Save') }}
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="block ? show(block.id) : index()">{{
                        $t('Cancel')
                    }}</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
