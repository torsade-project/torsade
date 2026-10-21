<!-- torsade/resources/js/components/search/SearchDialog.vue -->
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Search, X } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import SearchResult from '@/components/search/SearchResult.vue';
import UndocumentedAddress from '@/components/search/UndocumentedAddress.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { trans, transChoice } from '@/lib/i18n';
import { formatCount } from '@/lib/numbers';
import {
    asksForSearch,
    isWorthSearching,
    openSearch,
    SearchError,
    searchFor,
    searchOpen,
    searchStart,
} from '@/lib/search';
import { search as resultsPage } from '@/routes';
import type { SearchItem, SearchResults } from '@/types';

// The search window of the whole application, opened from the field of
// the header, by "/" or Ctrl+K, or from the field of the home page. The
// results come as the text is typed, once the typing pauses; a search
// still under way is given up when a newer one starts. A combobox and its
// list of options, in groups, for assistive technologies.

// How long the typing pauses before the search is asked for.
const PAUSE = 250;

const text = ref<string>('');
const results = ref<SearchResults | null>(null);
const failure = ref<string | null>(null);
const searching = ref<boolean>(false);
const active = ref<number>(-1);
const field = ref<HTMLInputElement | null>(null);

// The text the results or the failure shown answer, once trimmed.
const answered = ref<string | null>(null);

let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | null = null;

// The results one after the other, as the arrows go through them.
const items = computed<SearchItem[]>(
    () => results.value?.groups.flatMap((group) => group.items) ?? [],
);

const optionId = (item: SearchItem): string => `search-option-${item.key}`;

const activeItem = computed<SearchItem | null>(
    () => items.value[active.value] ?? null,
);

const tooShort = computed<boolean>(
    () => text.value.trim() !== '' && !isWorthSearching(text.value),
);

// What a screen reader is told once the results are there.
const announcement = computed<string>(() => {
    if (failure.value !== null) {
        return failure.value;
    }

    if (results.value === null) {
        return '';
    }

    return transChoice(':count result|:count results', results.value.total);
});

const allResults = computed(() =>
    resultsPage({ query: { q: text.value.trim() } }),
);

const stop = (): void => {
    clearTimeout(timer);
    controller?.abort();
    controller = null;
    searching.value = false;
};

const run = async (typed: string): Promise<void> => {
    controller?.abort();
    controller = null;

    if (!isWorthSearching(typed)) {
        results.value = null;
        failure.value = null;
        searching.value = false;
        active.value = -1;
        answered.value = typed.trim();

        return;
    }

    const current = new AbortController();
    controller = current;
    searching.value = true;

    try {
        const answer = await searchFor(typed, current.signal);

        results.value = answer;
        failure.value = null;
        // An answer to a text since typed over is shown, but none of its
        // results is chosen: Enter goes on opening the page of results of
        // what is in the field.
        active.value =
            answer.groups.length > 0 && text.value.trim() === typed.trim()
                ? 0
                : -1;
        answered.value = typed.trim();
    } catch (error) {
        // Given up for a newer search: that one answers.
        if (error instanceof DOMException && error.name === 'AbortError') {
            return;
        }

        results.value = null;
        active.value = -1;
        answered.value = typed.trim();
        failure.value =
            error instanceof SearchError
                ? error.message
                : trans('The search did not succeed. Try again in a moment.');
    } finally {
        if (controller === current) {
            controller = null;
            searching.value = false;
        }
    }
};

// The text changed: it is searched once the typing pauses. Until then,
// the results shown are those of an earlier text, and none of them is
// active: Enter opens the page of results of what is in the field.
const onInput = (): void => {
    clearTimeout(timer);
    active.value = -1;
    timer = setTimeout(() => void run(text.value), PAUSE);
};

// Closed to follow a result: the focus is not given back to the field it
// was opened from, which would scroll the next page back to its top.
let leaving = false;

const leave = (): void => {
    leaving = true;
    searchOpen.value = false;
};

const onCloseAutoFocus = (event: Event): void => {
    if (leaving) {
        event.preventDefault();
    }

    leaving = false;
};

const open = (item: SearchItem): void => {
    leave();
    router.visit(item.url);
};

