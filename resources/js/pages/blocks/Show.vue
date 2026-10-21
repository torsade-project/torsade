<!-- torsade/resources/js/pages/blocks/Show.vue -->
<script setup lang="ts">
import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DeviationList from '@/components/network/DeviationList.vue';
import Fact from '@/components/network/Fact.vue';
import FactBand from '@/components/network/FactBand.vue';
import OccupancyBar from '@/components/network/OccupancyBar.vue';
import StatusBadge from '@/components/network/StatusBadge.vue';
import VlanTag from '@/components/network/VlanTag.vue';
import { Button } from '@/components/ui/button';
import { trans } from '@/lib/i18n';
import { formatCount } from '@/lib/numbers';
import { destroy, edit, index, show } from '@/routes/address-blocks';
import { show as showSubnet } from '@/routes/subnets';
import { show as showVlan } from '@/routes/vlans';
import type { AddressBlock, BlockSpace, PlacedSubnet } from '@/types';

// A block of addresses: the subnets it holds, how much of it they take,
// what is left of it, and the subnets that are not where the blocks say.
const props = defineProps<{
    block: AddressBlock;
    space: BlockSpace;
    subnets: PlacedSubnet[];
}>();

setLayoutProps({
    breadcrumbs: [
        { title: trans('Address blocks'), href: index() },
        { title: props.block.cidr, href: show(props.block.id) },
    ],
});

const page = usePage();

// Only decides which buttons are shown: the server checks every action.
const canEdit = computed<boolean>(() => page.props.auth.can.editNetwork);

// Whose block it is and what it is for, under its address.
const context = computed<string>(() =>
    [
        props.block.site
            ? trans('Site :name', { name: props.block.site.name })
            : trans('Block of the organisation'),
        props.block.name,
    ]
        .filter(Boolean)
        .join(', '),
);

const deviations = computed(() =>
    props.subnets
        .filter((subnet) => subnet.deviations.length > 0)
        .map((subnet) => ({
            key: `subnet-${subnet.id}`,
            label: subnet.cidr,
            href: showSubnet(subnet.id),
            technical: true,
            deviations: subnet.deviations,
        })),
);

const confirmingDeletion = ref<boolean>(false);

const confirmDeletion = (): void => {
    confirmingDeletion.value = false;

    router.delete(destroy(props.block.id).url, {
        onError: (errors) =>
            toast.error(
                Object.values(errors)[0] ?? trans('Something went wrong.'),
            ),
    });
};
</script>

