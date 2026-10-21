<!-- torsade/resources/js/components/search/SearchField.vue -->
<script setup lang="ts">
import { Search } from '@lucide/vue';
import { openSearch } from '@/lib/search';

// The search field of a page, on the home page and on the page of results:
// what is typed in it opens the search window, where the typing goes on.
// The window does the searching; this field only starts it.
const start = (event: Event): void => {
    const field = event.target as HTMLInputElement;
    const typed = field.value;

    field.value = '';
    openSearch(typed);
};
</script>

<template>
    <div class="relative">
        <Search
            class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
        />
        <input
            type="search"
            autocomplete="off"
            spellcheck="false"
            class="h-11.5 w-full min-w-0 rounded-md border border-input bg-sunken pr-3.5 pl-11 text-base placeholder:text-subtle-foreground [&::-webkit-search-cancel-button]:hidden"
            :placeholder="$t('An address, a VLAN, a device, a jack…')"
            :aria-label="$t('Search the documentation')"
            aria-haspopup="dialog"
            data-test="search-field"
            @input="start"
            @keydown.enter.prevent="start"
        />
    </div>
</template>
