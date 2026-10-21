<!-- torsade/resources/js/components/home/SearchModule.vue -->
<script setup lang="ts">
import HomePanel from '@/components/home/HomePanel.vue';
import SearchField from '@/components/search/SearchField.vue';

// The field of the search: what is typed in it opens the search window,
// the same as the field of the header.
</script>

<template>
    <HomePanel :title="$t('Search')" test-id="search">
        <SearchField />
    </HomePanel>
</template>
