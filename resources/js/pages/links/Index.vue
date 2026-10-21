<!-- torsade/resources/js/pages/links/Index.vue -->
<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import LinksTable from '@/components/network/LinksTable.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { trans } from '@/lib/i18n';
import { formatCount } from '@/lib/numbers';
import { index as devicesIndex } from '@/routes/devices';
import { index } from '@/routes/links';
import type { LinkFilters, LinkLine, Option, SiteOption } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: trans('Links'),
                href: index(),
            },
        ],
    },
});

// Every link between two devices, whatever their sites: its two ends, its
// medium, its status and where its ends do not agree. A link is declared
// from the page of a device, on the line of one of its ports.
const props = defineProps<{
    // The links the filters keep, the aggregated ones as one line each.
    links: LinkLine[];
    // How many links there are, whatever the filters.
    total: number;
    filters: LinkFilters;
    sites: SiteOption[];
    statuses: Option[];
    media: Option[];
}>();

const page = usePage();

// Only decides which buttons are shown: the server checks every action.
const canEdit = computed<boolean>(() => page.props.auth.can.editNetwork);

const filters = reactive<{ site: string; status: string; deviations: boolean }>(
    {
        site: props.filters.site,
        status: props.filters.status,
        deviations: props.filters.deviations === '1',
    },
);

// Filtering is a plain GET request: the address of the page keeps the
// filters, so that a filtered list can be bookmarked or shared.
const applyFilters = (): void => {
    const query: Record<string, string> = {};

    if (filters.site !== '') {
        query.site = filters.site;
    }

    if (filters.status !== '') {
        query.status = filters.status;
    }

    if (filters.deviations) {
        query.deviations = '1';
    }

    router.get(index().url, query, { preserveState: true, replace: true });
};

// How many links the filters keep.
const shown = computed<number>(() =>
    props.links.reduce((count, line) => count + line.links.length, 0),
);
</script>

<template>
    <Head :title="$t('Links')" />

    <div class="flex flex-col gap-7">
        <Heading
            :title="$t('Links')"
            :description="
                $t(
                    'What is plugged into what: each link between a port of a device and a port of another one, on the same site or between two sites, and where its two ends do not agree.',
                )
            "
        />

        <!-- First use: a link joins two ports, declared on the page of
             their devices. -->
        <div
            v-if="total === 0"
            class="flex flex-col items-center gap-4 rounded-lg border border-dashed p-8 text-center"
            data-test="no-link-notice"
        >
            <p class="max-w-prose text-muted-foreground">
                {{
                    $t(
                        'No link yet. A link is declared from the page of a device, on the line of one of its ports, with "Link…".',
                    )
                }}
            </p>
            <Button as-child variant="outline">
                <Link :href="devicesIndex()">{{
                    $t('Go to the devices')
                }}</Link>
            </Button>
        </div>

        <template v-else>
            <form
                class="flex flex-wrap items-end gap-x-4 gap-y-3"
                data-test="link-filters"
                @submit.prevent="applyFilters"
            >
                <div class="grid w-full gap-2 sm:w-56">
                    <Label for="filter-site">{{ $t('Site') }}</Label>
                    <NativeSelect
                        id="filter-site"
                        v-model="filters.site"
                        name="site"
                        class="h-9.5"
                        aria-describedby="filter-site-help"
                        @change="applyFilters"
                    >
                        <option value="">{{ $t('All sites') }}</option>
                        <option
                            v-for="site in sites"
                            :key="site.id"
                            :value="String(site.id)"
                        >
                            {{ site.name }}
                        </option>
                    </NativeSelect>
                </div>

                <div class="grid w-full gap-2 sm:w-48">
                    <Label for="filter-status">{{ $t('Status') }}</Label>
                    <NativeSelect
                        id="filter-status"
                        v-model="filters.status"
                        name="status"
                        class="h-9.5"
                        @change="applyFilters"
                    >
                        <option value="">{{ $t('All statuses') }}</option>
                        <option
                            v-for="status in statuses"
                            :key="status.value"
                            :value="status.value"
                        >
                            {{ status.label }}
                        </option>
                    </NativeSelect>
                </div>

                <label class="flex h-9.5 items-center gap-2.5">
                    <input
                        v-model="filters.deviations"
                        type="checkbox"
                        name="deviations"
                        class="size-4.5 shrink-0 rounded-xs accent-primary"
                        data-test="filter-deviations"
                        @change="applyFilters"
                    />
                    <span>{{ $t('With a deviation only') }}</span>
                </label>

                <p
                    id="filter-site-help"
                    class="w-full text-sm text-muted-foreground"
                >
                    {{
                        $t(
                            'A site keeps the links one of whose ends is on it, the links to another site included.',
                        )
                    }}
                </p>
            </form>

            <p
                class="text-sm text-muted-foreground"
                role="status"
                data-test="links-count"
            >
                {{
                    shown === total
                        ? $tc(':count link|:count links', total)
                        : $tc(
                              ':shown link out of :total|:shown links out of :total',
                              shown,
                              {
                                  shown: formatCount(shown),
                                  total: formatCount(total),
                              },
                          )
                }}
            </p>

            <p
                v-if="links.length === 0"
                class="rounded-lg border border-dashed p-4 text-muted-foreground"
            >
                {{ $t('No link matches these filters.') }}
            </p>

            <LinksTable
                v-else
                :lines="links"
                :media="media"
                :statuses="statuses"
                :can-edit="canEdit"
            />
        </template>
    </div>
</template>
