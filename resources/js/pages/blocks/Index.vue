<!-- torsade/resources/js/pages/blocks/Index.vue -->
<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import OccupancyBar from '@/components/network/OccupancyBar.vue';
import StatusBadge from '@/components/network/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { trans } from '@/lib/i18n';
import { create, index, show } from '@/routes/address-blocks';
import type { AddressBlockNode } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: trans('Address blocks'),
                href: index(),
            },
        ],
    },
});

// The blocks, nested by their addresses: those of the organisation, each
// followed by what it holds, then the blocks of sites that no block of the
// organisation holds.
const props = defineProps<{
    blocks: AddressBlockNode[];
    outside: AddressBlockNode[];
}>();

const page = usePage();

// Only decides which buttons are shown: the server checks every action.
const canEdit = computed<boolean>(() => page.props.auth.can.editNetwork);

const groups = computed(() =>
    [
        {
            key: 'organisation',
            title: trans('Blocks of the organisation'),
            description: trans(
                'Each block of the organisation, with the blocks it holds under it.',
            ),
            blocks: props.blocks,
        },
        {
            key: 'outside',
            title: trans(
                'Blocks of sites outside the blocks of the organisation',
            ),
            description: trans(
                'Blocks of sites that no block of the organisation holds.',
            ),
            blocks: props.outside,
        },
    ].filter((group) => group.blocks.length > 0),
);
</script>

<template>
    <Head :title="$t('Address blocks')" />

    <div class="flex flex-col gap-7">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <Heading
                :title="$t('Address blocks')"
                :description="
                    $t(
                        'The addresses set aside for the organisation and for each site. A subnet that is not where the blocks say is shown as a deviation.',
                    )
                "
            />
            <Button v-if="canEdit" as-child data-test="create-block-button">
                <Link :href="create()">{{ $t('New block') }}</Link>
            </Button>
        </div>

        <p
            v-if="groups.length === 0"
            class="rounded-lg border border-dashed p-8 text-center text-muted-foreground"
        >
            {{ $t('No address block yet.') }}
        </p>

        <section
            v-for="group in groups"
            :key="group.key"
            class="flex flex-col gap-3"
            :aria-label="group.title"
            :data-test="`blocks-${group.key}`"
        >
            <Heading
                variant="small"
                :title="group.title"
                :description="group.description"
            />

            <div class="table-frame">
                <table class="table-dense" :aria-label="group.title">
                    <thead>
                        <tr>
                            <th scope="col">{{ $t('Block') }}</th>
                            <th scope="col">{{ $t('Site') }}</th>
                            <th scope="col">{{ $t('Occupied space') }}</th>
                            <th scope="col">{{ $t('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="block in group.blocks"
                            :key="block.id"
                            data-test="block-row"
                            :data-depth="block.depth"
                        >
                            <!-- A block is shifted to the right once for
                                 each block around it. -->
                            <td
                                :style="{
                                    paddingInlineStart: `${0.875 + block.depth * 1.5}rem`,
                                }"
                            >
                                <span v-if="block.depth > 0" class="sr-only">{{
                                    $t('Level :level', {
                                        level: block.depth + 1,
                                    })
                                }}</span>
                                <Link
                                    :href="show(block.id)"
                                    class="link font-mono text-sm font-medium whitespace-nowrap"
                                    >{{ block.cidr }}</Link
                                >
                                <div
                                    v-if="block.name"
                                    class="text-note text-muted-foreground"
                                >
                                    {{ block.name }}
                                </div>
                            </td>
                            <td class="whitespace-nowrap">
                                <template v-if="block.site">{{
                                    block.site.name
                                }}</template>
                                <span v-else class="text-muted-foreground">{{
                                    $t('Organisation')
                                }}</span>
                            </td>
                            <td>
                                <OccupancyBar
                                    :documented="block.occupied"
                                    :usable="block.size"
                                />
                            </td>
                            <td>
                                <StatusBadge
                                    :status="block.status"
                                    :label="block.status_label"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