const openAll = (): void => {
    if (text.value.trim() === '') {
        return;
    }

    leave();
    router.visit(allResults.value.url);
};

const onKeydown = (event: KeyboardEvent): void => {
    const count = items.value.length;

    if (event.key === 'ArrowDown' && count > 0) {
        event.preventDefault();
        active.value = (active.value + 1) % count;
    } else if (event.key === 'ArrowUp' && count > 0) {
        event.preventDefault();
        active.value = (active.value - 1 + count) % count;
    } else if (event.key === 'Enter') {
        event.preventDefault();

        if (activeItem.value !== null) {
            open(activeItem.value);
        } else {
            openAll();
        }
    } else if (event.key === 'Escape') {
        // A field of search is emptied by Escape before the window closes:
        // the window closes on its own, the text kept, as with "Close".
        event.preventDefault();
        searchOpen.value = false;
    }
};

// The option reached by the arrows stays in sight.
watch(active, async () => {
    await nextTick();

    if (activeItem.value !== null) {
        document
            .getElementById(optionId(activeItem.value))
            ?.scrollIntoView({ block: 'nearest' });
    }
});

// Opened from a field where something was typed: that text, searched
// once the typing pauses, as if it had been typed here. Opened again
// otherwise: the last search, ready to be typed over, done again if the
// window was closed before it answered.
watch(searchOpen, async (isOpen) => {
    if (!isOpen) {
        stop();

        return;
    }

    const start = searchStart.value;
    searchStart.value = null;

    if (start !== null) {
        text.value = start;
        onInput();
    } else if (answered.value !== text.value.trim()) {
        void run(text.value);
    }

    await nextTick();

    if (start === null) {
        field.value?.select();
    } else {
        field.value?.setSelectionRange(start.length, start.length);
    }
});

const onShortcut = (event: KeyboardEvent): void => {
    if (!searchOpen.value && asksForSearch(event)) {
        event.preventDefault();
        openSearch();
    }
};

onMounted(() => window.addEventListener('keydown', onShortcut));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onShortcut);
    stop();
});
</script>

