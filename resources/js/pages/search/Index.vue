<!-- torsade/resources/js/pages/search/Index.vue -->
<script setup lang="ts">
import { Head, Link, setLayoutProps, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import SearchField from '@/components/search/SearchField.vue';
import SearchResult from '@/components/search/SearchResult.vue';
import UndocumentedAddress from '@/components/search/UndocumentedAddress.vue';
import { Button } from '@/components/ui/button';
import { trans } from '@/lib/i18n';
import { formatCount } from '@/lib/numbers';
import { nextPageOf, SearchError } from '@/lib/search';
import { search } from '@/routes';
import type { SearchGroup, SearchResults } from '@/types';

// The page of results of a search, whose address can be shared: every
// group, a page of results each, and the next ones of a group on demand.
const props = defineProps<{
    results: SearchResults | null;
    perPage: number;
}>();

setLayoutProps({
    breadcrumbs: [{ title: trans('Search'), href: search() }],
});

const page = usePage();

// The groups as they grow with their next results.
const groups = ref<SearchGroup[]>([]);
// The page of each group read last, and the group whose next page is
// being read, if any.
const pages = ref<Record<string, number>>({});
const loading = ref<string | null>(null);
const failures = ref<Record<string, string>>({});

watch(
    () => props.results,
    (results) => {
        groups.value = results?.groups.map((group) => ({ ...group })) ?? [];
        pages.value = {};
        failures.value = {};
    },
    { immediate: true },
);

const showMore = async (group: SearchGroup): Promise<void> => {
    if (props.results === null) {
        return;
    }

    const next = (pages.value[group.key] ?? 1) + 1;
    loading.value = group.key;
    delete failures.value[group.key];

    try {
        const more = await nextPageOf(props.results.query, group.key, next);

        if (more !== null) {
            group.items = [...group.items, ...more.items];
            group.total = more.total;
        }

        pages.value[group.key] = next;
    } catch (error) {
        failures.value[group.key] =
            error instanceof SearchError
                ? error.message
                : trans('The search did not succeed. Try again in a moment.');
    } finally {
        loading.value = null;
    }
};
</script>

<template>
    <Head
        :title="
            results
                ? $t('Results for “:text”', { text: results.query })
                : $t('Search')
        "
    />

    <div class="flex flex-col gap-7">
        <Heading
            :title="
                results
                    ? $t('Results for “:text”', { text: results.query })
                    : $t('Search')
            "
            :description="
                results
                    ? $tc(':count result|:count results', results.total)
                    : $t(
                          'An IP address, a prefix, a VLAN number, a MAC address, a device followed by one of its ports, or a name.',
                      )
            "
        />

        <div class="max-w-160">
            <SearchField />
        </div>

        <p
            v-if="page.props.errors?.q"
            class="rounded-md bg-error-surface px-3 py-2 text-error"
            data-test="search-error"
        >
            {{ page.props.errors.q }}
        </p>

        <template v-if="results">
            <p
                v-if="results.notice"
                class="text-muted-foreground"
                data-test="search-notice"
            >
                {{ results.notice }}
            </p>

            <UndocumentedAddress
                v-if="results.undocumented"
                class="max-w-160"
                :undocumented="results.undocumented"
            />

            <p
                v-if="results.total === 0 && !results.undocumented"
                class="text-muted-foreground"
                data-test="search-nothing"
            >
                {{ $t('No result for “:text”.', { text: results.query }) }}
            </p>

            <section
                v-for="group in groups"
                :key="group.key"
                class="flex min-w-0 flex-col gap-3"
                :aria-labelledby="`results-${group.key}`"
                :data-test="`results-${group.key}`"
            >
                <h2
                    :id="`results-${group.key}`"
                    class="flex items-baseline gap-2.5 text-lg font-semibold"
                >
                    {{ group.label }}
                    <span
                        class="text-base font-normal text-muted-foreground tabular-nums"
                        >{{ formatCount(group.total) }}</span
                    >
                </h2>

                <ul class="flex min-w-0 flex-col divide-y border-y">
                    <li
                        v-for="item in group.items"
                        :key="item.key"
                        class="min-w-0"
                    >
                        <Link
                            :href="item.url"
                            class="group block rounded-md px-1 py-2.5"
                            data-test="search-result"
                        >
                            <span
                                class="block group-hover:[&_[data-test=search-label]]:underline"
                            >
                                <SearchResult :item="item" />
                            </span>
                        </Link>
                    </li>
                </ul>

                <p
                    v-if="failures[group.key]"
                    class="rounded-md bg-error-surface px-3 py-2 text-error"
                >
                    {{ failures[group.key] }}
                </p>

                <div v-if="group.items.length < group.total">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="loading === group.key"
                        :data-test="`show-more-${group.key}`"
                        @click="showMore(group)"
                    >
                        {{ $t('Show more') }}
                    </Button>
                </div>
            </section>
        </template>
    </div>
</template>
