<!-- torsade/resources/js/components/search/SearchButton.vue -->
<script setup lang="ts">
import { Search } from '@lucide/vue';
import { openSearch } from '@/lib/search';

// The field of the header: a button drawn as a field, which opens the
// search window, with the shortcut that does the same. On a phone, the
// magnifying glass alone.
</script>

<template>
    <button
        type="button"
        class="inline-flex h-9.5 shrink-0 items-center gap-2.5 rounded-md border border-input bg-sunken px-2.5 text-muted-foreground transition-colors hover:text-foreground sm:w-56 sm:px-3"
        :aria-label="$t('Search the documentation')"
        aria-keyshortcuts="/ Control+K"
        data-test="search-button"
        @click="openSearch()"
    >
        <Search class="size-4 shrink-0" aria-hidden="true" />
        <span class="hidden sm:inline sm:flex-1 sm:text-left">{{
            $t('Search')
        }}</span>
        <kbd
            class="hidden rounded-xs border px-1.5 font-mono text-note sm:inline"
            aria-hidden="true"
            >/</kbd
        >
    </button>
</template>