<template>
    <Dialog v-model:open="searchOpen">
        <DialogContent
            :show-close-button="false"
            class="top-0 left-0 flex h-dvh max-h-dvh w-full max-w-none translate-x-0 translate-y-0 flex-col gap-0 overflow-hidden rounded-none p-0 sm:top-[10vh] sm:left-1/2 sm:h-auto sm:max-h-[80vh] sm:max-w-160 sm:-translate-x-1/2 sm:rounded-xl"
            data-test="search-dialog"
            @close-auto-focus="onCloseAutoFocus"
        >
            <DialogTitle class="sr-only">{{
                $t('Search the documentation')
            }}</DialogTitle>
            <DialogDescription class="sr-only">{{
                $t(
                    'An IP address, a prefix, a VLAN number, a MAC address, a device followed by one of its ports, or a name.',
                )
            }}</DialogDescription>

            <div class="flex shrink-0 items-center gap-3 border-b px-4">
                <Search
                    class="size-5 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <input
                    ref="field"
                    v-model="text"
                    type="search"
                    role="combobox"
                    autocomplete="off"
                    spellcheck="false"
                    class="h-11.5 min-w-0 flex-1 bg-transparent text-base outline-none placeholder:text-subtle-foreground [&::-webkit-search-cancel-button]:hidden"
                    :placeholder="$t('Search')"
                    :aria-label="$t('Search the documentation')"
                    aria-autocomplete="list"
                    aria-controls="search-listbox"
                    :aria-expanded="items.length > 0"
                    :aria-activedescendant="
                        activeItem ? optionId(activeItem) : undefined
                    "
                    :aria-invalid="failure !== null ? true : undefined"
                    aria-describedby="search-status"
                    data-test="search-input"
                    @input="onInput"
                    @keydown="onKeydown"
                />
                <Spinner
                    v-if="searching"
                    class="size-4 shrink-0 text-muted-foreground"
                />
                <button
                    type="button"
                    class="-mr-1.5 rounded-md p-1.5 text-muted-foreground hover:text-foreground"
                    data-test="close-search"
                    @click="searchOpen = false"
                >
                    <X class="size-4" aria-hidden="true" />
                    <span class="sr-only">{{ $t('Close') }}</span>
                </button>
            </div>

            <!-- The number of results, read out once they are there. -->
            <p
                id="search-status"
                class="sr-only"
                role="status"
                aria-live="polite"
            >
                {{ announcement }}
            </p>

            <div
                class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto bg-background py-3"
            >
                <p
                    v-if="failure"
                    class="mx-4 rounded-md bg-error-surface px-3 py-2 text-error"
                    data-test="search-error"
                >
                    {{ failure }}
                </p>

                <p
                    v-else-if="text.trim() === ''"
                    class="px-4 text-muted-foreground"
                >
                    {{
                        $t(
                            'An IP address, a prefix, a VLAN number, a MAC address, a device followed by one of its ports, or a name.',
                        )
                    }}
                </p>

                <p v-else-if="tooShort" class="px-4 text-muted-foreground">
                    {{ $t('Type two characters, or a number.') }}
                </p>

                <template v-if="results && !failure">
                    <p
                        v-if="results.notice"
                        class="px-4 text-note text-muted-foreground"
                        data-test="search-notice"
                    >
                        {{ results.notice }}
                    </p>

                    <UndocumentedAddress
                        v-if="results.undocumented"
                        class="mx-4"
                        :undocumented="results.undocumented"
                        @navigate="leave"
                    />

                    <p
                        v-if="results.total === 0 && !results.undocumented"
                        class="px-4 text-muted-foreground"
                        data-test="search-nothing"
                    >
                        {{
                            $t('No result for “:text”.', {
                                text: results.query,
                            })
                        }}
                    </p>
                </template>

                <ul
                    id="search-listbox"
                    role="listbox"
                    :aria-label="$t('Results')"
                    class="flex flex-col gap-3"
                >
                    <template v-if="results && !failure">
                        <li
                            v-for="group in results.groups"
                            :key="group.key"
                            role="presentation"
                        >
                            <ul
                                role="group"
                                :aria-label="
                                    $tc(
                                        ':label: :count result|:label: :count results',
                                        group.total,
                                        { label: group.label },
                                    )
                                "
                                :data-test="`search-group-${group.key}`"
                            >
                                <li
                                    role="presentation"
                                    class="flex items-baseline gap-2 px-4 pb-1 text-note text-muted-foreground"
                                    aria-hidden="true"
                                >
                                    <span>{{ group.label }}</span>
                                    <span class="tabular-nums">{{
                                        formatCount(group.total)
                                    }}</span>
                                </li>
                                <li
                                    v-for="item in group.items"
                                    :id="optionId(item)"
                                    :key="item.key"
                                    role="option"
                                    :aria-selected="
                                        activeItem?.key === item.key
                                    "
                                    class="mx-2 cursor-pointer rounded-md px-2 py-2"
                                    :class="{
                                        'bg-accent ring-2 ring-ring':
                                            activeItem?.key === item.key,
                                    }"
                                    data-test="search-option"
                                    @mousemove="active = items.indexOf(item)"
                                    @click="open(item)"
                                >
                                    <SearchResult
                                        :item="item"
                                        :highlighted="
                                            activeItem?.key === item.key
                                        "
                                    />
                                </li>
                            </ul>
                        </li>
                    </template>
                </ul>
            </div>

            <div
                class="flex shrink-0 flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t px-4 py-2.5 text-note text-muted-foreground"
            >
                <p
                    class="hidden flex-wrap items-center gap-x-3 gap-y-1 sm:flex"
                >
                    <span
                        ><kbd class="font-mono">↑</kbd>
                        <kbd class="font-mono">↓</kbd>
                        {{ $t('to move') }}</span
                    >
                    <span
                        ><kbd class="font-mono">{{ $t('Enter') }}</kbd>
                        {{ $t('to open') }}</span
                    >
                    <span
                        ><kbd class="font-mono">{{ $t('Esc') }}</kbd>
                        {{ $t('to close') }}</span
                    >
                </p>
                <Link
                    v-if="text.trim() !== ''"
                    :href="allResults"
                    class="link ml-auto"
                    data-test="all-results"
                    @click="leave"
                    >{{ $t('See all the results') }}</Link
                >
            </div>
        </DialogContent>
    </Dialog>
</template>