<template>
    <Head :title="block.cidr" />

    <div class="flex flex-col gap-7">
        <header class="flex flex-wrap items-end justify-between gap-5">
            <div class="flex min-w-0 flex-col gap-3">
                <div class="flex flex-wrap items-center gap-x-3.5 gap-y-2">
                    <h1
                        class="font-mono text-2xl font-medium tracking-tight break-all sm:text-4xl"
                        data-test="block-cidr"
                    >
                        {{ block.cidr }}
                    </h1>
                    <StatusBadge
                        :status="block.status"
                        :label="block.status_label"
                    />
                </div>
                <p class="text-muted-foreground">{{ context }}</p>
            </div>
            <div v-if="canEdit" class="flex flex-wrap gap-2.5">
                <Button variant="outline" as-child data-test="edit-block">
                    <Link :href="edit(block.id)">{{ $t('Edit') }}</Link>
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    data-test="delete-block"
                    @click="confirmingDeletion = true"
                >
                    {{ $t('Delete') }}
                </Button>
            </div>
        </header>

        <FactBand class="xl:grid-cols-4" data-test="block-summary">
            <Fact :label="$t('Mask')">
                <div class="font-mono">{{ block.mask }}</div>
                <div class="font-mono text-note text-muted-foreground">
                    /{{ block.prefix_length }}
                </div>
            </Fact>
            <Fact :label="$t('Addresses')">
                <div class="font-mono">{{ formatCount(block.size) }}</div>
                <div class="font-mono text-note text-muted-foreground">
                    {{ block.network
                    }}<template v-if="block.size > 1">
                        – {{ block.last_address }}</template
                    >
                </div>
            </Fact>
            <Fact :label="$t('Occupied space')">
                <OccupancyBar
                    :documented="space.occupied"
                    :usable="space.size"
                />
            </Fact>
            <Fact :label="$t('Free space')">
                <div class="font-mono" data-test="block-free-addresses">
                    {{ formatCount(space.free) }}
                </div>
                <div class="text-note text-muted-foreground">
                    {{
                        $tc(
                            'in :count prefix|in :count prefixes',
                            space.free_prefix_count,
                        )
                    }}
                </div>
            </Fact>
        </FactBand>

        <p
            v-if="block.description"
            class="max-w-prose whitespace-pre-line text-muted-foreground"
        >
            {{ block.description }}
        </p>

        <div class="flex flex-wrap items-start gap-7">
            <section
                class="flex min-w-0 flex-[999_1_40rem] flex-col gap-3"
                :aria-label="$t('Subnets')"
                data-test="block-subnets"
            >
                <h2 class="text-lg font-semibold">
                    {{ $t('Subnets') }}
                    <span class="font-normal text-muted-foreground">{{
                        formatCount(subnets.length)
                    }}</span>
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{
                        $t(
                            'The subnets whose addresses lie in this block, whatever their site.',
                        )
                    }}
                </p>

                <p
                    v-if="subnets.length === 0"
                    class="rounded-lg border border-dashed p-4 text-muted-foreground"
                >
                    {{ $t('No subnet in this block yet.') }}
                </p>

                <div v-else class="table-frame">
                    <table class="table-dense" :aria-label="$t('Subnets')">
                        <thead>
                            <tr>
                                <th scope="col">{{ $t('Subnet') }}</th>
                                <th scope="col">{{ $t('Type') }}</th>
                                <th scope="col">{{ $t('Site') }}</th>
                                <th scope="col">{{ $t('VLAN') }}</th>
                                <th scope="col">{{ $t('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="subnet in subnets"
                                :key="subnet.id"
                                data-test="block-subnet-row"
                            >
                                <td>
                                    <Link
                                        :href="showSubnet(subnet.id)"
                                        class="link font-mono text-sm font-medium whitespace-nowrap"
                                        >{{ subnet.cidr }}</Link
                                    >
                                    <div
                                        v-if="subnet.name"
                                        class="text-note text-muted-foreground"
                                    >
                                        {{ subnet.name }}
                                    </div>
                                </td>
                                <td class="whitespace-nowrap">
                                    {{ subnet.kind_label }}
                                </td>
                                <td class="whitespace-nowrap">
                                    <template v-if="subnet.site">{{
                                        subnet.site.name
                                    }}</template>
                                    <span
                                        v-else
                                        class="text-muted-foreground"
                                        >{{ $t('No site') }}</span
                                    >
                                </td>
                                <td>
                                    <VlanTag
                                        v-if="subnet.vlan"
                                        :vid="subnet.vlan.vid"
                                        :name="subnet.vlan.name"
                                        :href="showVlan(subnet.vlan.id)"
                                        size="sm"
                                    />
                                    <span
                                        v-else
                                        class="text-muted-foreground"
                                        >{{ $t('None') }}</span
                                    >
                                </td>
                                <td>
                                    <StatusBadge
                                        :status="subnet.status"
                                        :label="subnet.status_label"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section
                class="flex min-w-0 flex-[1_1_18rem] flex-col gap-3"
                :aria-label="$t('Free space')"
                data-test="block-free-space"
            >
                <h2 class="text-lg font-semibold">{{ $t('Free space') }}</h2>
                <p class="text-sm text-muted-foreground">
                    {{
                        $t(
                            'The addresses of the block that belong to no subnet, as the largest prefixes that fit.',
                        )
                    }}
                </p>

                <p
                    v-if="space.free_prefixes.length === 0"
                    class="rounded-lg border border-dashed p-4 text-muted-foreground"
                >
                    {{ $t('No free space left in this block.') }}
                </p>

                <template v-else>
                    <!-- A fragmented block has many prefixes: the list
                         scrolls in its own frame, from the keyboard too. -->
                    <div
                        class="max-h-96 overflow-y-auto"
                        tabindex="0"
                        role="group"
                        :aria-label="$t('Free prefixes')"
                    >
                        <ul class="flex flex-col">
                            <li
                                v-for="prefix in space.free_prefixes"
                                :key="prefix.cidr"
                                class="flex items-baseline justify-between gap-3 border-b px-1 py-2"
                                data-test="free-prefix"
                            >
                                <span class="font-mono text-sm">{{
                                    prefix.cidr
                                }}</span>
                                <span class="text-sm text-muted-foreground">{{
                                    $tc(
                                        ':count address|:count addresses',
                                        prefix.size,
                                    )
                                }}</span>
                            </li>
                        </ul>
                    </div>
                    <p
                        v-if="
                            space.free_prefix_count > space.free_prefixes.length
                        "
                        class="text-sm text-muted-foreground"
                        data-test="free-prefix-limit"
                    >
                        {{
                            $t(
                                'The first :shown prefixes out of :count are listed.',
                                {
                                    shown: formatCount(
                                        space.free_prefixes.length,
                                    ),
                                    count: formatCount(space.free_prefix_count),
                                },
                            )
                        }}
                    </p>
                </template>
            </section>
        </div>

        <DeviationList :items="deviations" />
    </div>

    <ConfirmDialog
        :open="confirmingDeletion"
        :title="$t('Delete this block?')"
        :description="
            $t(
                ':cidr will be deleted. The subnets it holds are kept as they are.',
                { cidr: block.cidr },
            )
        "
        :confirm-label="$t('Delete')"
        destructive
        @confirm="confirmDeletion"
        @cancel="confirmingDeletion = false"
    />
</template>
